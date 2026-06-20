<?php
defined('ABSPATH') || exit;

class OdooConnect_Scheduler {

    const HOOK = 'odoo_connect_cron_sync';

    public static function init(): void {
        add_filter('cron_schedules', [__CLASS__, 'add_intervals']);
        add_action(self::HOOK, [__CLASS__, 'run']);
        add_action('update_option_odoo_connect_sync_interval', [__CLASS__, 'reschedule'], 10, 2);
        self::maybe_schedule();
    }

    public static function add_intervals(array $schedules): array {
        $schedules['every_5_minutes']  = ['interval' => 300,   'display' => 'Cada 5 minutos'];
        $schedules['every_15_minutes'] = ['interval' => 900,   'display' => 'Cada 15 minutos'];
        $schedules['every_30_minutes'] = ['interval' => 1800,  'display' => 'Cada 30 minutos'];
        return $schedules;
    }

    public static function maybe_schedule(): void {
        $interval = get_option('odoo_connect_sync_interval', 'hourly');
        if ($interval === 'disabled') {
            self::clear();
            return;
        }
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), $interval, self::HOOK);
        }
    }

    public static function reschedule($old, $new): void {
        self::clear();
        if ($new !== 'disabled') {
            wp_schedule_event(time(), $new, self::HOOK);
        }
    }

    public static function clear(): void {
        $timestamp = wp_next_scheduled(self::HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }

    public static function deactivate(): void {
        self::clear();
    }

    public static function run(): void {
        $client  = self::build_client();
        if (!$client) return;

        $syncer = new OdooConnect_ProductSyncer($client);
        $syncer->run_incremental_sync();
    }

    public static function build_client(): ?OdooConnect_Client {
        $url  = get_option('odoo_connect_url', '');
        $db   = get_option('odoo_connect_db', '');
        $user = get_option('odoo_connect_user', '');
        $pass = get_option('odoo_connect_password', '');

        if (!$url || !$db || !$user || !$pass) return null;

        $client = new OdooConnect_Client($url, $db, $user, $pass);
        if (!$client->authenticate()) return null;
        return $client;
    }
}
