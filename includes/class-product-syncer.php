<?php
defined('ABSPATH') || exit;

/**
 * Motor principal de sincronización Odoo → WooCommerce.
 *
 * Estrategia de matching (en orden):
 *  1. _odoo_id  (meta WC guardado por este plugin)
 *  2. SKU       (default_code de Odoo)
 *
 * Productos simples  → WC_Product_Simple
 * Productos variados → WC_Product_Variable + WC_Product_Variation
 */
class OdooConnect_ProductSyncer {

    private OdooConnect_Client $odoo;
    private array $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0,
                             'unpublished' => 0, 'errors' => 0];

    public function __construct(OdooConnect_Client $client) {
        $this->odoo = $client;
    }

    // ── Punto de entrada ───────────────────────────────────────
    public function run_full_sync(): array {
        $this->stats = array_fill_keys(array_keys($this->stats), 0);
        $products    = $this->odoo->get_all_products();
        $this->sync_products($products);
        $this->handle_deletions($products);
        update_option('odoo_connect_last_sync', gmdate('Y-m-d H:i:s'));
        return $this->stats;
    }

    public function run_incremental_sync(): array {
        $this->stats    = array_fill_keys(array_keys($this->stats), 0);
        $last_sync      = get_option('odoo_connect_last_sync', '');
        $products       = $last_sync
            ? $this->odoo->get_products_since($last_sync)
            : $this->odoo->get_all_products();
        $this->sync_products($products);
        update_option('odoo_connect_last_sync', gmdate('Y-m-d H:i:s'));
        return $this->stats;
    }

    /** Sincroniza un único producto por su Odoo ID (llamado desde webhook) */
    public function sync_one(int $odoo_id): array {
        $this->stats = array_fill_keys(array_keys($this->stats), 0);
        $product     = $this->odoo->get_product($odoo_id);
        if (!$product) {
            // Producto eliminado o inactivo — despublicar en WC
            $this->unpublish_by_odoo_id($odoo_id);
            return $this->stats;
        }
        if (!$product['active']) {
            $this->unpublish_by_odoo_id($odoo_id);
            return $this->stats;
        }
        $this->sync_products([$product]);
        return $this->stats;
    }

    // ── Sync loop ──────────────────────────────────────────────
    private function sync_products(array $products): void {
        if (empty($products)) return;

        // Batch-fetch attribute lines
        $all_line_ids = [];
        foreach ($products as $p) {
            $all_line_ids = array_merge($all_line_ids, $p['attribute_line_ids'] ?? []);
        }
        $attr_lines_by_tmpl = $this->group_attr_lines(
            $this->odoo->get_attribute_lines(array_unique($all_line_ids))
        );

        // Batch-fetch POS categories
        $all_cat_ids = [];
        foreach ($products as $p) {
            $all_cat_ids = array_merge($all_cat_ids, $p['pos_categ_ids'] ?? []);
        }
        $cat_names = $this->odoo->get_pos_categories(array_unique($all_cat_ids));

        foreach ($products as $p) {
            try {
                $lines = $attr_lines_by_tmpl[$p['id']] ?? [];
                $this->sync_single($p, $lines, $cat_names);
            } catch (Throwable $e) {
                $this->stats['errors']++;
                $this->log($p['id'], $p['default_code'] ?? '', $p['name'], 'sync', 'error', $e->getMessage());
            }
        }
    }

    private function sync_single(array $odoo, array $attr_lines, array $cat_names): void {
        $odoo_id = (int) $odoo['id'];
        $sku     = $odoo['default_code'] ?? '';
        $name    = is_array($odoo['name']) ? ($odoo['name']['es_PE'] ?? $odoo['name']['en_US'] ?? '') : ($odoo['name'] ?? '');

        // Detectar si tiene variantes con precio diferente
        $has_price_variants = $this->has_price_variants($attr_lines);

        // Encontrar o crear WC product
        $wc_id = $this->find_wc_product($odoo_id, $sku);

        if ($has_price_variants) {
            $action = $this->sync_variable($odoo, $attr_lines, $cat_names, $wc_id);
        } else {
            $action = $this->sync_simple($odoo, $attr_lines, $cat_names, $wc_id);
        }

        $this->stats[$action]++;
        $this->log($odoo_id, $sku, $name, $action, 'ok');
    }

    // ── Producto simple ────────────────────────────────────────
    private function sync_simple(array $odoo, array $attr_lines, array $cat_names, ?int $wc_id): string {
        $product = $wc_id ? wc_get_product($wc_id) : null;
        $action  = 'updated';

        if (!$product || $product->is_type('variable')) {
            // Si era variable, lo reemplazamos
            if ($product) {
                $product->delete(true);
            }
            $product = new WC_Product_Simple();
            $action  = 'created';
        }

        $this->apply_common_fields($product, $odoo, $cat_names);
        $product->set_regular_price((string) $odoo['list_price']);

        // SKU: usar default_code o generar desde odoo_id
        $sku = $odoo['default_code'] ?: '';
        if ($sku) {
            // Verificar que el SKU no lo use otro producto
            $existing_sku_id = wc_get_product_id_by_sku($sku);
            if (!$existing_sku_id || $existing_sku_id === $product->get_id()) {
                $product->set_sku($sku);
            }
        }

        // Atributos informativos (sin precio extra) como atributos de WC sin variación
        if (!empty($attr_lines)) {
            $wc_attrs = $this->build_wc_attributes_flat($attr_lines);
            $product->set_attributes($wc_attrs);
        }

        $product->update_meta_data('_odoo_id',        $odoo['id']);
        $product->update_meta_data('_odoo_write_date', $odoo['write_date'] ?? '');
        $product->save();

        $this->maybe_update_image($product, $odoo['image_1920'] ?? null);

        return $action;
    }

    // ── Producto variable ──────────────────────────────────────
    private function sync_variable(array $odoo, array $attr_lines, array $cat_names, ?int $wc_id): string {
        $product = $wc_id ? wc_get_product($wc_id) : null;
        $action  = 'updated';

        if (!$product || !$product->is_type('variable')) {
            if ($product) {
                $product->delete(true);
            }
            $product = new WC_Product_Variable();
            $action  = 'created';
        }

        $this->apply_common_fields($product, $odoo, $cat_names);

        // Identificar la línea con price_extra (típicamente Tamaño)
        $price_line = $this->get_price_attr_line($attr_lines);
        $other_lines = array_filter($attr_lines, fn($l) => $l['id'] !== $price_line['id']);

        // Construir atributos WC
        $wc_attrs = [];

        // Atributo con variaciones de precio
        $attr_name = is_array($price_line['attribute_id'])
            ? $price_line['attribute_id'][1]
            : 'Variante';
        $wc_attr = $this->get_or_create_wc_attribute($attr_name);
        $values  = array_map(fn($v) => is_array($v) ? $v[1] : $v, $price_line['value_names'] ?? []);

        $pa = new WC_Product_Attribute();
        $pa->set_id($wc_attr['id']);
        $pa->set_name($wc_attr['slug']);
        $pa->set_options($values);
        $pa->set_position(0);
        $pa->set_visible(true);
        $pa->set_variation(true);
        $wc_attrs[] = $pa;

        // Otros atributos (informativos, sin variación)
        $pos = 1;
        foreach ($other_lines as $line) {
            $other_attrs = $this->build_wc_attributes_flat([$line], $pos++);
            $wc_attrs    = array_merge($wc_attrs, $other_attrs);
        }

        $product->set_attributes($wc_attrs);
        $product->update_meta_data('_odoo_id',        $odoo['id']);
        $product->update_meta_data('_odoo_write_date', $odoo['write_date'] ?? '');
        $product->save();

        // Sincronizar variaciones
        $this->sync_variations($product, $price_line, $odoo['list_price'], $wc_attr['slug']);

        // Establecer precio mínimo en el padre
        WC_Product_Variable::sync($product->get_id());
        $product->save();

        $this->maybe_update_image($product, $odoo['image_1920'] ?? null);

        return $action;
    }

    private function sync_variations(WC_Product_Variable $parent, array $price_line, float $base_price, string $attr_slug): void {
        $existing_variations = $parent->get_children();
        $existing_by_attr    = [];

        foreach ($existing_variations as $var_id) {
            $var  = wc_get_product($var_id);
            $attr = $var->get_attribute($attr_slug);
            if ($attr) {
                $existing_by_attr[strtolower($attr)] = $var;
            }
        }

        $ptavs = $price_line['ptavs'] ?? [];
        foreach ($ptavs as $ptav) {
            $val_name   = is_array($ptav['product_attribute_value_id'])
                ? $ptav['product_attribute_value_id'][1]
                : '';
            $price_extra = (float) ($ptav['price_extra'] ?? 0);
            $price       = round($base_price + $price_extra, 2);

            $existing = $existing_by_attr[strtolower($val_name)] ?? null;

            if ($existing) {
                $var = $existing;
            } else {
                $var = new WC_Product_Variation();
                $var->set_parent_id($parent->get_id());
            }

            $var->set_attributes([$attr_slug => $val_name]);
            $var->set_regular_price((string) $price);
            $var->set_status('publish');
            $var->save();
        }

        // Eliminar variaciones que ya no existen en Odoo
        $valid_names = array_map(fn($ptav) =>
            strtolower(is_array($ptav['product_attribute_value_id'])
                ? $ptav['product_attribute_value_id'][1] : ''),
            $ptavs
        );
        foreach ($existing_by_attr as $name => $var) {
            if (!in_array($name, $valid_names, true)) {
                $var->delete(true);
            }
        }
    }

    // ── Campos comunes ─────────────────────────────────────────
    private function apply_common_fields(WC_Product $product, array $odoo, array $cat_names): void {
        $name = is_array($odoo['name'])
            ? ($odoo['name']['es_PE'] ?? $odoo['name']['en_US'] ?? '')
            : ($odoo['name'] ?? '');

        $product->set_name($name);
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');

        $desc = $odoo['description_sale'] ?? '';
        if (is_array($desc)) {
            $desc = $desc['es_PE'] ?? $desc['en_US'] ?? '';
        }
        if ($desc) {
            $product->set_short_description(nl2br(esc_html($desc)));
        }

        // Categorías
        $wc_cat_ids = [];
        foreach ($odoo['pos_categ_ids'] ?? [] as $cat_id) {
            $cat_name = $cat_names[$cat_id] ?? '';
            if ($cat_name) {
                $wc_cat_ids[] = $this->get_or_create_wc_category($cat_name);
            }
        }
        if ($wc_cat_ids) {
            $product->set_category_ids($wc_cat_ids);
        }
    }

    // ── Imagen ────────────────────────────────────────────────
    private function maybe_update_image(WC_Product $product, ?string $b64): void {
        if (!$b64) return;

        $hash = md5($b64);
        if (get_post_meta($product->get_id(), '_odoo_image_hash', true) === $hash) {
            return; // sin cambios
        }

        $img_id = $this->upload_base64_image($b64, $product->get_name());
        if ($img_id) {
            $product->set_image_id($img_id);
            $product->save();
            update_post_meta($product->get_id(), '_odoo_image_hash', $hash);
        }
    }

    private function upload_base64_image(string $b64, string $name): ?int {
        $data = base64_decode($b64, true);
        if (!$data) return null;

        // Detectar tipo
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->buffer($data);
        $ext   = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg',
        };

        $filename = sanitize_file_name($name) . '.' . $ext;
        $upload   = wp_upload_bits($filename, null, $data);
        if ($upload['error']) return null;

        $attachment = [
            'post_mime_type' => $mime,
            'post_title'     => $name,
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $att_id = wp_insert_attachment($attachment, $upload['file']);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($att_id, $upload['file']);
        wp_update_attachment_metadata($att_id, $metadata);

        return $att_id ?: null;
    }

    // ── Manejo de borrados ────────────────────────────────────
    private function handle_deletions(array $synced_products): void {
        $odoo_ids = array_column($synced_products, 'id');

        global $wpdb;
        $wc_products = $wpdb->get_results(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_odoo_id' AND meta_value != ''"
        );

        foreach ($wc_products as $row) {
            $odoo_id = (int) get_post_meta($row->post_id, '_odoo_id', true);
            if (!in_array($odoo_id, $odoo_ids, true)) {
                $this->unpublish_wc_product($row->post_id, $odoo_id);
            }
        }
    }

    private function unpublish_by_odoo_id(int $odoo_id): void {
        global $wpdb;
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_odoo_id' AND meta_value=%d LIMIT 1",
            $odoo_id
        ));
        if ($post_id) {
            $this->unpublish_wc_product((int) $post_id, $odoo_id);
        }
    }

    private function unpublish_wc_product(int $post_id, int $odoo_id): void {
        $product = wc_get_product($post_id);
        if ($product && $product->get_status() === 'publish') {
            $product->set_status('draft');
            $product->save();
            $this->stats['unpublished']++;
            $this->log($odoo_id, '', $product->get_name(), 'unpublish', 'ok');
        }
    }

    // ── Helpers de atributos ──────────────────────────────────
    private function group_attr_lines(array $lines): array {
        $by_tmpl = [];
        foreach ($lines as $line) {
            $tmpl_id = is_array($line['product_tmpl_id']) ? $line['product_tmpl_id'][0] : $line['product_tmpl_id'];
            $by_tmpl[$tmpl_id][] = $line;
        }
        return $by_tmpl;
    }

    private function has_price_variants(array $attr_lines): bool {
        foreach ($attr_lines as $line) {
            foreach ($line['ptavs'] ?? [] as $ptav) {
                if ((float) ($ptav['price_extra'] ?? 0) != 0) {
                    return true;
                }
            }
        }
        return false;
    }

    private function get_price_attr_line(array $attr_lines): array {
        foreach ($attr_lines as $line) {
            foreach ($line['ptavs'] ?? [] as $ptav) {
                if ((float) ($ptav['price_extra'] ?? 0) != 0) {
                    // Enriquecer con value_names
                    $line['value_names'] = $this->extract_value_names($line);
                    return $line;
                }
            }
        }
        // fallback: primera línea
        $line = $attr_lines[0];
        $line['value_names'] = $this->extract_value_names($line);
        return $line;
    }

    private function extract_value_names(array $line): array {
        $names = [];
        foreach ($line['ptavs'] ?? [] as $ptav) {
            $names[] = $ptav['product_attribute_value_id'];
        }
        return $names;
    }

    private function build_wc_attributes_flat(array $attr_lines, int $position = 0): array {
        $wc_attrs = [];
        foreach ($attr_lines as $line) {
            $attr_name = is_array($line['attribute_id']) ? $line['attribute_id'][1] : 'Atributo';
            $values    = [];
            foreach ($line['ptavs'] ?? [] as $ptav) {
                $v = $ptav['product_attribute_value_id'];
                $values[] = is_array($v) ? $v[1] : $v;
            }
            if (empty($values)) continue;

            $wc_attr = $this->get_or_create_wc_attribute($attr_name);
            $pa = new WC_Product_Attribute();
            $pa->set_id($wc_attr['id']);
            $pa->set_name($wc_attr['slug']);
            $pa->set_options($values);
            $pa->set_position($position++);
            $pa->set_visible(true);
            $pa->set_variation(false);
            $wc_attrs[] = $pa;
        }
        return $wc_attrs;
    }

    private function get_or_create_wc_attribute(string $name): array {
        $slug = wc_sanitize_taxonomy_name($name);
        $tax  = 'pa_' . $slug;

        if (!taxonomy_exists($tax)) {
            $attr_id = wc_create_attribute([
                'name'         => $name,
                'slug'         => $slug,
                'type'         => 'select',
                'order_by'     => 'menu_order',
                'has_archives' => false,
            ]);
            if (!is_wp_error($attr_id)) {
                register_taxonomy($tax, 'product');
            }
        } else {
            $attr    = wc_get_attribute(wc_attribute_taxonomy_id_by_name($slug));
            $attr_id = $attr ? $attr->id : 0;
        }

        return ['id' => (int) ($attr_id ?: 0), 'slug' => $tax];
    }

    private function get_or_create_wc_category(string $name): int {
        $term = get_term_by('name', $name, 'product_cat');
        if ($term) return $term->term_id;

        $result = wp_insert_term($name, 'product_cat');
        return is_wp_error($result) ? 0 : (int) $result['term_id'];
    }

    // ── Buscar producto WC ────────────────────────────────────
    private function find_wc_product(int $odoo_id, string $sku): ?int {
        global $wpdb;

        // 1. Por _odoo_id meta
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key='_odoo_id' AND meta_value=%d LIMIT 1",
            $odoo_id
        ));
        if ($id) return (int) $id;

        // 2. Por SKU
        if ($sku) {
            $id = wc_get_product_id_by_sku($sku);
            if ($id) return (int) $id;
        }

        return null;
    }

    // ── Log ───────────────────────────────────────────────────
    private function log(int $odoo_id, string $sku, string $name, string $action, string $status, string $msg = ''): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . OdooConnect_Install::TABLE_LOGS,
            [
                'odoo_id'      => $odoo_id,
                'sku'          => $sku,
                'product_name' => $name,
                'action'       => $action,
                'status'       => $status,
                'message'      => $msg,
                'synced_at'    => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    public function get_stats(): array {
        return $this->stats;
    }
}
