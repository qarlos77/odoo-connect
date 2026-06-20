<?php
defined('ABSPATH') || exit;

class OdooConnect_Install {

    const TABLE_LOGS = 'odoo_connect_logs';

    public static function activate() {
        self::create_tables();
        // Default options
        add_option('odoo_connect_url',            '');
        add_option('odoo_connect_db',             '');
        add_option('odoo_connect_user',           'admin');
        add_option('odoo_connect_password',       '');
        add_option('odoo_connect_sync_interval',  'hourly');
        add_option('odoo_connect_webhook_secret', wp_generate_password(32, false));
        add_option('odoo_connect_last_sync',      '');
        add_option('odoo_connect_version',        ODOO_CONNECT_VERSION);
    }

    public static function create_tables() {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE_LOGS;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            synced_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            odoo_id     BIGINT UNSIGNED NOT NULL,
            sku         VARCHAR(100)    NOT NULL DEFAULT '',
            product_name VARCHAR(255)   NOT NULL DEFAULT '',
            action      VARCHAR(20)     NOT NULL DEFAULT '',
            status      VARCHAR(20)     NOT NULL DEFAULT 'ok',
            message     TEXT,
            PRIMARY KEY (id),
            KEY idx_odoo_id (odoo_id),
            KEY idx_status  (status),
            KEY idx_synced  (synced_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function uninstall() {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}" . self::TABLE_LOGS);

        $options = [
            'odoo_connect_url', 'odoo_connect_db', 'odoo_connect_user',
            'odoo_connect_password', 'odoo_connect_sync_interval',
            'odoo_connect_webhook_secret', 'odoo_connect_last_sync',
            'odoo_connect_version',
        ];
        foreach ($options as $opt) {
            delete_option($opt);
        }
    }
}
