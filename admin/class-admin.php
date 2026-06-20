<?php
defined('ABSPATH') || exit;

class OdooConnect_Admin {

    public static function init(): void {
        add_action('admin_menu',    [__CLASS__, 'register_menu']);
        add_action('admin_init',    [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_odoo_connect_full_sync',        [__CLASS__, 'ajax_full_sync']);
        add_action('wp_ajax_odoo_connect_test_connection',  [__CLASS__, 'ajax_test_connection']);
        add_action('wp_ajax_odoo_connect_clear_logs',       [__CLASS__, 'ajax_clear_logs']);
    }

    // ── Menú ──────────────────────────────────────────────────
    public static function register_menu(): void {
        add_menu_page(
            'Odoo Connect',
            'Odoo Connect',
            'manage_options',
            'odoo-connect',
            [__CLASS__, 'render_settings'],
            'dashicons-rest-api',
            56
        );
        add_submenu_page(
            'odoo-connect',
            'Ajustes',
            'Ajustes',
            'manage_options',
            'odoo-connect',
            [__CLASS__, 'render_settings']
        );
        add_submenu_page(
            'odoo-connect',
            'Historial de Sync',
            'Historial',
            'manage_options',
            'odoo-connect-logs',
            [__CLASS__, 'render_logs']
        );
    }

    // ── Settings API ──────────────────────────────────────────
    public static function register_settings(): void {
        $fields = [
            'odoo_connect_url'            => ['label' => 'URL de Odoo',          'type' => 'url'],
            'odoo_connect_db'             => ['label' => 'Base de datos',         'type' => 'text'],
            'odoo_connect_user'           => ['label' => 'Usuario',               'type' => 'text'],
            'odoo_connect_password'       => ['label' => 'Contraseña',            'type' => 'password'],
            'odoo_connect_sync_interval'  => ['label' => 'Intervalo de sync',     'type' => 'select'],
            'odoo_connect_webhook_secret' => ['label' => 'Clave del webhook',     'type' => 'text'],
        ];

        register_setting('odoo_connect_group', 'odoo_connect_url',           ['sanitize_callback' => 'esc_url_raw']);
        register_setting('odoo_connect_group', 'odoo_connect_db',            ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('odoo_connect_group', 'odoo_connect_user',          ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('odoo_connect_group', 'odoo_connect_password',      ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('odoo_connect_group', 'odoo_connect_sync_interval', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('odoo_connect_group', 'odoo_connect_webhook_secret',['sanitize_callback' => 'sanitize_text_field']);
    }

    // ── Assets ────────────────────────────────────────────────
    public static function enqueue_assets(string $hook): void {
        if (!str_contains($hook, 'odoo-connect')) return;
        wp_enqueue_style('odoo-connect-admin',  ODOO_CONNECT_URL . 'assets/css/admin.css', [], ODOO_CONNECT_VERSION);
        wp_enqueue_script('odoo-connect-admin', ODOO_CONNECT_URL . 'assets/js/admin.js',  ['jquery'], ODOO_CONNECT_VERSION, true);
        wp_localize_script('odoo-connect-admin', 'OdooConnect', [
            'nonce'       => wp_create_nonce('odoo_connect_nonce'),
            'ajax_url'    => admin_url('admin-ajax.php'),
            'webhook_url' => rest_url('odoo-connect/v1/sync'),
        ]);
    }

    // ── Vistas ────────────────────────────────────────────────
    public static function render_settings(): void {
        include ODOO_CONNECT_DIR . 'admin/views/page-settings.php';
    }

    public static function render_logs(): void {
        include ODOO_CONNECT_DIR . 'admin/views/page-logs.php';
    }

    // ── AJAX: Sync manual ─────────────────────────────────────
    public static function ajax_full_sync(): void {
        check_ajax_referer('odoo_connect_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die('No autorizado');

        @set_time_limit(300);
        ignore_user_abort(true);

        $client = OdooConnect_Scheduler::build_client();
        if (!$client) {
            wp_send_json_error(['message' => 'No se pudo conectar a Odoo. Verifica los ajustes.']);
        }

        $syncer = new OdooConnect_ProductSyncer($client);
        $stats  = $syncer->run_full_sync();
        wp_send_json_success(['stats' => $stats]);
    }

    // ── AJAX: Test conexión ───────────────────────────────────
    public static function ajax_test_connection(): void {
        check_ajax_referer('odoo_connect_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die('No autorizado');

        $url  = sanitize_text_field($_POST['url']  ?? '');
        $db   = sanitize_text_field($_POST['db']   ?? '');
        $user = sanitize_text_field($_POST['user'] ?? '');
        $pass = sanitize_text_field($_POST['pass'] ?? '');

        if (!$url || !$db || !$user || !$pass) {
            wp_send_json_error(['message' => 'Completa todos los campos.']);
        }

        try {
            $client = new OdooConnect_Client($url, $db, $user, $pass);
            if ($client->authenticate()) {
                $count = count($client->get_all_product_ids());
                wp_send_json_success(['message' => "Conexión exitosa. $count productos encontrados en Odoo."]);
            } else {
                wp_send_json_error(['message' => 'Autenticación fallida. Verifica usuario y contraseña.']);
            }
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
        }
    }

    // ── AJAX: Limpiar logs ────────────────────────────────────
    public static function ajax_clear_logs(): void {
        check_ajax_referer('odoo_connect_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die('No autorizado');
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}" . OdooConnect_Install::TABLE_LOGS);
        wp_send_json_success();
    }
}
