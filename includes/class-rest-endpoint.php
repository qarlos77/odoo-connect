<?php
defined('ABSPATH') || exit;

/**
 * Webhook que Odoo puede llamar para forzar la sincronización de un producto.
 *
 * POST /wp-json/odoo-connect/v1/sync
 * Header: X-Odoo-Secret: {webhook_secret}
 * Body JSON:
 *   { "action": "write|create|unlink", "id": 123 }
 */
class OdooConnect_RestEndpoint {

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route('odoo-connect/v1', '/sync', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [__CLASS__, 'handle_sync'],
            'permission_callback' => [__CLASS__, 'check_secret'],
        ]);

        register_rest_route('odoo-connect/v1', '/ping', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => fn() => new WP_REST_Response(['status' => 'ok', 'version' => ODOO_CONNECT_VERSION]),
            'permission_callback' => '__return_true',
        ]);
    }

    public static function check_secret(WP_REST_Request $request): bool {
        $secret   = get_option('odoo_connect_webhook_secret', '');
        $provided = $request->get_header('X-Odoo-Secret');
        return $secret && hash_equals($secret, (string) $provided);
    }

    public static function handle_sync(WP_REST_Request $request): WP_REST_Response {
        $body   = $request->get_json_params();
        $action = sanitize_text_field($body['action'] ?? '');
        $id     = (int) ($body['id'] ?? 0);

        if (!$id) {
            return new WP_REST_Response(['error' => 'Missing id'], 400);
        }

        $client = OdooConnect_Scheduler::build_client();
        if (!$client) {
            return new WP_REST_Response(['error' => 'Odoo connection not configured'], 503);
        }

        $syncer = new OdooConnect_ProductSyncer($client);

        if ($action === 'unlink') {
            // Despublicar en WC
            $syncer->sync_one($id); // sync_one maneja active=False → draft
        } else {
            $syncer->sync_one($id);
        }

        return new WP_REST_Response([
            'ok'    => true,
            'stats' => $syncer->get_stats(),
        ]);
    }
}
