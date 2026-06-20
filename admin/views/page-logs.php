<?php
defined('ABSPATH') || exit;
global $wpdb;
$table  = $wpdb->prefix . OdooConnect_Install::TABLE_LOGS;
$page   = max(1, (int) ($_GET['paged'] ?? 1));
$per    = 50;
$offset = ($page - 1) * $per;
$total  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
$rows   = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$table} ORDER BY synced_at DESC LIMIT %d OFFSET %d",
    $per, $offset
));
?>
<div class="wrap odoo-connect-wrap">
    <h1 class="oc-title">
        <span class="dashicons dashicons-list-view"></span>
        Historial de Sincronización
    </h1>

    <div class="oc-card">
        <div class="oc-logs-toolbar">
            <span><?= number_format($total) ?> registros</span>
            <button type="button" id="oc-clear-logs" class="button button-secondary">
                Limpiar historial
            </button>
        </div>

        <table class="wp-list-table widefat fixed striped oc-logs-table">
            <thead>
                <tr>
                    <th width="140">Fecha (UTC)</th>
                    <th width="80">Odoo ID</th>
                    <th width="100">SKU</th>
                    <th>Producto</th>
                    <th width="90">Acción</th>
                    <th width="80">Estado</th>
                    <th>Mensaje</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                <tr><td colspan="7" class="oc-empty">Sin registros aún. Ejecuta una sincronización.</td></tr>
                <?php else: ?>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= esc_html($r->synced_at) ?></td>
                    <td><code><?= esc_html($r->odoo_id) ?></code></td>
                    <td><code><?= esc_html($r->sku) ?: '—' ?></code></td>
                    <td><?= esc_html($r->product_name) ?></td>
                    <td><span class="oc-badge oc-action-<?= esc_attr($r->action) ?>"><?= esc_html($r->action) ?></span></td>
                    <td><span class="oc-badge oc-status-<?= esc_attr($r->status) ?>"><?= esc_html($r->status) ?></span></td>
                    <td class="oc-msg"><?= esc_html($r->message) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($total > $per): ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php
                echo paginate_links([
                    'base'      => add_query_arg('paged', '%#%'),
                    'format'    => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total'     => ceil($total / $per),
                    'current'   => $page,
                ]);
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
