<?php
defined('ABSPATH') || exit;

/**
 * Cliente JSON-RPC para Odoo 16+.
 * Soporta autenticación por sesión (usuario/contraseña).
 */
class OdooConnect_Client {

    private string $url;
    private string $db;
    private string $username;
    private string $password;
    private ?string $session_id = null;
    private ?int    $uid        = null;
    private int     $timeout    = 30;
    private int     $batch_timeout = 120;

    public function __construct(string $url, string $db, string $username, string $password) {
        $this->url      = rtrim($url, '/');
        $this->db       = $db;
        $this->username = $username;
        $this->password = $password;
    }

    // ── Autenticación ──────────────────────────────────────────
    public function authenticate(): bool {
        $result = $this->rpc('/web/session/authenticate', [
            'db'       => $this->db,
            'login'    => $this->username,
            'password' => $this->password,
        ]);

        if (isset($result['uid']) && $result['uid']) {
            $this->uid = (int) $result['uid'];
            return true;
        }
        return false;
    }

    public function is_authenticated(): bool {
        return $this->uid !== null;
    }

    // ── Llamada a modelo ──────────────────────────────────────
    public function call(string $model, string $method, array $args = [], array $kwargs = []): mixed {
        if (!$this->is_authenticated()) {
            $this->authenticate();
        }

        return $this->rpc('/web/dataset/call_kw', [
            'model'  => $model,
            'method' => $method,
            'args'   => $args,
            'kwargs' => array_merge(['context' => ['lang' => 'es_PE']], $kwargs),
        ]);
    }

    // ── Helpers de producto ───────────────────────────────────
    /** Devuelve todos los product.template activos disponibles en POS (sin imagen para batch rápido) */
    public function get_all_products(): array {
        $prev = $this->timeout;
        $this->timeout = $this->batch_timeout;
        $result = $this->call('product.template', 'search_read',
            [[['available_in_pos', '=', true], ['active', '=', true]]],
            [
                'fields' => [
                    'id', 'name', 'default_code', 'list_price',
                    'description_sale', 'pos_categ_ids',
                    'attribute_line_ids', 'write_date',
                ],
                'limit'  => 1000,
                'order'  => 'id asc',
            ]
        ) ?? [];
        $this->timeout = $prev;
        return $result;
    }

    /** Devuelve productos modificados desde $since (sin imagen para batch rápido) */
    public function get_products_since(string $since): array {
        $prev = $this->timeout;
        $this->timeout = $this->batch_timeout;
        $result = $this->call('product.template', 'search_read',
            [[
                ['available_in_pos', '=', true],
                ['active', '=', true],
                ['write_date', '>', $since],
            ]],
            [
                'fields' => [
                    'id', 'name', 'default_code', 'list_price',
                    'description_sale', 'pos_categ_ids',
                    'attribute_line_ids', 'write_date',
                ],
                'limit'  => 1000,
                'order'  => 'write_date asc',
            ]
        ) ?? [];
        $this->timeout = $prev;
        return $result;
    }

    /** Devuelve todos los IDs activos (para detección de borrados) */
    public function get_all_product_ids(): array {
        return $this->call('product.template', 'search',
            [[['available_in_pos', '=', true], ['active', '=', true]]]
        ) ?? [];
    }

    /** Devuelve un conjunto de productos por IDs incluyendo imagen (chunks pequeños) */
    public function get_products_by_ids(array $ids): array {
        if (empty($ids)) return [];
        $prev = $this->timeout;
        $this->timeout = $this->batch_timeout;
        $result = $this->call('product.template', 'search_read',
            [[['id', 'in', $ids]]],
            [
                'fields' => [
                    'id', 'name', 'default_code', 'list_price',
                    'description_sale', 'image_1920', 'pos_categ_ids',
                    'attribute_line_ids', 'write_date', 'active',
                ],
            ]
        ) ?? [];
        $this->timeout = $prev;
        return $result;
    }

    /** Devuelve un producto por ID */
    public function get_product(int $id): ?array {
        $result = $this->call('product.template', 'search_read',
            [[['id', '=', $id]]],
            [
                'fields' => [
                    'id', 'name', 'default_code', 'list_price',
                    'description_sale', 'image_1920', 'pos_categ_ids',
                    'attribute_line_ids', 'write_date', 'active',
                ],
                'limit' => 1,
            ]
        ) ?? [];
        return $result[0] ?? null;
    }

    /**
     * Devuelve los attribute lines de los productos dados.
     * Incluye valores y price_extra por variante.
     */
    public function get_attribute_lines(array $line_ids): array {
        if (empty($line_ids)) return [];

        $lines = $this->call('product.template.attribute.line', 'search_read',
            [[['id', 'in', $line_ids]]],
            ['fields' => ['id', 'product_tmpl_id', 'attribute_id', 'value_ids', 'product_template_value_ids']]
        ) ?? [];

        // Fetch price extras for each ptav
        $ptav_ids = [];
        foreach ($lines as $l) {
            $ptav_ids = array_merge($ptav_ids, $l['product_template_value_ids']);
        }
        $ptav_ids = array_unique($ptav_ids);

        $ptavs = [];
        if ($ptav_ids) {
            $raw = $this->call('product.template.attribute.value', 'search_read',
                [[['id', 'in', $ptav_ids]]],
                ['fields' => ['id', 'product_attribute_value_id', 'price_extra', 'attribute_line_id']]
            ) ?? [];
            foreach ($raw as $p) {
                $ptavs[$p['id']] = $p;
            }
        }

        // Attach ptav data to lines
        foreach ($lines as &$line) {
            $line['ptavs'] = array_filter(
                $ptavs,
                fn($p) => in_array($p['id'], $line['product_template_value_ids'])
            );
        }

        return $lines;
    }

    /** Devuelve nombres de categorías POS dados sus IDs */
    public function get_pos_categories(array $ids): array {
        if (empty($ids)) return [];
        // Odoo puede devolver [id, nombre] en lugar de enteros simples; normalizamos
        $ids = array_values(array_unique(array_filter(array_map(
            fn($v) => is_array($v) ? (int) $v[0] : (int) $v,
            $ids
        ))));
        if (empty($ids)) return [];
        $raw = $this->call('pos.category', 'search_read',
            [[['id', 'in', $ids]]],
            ['fields' => ['id', 'name']]
        ) ?? [];
        $out = [];
        foreach ($raw as $c) {
            $name = is_array($c['name']) ? ($c['name']['es_PE'] ?? $c['name']['en_US'] ?? '') : $c['name'];
            $out[$c['id']] = $name;
        }
        return $out;
    }

    // ── JSON-RPC interno ──────────────────────────────────────
    private function rpc(string $endpoint, array $params): mixed {
        $body = wp_json_encode([
            'jsonrpc' => '2.0',
            'method'  => 'call',
            'id'      => 1,
            'params'  => $params,
        ]);

        $args = [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $body,
            'timeout' => $this->timeout,
        ];

        if ($this->session_id) {
            $args['headers']['Cookie'] = 'session_id=' . $this->session_id;
        }

        $response = wp_remote_post($this->url . $endpoint, $args);

        if (is_wp_error($response)) {
            throw new RuntimeException('Odoo HTTP error: ' . $response->get_error_message());
        }

        // Capturar session_id de cookies
        foreach (wp_remote_retrieve_cookies($response) as $cookie) {
            if ($cookie->name === 'session_id') {
                $this->session_id = $cookie->value;
            }
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($data['error'])) {
            $msg = $data['error']['data']['message'] ?? $data['error']['message'] ?? 'Unknown error';
            throw new RuntimeException('Odoo RPC error: ' . $msg);
        }

        return $data['result'] ?? null;
    }
}
