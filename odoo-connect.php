<?php
/**
 * Plugin Name: Odoo Connect
 * Plugin URI:  https://github.com/qarlos77/odoo-connect
 * Description: Sincroniza productos desde Odoo hacia WooCommerce. Panel de administración, sincronización automática y webhook para actualizaciones en tiempo real.
 * Version:     1.0.0
 * Author:      PopoloPizza
 * License:     GPL-2.0+
 * Text Domain: odoo-connect
 * Requires Plugins: woocommerce
 */

defined('ABSPATH') || exit;

define('ODOO_CONNECT_VERSION', '1.0.0');
define('ODOO_CONNECT_FILE',    __FILE__);
define('ODOO_CONNECT_DIR',     plugin_dir_path(__FILE__));
define('ODOO_CONNECT_URL',     plugin_dir_url(__FILE__));

// ── Autoload ──────────────────────────────────────────────────
require_once ODOO_CONNECT_DIR . 'includes/class-install.php';
require_once ODOO_CONNECT_DIR . 'includes/class-odoo-client.php';
require_once ODOO_CONNECT_DIR . 'includes/class-product-syncer.php';
require_once ODOO_CONNECT_DIR . 'includes/class-scheduler.php';
require_once ODOO_CONNECT_DIR . 'includes/class-rest-endpoint.php';

if (is_admin()) {
    require_once ODOO_CONNECT_DIR . 'admin/class-admin.php';
}

// ── Hooks del ciclo de vida ────────────────────────────────────
register_activation_hook(__FILE__,   ['OdooConnect_Install',   'activate']);
register_deactivation_hook(__FILE__,  ['OdooConnect_Scheduler', 'deactivate']);
register_uninstall_hook(__FILE__,     'odoo_connect_uninstall');

function odoo_connect_uninstall() {
    OdooConnect_Install::uninstall();
}

// ── Bootstrap ─────────────────────────────────────────────────
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Odoo Connect</strong> requiere WooCommerce activo.</p></div>';
        });
        return;
    }

    OdooConnect_Scheduler::init();
    OdooConnect_RestEndpoint::init();

    if (is_admin()) {
        OdooConnect_Admin::init();
    }
});
