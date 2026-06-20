<?php defined('ABSPATH') || exit; ?>
<div class="wrap odoo-connect-wrap">
    <h1 class="oc-title">
        <span class="dashicons dashicons-rest-api"></span>
        Odoo Connect
    </h1>

    <?php settings_errors(); ?>

    <div class="oc-layout">

        <!-- ── Panel izquierdo: Ajustes ────────────────────── -->
        <div class="oc-main">
            <form method="post" action="options.php">
                <?php settings_fields('odoo_connect_group'); ?>

                <div class="oc-card">
                    <h2>Conexión con Odoo</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="oc_url">URL de Odoo</label></th>
                            <td>
                                <input id="oc_url" type="url" name="odoo_connect_url"
                                    value="<?= esc_attr(get_option('odoo_connect_url')) ?>"
                                    class="regular-text" placeholder="https://sistema.mipizza.com"/>
                                <p class="description">URL base de tu instancia Odoo (sin barra final).</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="oc_db">Base de datos</label></th>
                            <td>
                                <input id="oc_db" type="text" name="odoo_connect_db"
                                    value="<?= esc_attr(get_option('odoo_connect_db')) ?>"
                                    class="regular-text" placeholder="MiPizzaLoyalty"/>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="oc_user">Usuario</label></th>
                            <td>
                                <input id="oc_user" type="text" name="odoo_connect_user"
                                    value="<?= esc_attr(get_option('odoo_connect_user', 'admin')) ?>"
                                    class="regular-text"/>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="oc_pass">Contraseña</label></th>
                            <td>
                                <input id="oc_pass" type="password" name="odoo_connect_password"
                                    value="<?= esc_attr(get_option('odoo_connect_password')) ?>"
                                    class="regular-text" autocomplete="new-password"/>
                            </td>
                        </tr>
                    </table>

                    <div class="oc-test-row">
                        <button type="button" id="oc-test-btn" class="button button-secondary">
                            Probar conexión
                        </button>
                        <span id="oc-test-result"></span>
                    </div>
                </div>

                <div class="oc-card">
                    <h2>Comportamiento de productos</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="oc_product_status">Estado al importar</label></th>
                            <td>
                                <select id="oc_product_status" name="odoo_connect_product_status">
                                    <?php $current_status = get_option('odoo_connect_product_status', 'publish'); ?>
                                    <option value="publish" <?= selected($current_status, 'publish', false) ?>>
                                        Publicado (visible en tienda)
                                    </option>
                                    <option value="draft" <?= selected($current_status, 'draft', false) ?>>
                                        Borrador (revisar manualmente)
                                    </option>
                                </select>
                                <p class="description">Estado con el que se crean y actualizan los productos sincronizados desde Odoo.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="oc-card">
                    <h2>Sincronización automática</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="oc_interval">Intervalo</label></th>
                            <td>
                                <select id="oc_interval" name="odoo_connect_sync_interval">
                                    <?php
                                    $intervals = [
                                        'disabled'        => 'Desactivada',
                                        'every_5_minutes' => 'Cada 5 minutos',
                                        'every_15_minutes'=> 'Cada 15 minutos',
                                        'every_30_minutes'=> 'Cada 30 minutos',
                                        'hourly'          => 'Cada hora',
                                        'twicedaily'      => 'Dos veces al día',
                                        'daily'           => 'Una vez al día',
                                    ];
                                    $current = get_option('odoo_connect_sync_interval', 'hourly');
                                    foreach ($intervals as $val => $label):
                                    ?>
                                    <option value="<?= $val ?>" <?= selected($current, $val, false) ?>>
                                        <?= esc_html($label) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php
                                $next = wp_next_scheduled(OdooConnect_Scheduler::HOOK);
                                if ($next):
                                ?>
                                <p class="description">
                                    Próxima sync: <strong><?= esc_html(wp_date('d/m/Y H:i:s', $next)) ?></strong>
                                </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Última sync</th>
                            <td>
                                <?php $last = get_option('odoo_connect_last_sync', ''); ?>
                                <span id="oc-last-sync">
                                    <?= $last ? esc_html(wp_date('d/m/Y H:i:s', strtotime($last))) : 'Nunca' ?>
                                </span>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="oc-card">
                    <h2>Webhook (Push desde Odoo)</h2>
                    <table class="form-table">
                        <tr>
                            <th>URL del endpoint</th>
                            <td>
                                <code class="oc-code"><?= esc_html(rest_url('odoo-connect/v1/sync')) ?></code>
                                <p class="description">
                                    Odoo puede llamar a esta URL (POST) para sincronizar un producto al instante.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="oc_secret">Clave secreta</label></th>
                            <td>
                                <input id="oc_secret" type="text" name="odoo_connect_webhook_secret"
                                    value="<?= esc_attr(get_option('odoo_connect_webhook_secret')) ?>"
                                    class="regular-text" readonly/>
                                <p class="description">
                                    Envía este valor en el header <code>X-Odoo-Secret</code>.
                                    <br>Body JSON: <code>{"action": "write", "id": 123}</code>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button('Guardar ajustes'); ?>
            </form>
        </div>

        <!-- ── Panel derecho: Sync manual ──────────────────── -->
        <div class="oc-sidebar">
            <div class="oc-card oc-sync-card">
                <h2>Sincronizar ahora</h2>
                <p>Trae todos los productos activos de Odoo y los crea o actualiza en WooCommerce.</p>
                <button type="button" id="oc-sync-btn" class="button button-primary button-hero oc-sync-btn">
                    <span class="dashicons dashicons-update"></span>
                    Sincronizar productos
                </button>
                <div id="oc-sync-result" class="oc-sync-result" style="display:none"></div>
            </div>

            <div class="oc-card">
                <h2>Estado</h2>
                <ul class="oc-status-list">
                    <li>
                        <span class="oc-label">Productos en WC con Odoo ID</span>
                        <strong><?php
                            global $wpdb;
                            echo (int) $wpdb->get_var(
                                "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='_odoo_id'"
                            );
                        ?></strong>
                    </li>
                    <li>
                        <span class="oc-label">Sync automática</span>
                        <strong>
                            <?= get_option('odoo_connect_sync_interval','hourly') === 'disabled'
                                ? '<span style="color:#d63638">Desactivada</span>'
                                : '<span style="color:#00a32a">Activa</span>'
                            ?>
                        </strong>
                    </li>
                </ul>
            </div>

            <div class="oc-card">
                <h2>Push desde Odoo</h2>
                <p style="font-size:13px;color:#1d2327">
                    Configura en <strong>Odoo → Ajustes → Loyalty Rewards API → WooCommerce</strong>:
                </p>
                <ul class="oc-status-list" style="font-size:13px">
                    <li>
                        <span class="oc-label">Webhook URL</span>
                        <code class="oc-code" style="font-size:11px"><?= esc_html(rest_url('odoo-connect/v1/sync')) ?></code>
                    </li>
                    <li>
                        <span class="oc-label">Clave secreta</span>
                        <code class="oc-code" style="font-size:11px"><?= esc_attr(get_option('odoo_connect_webhook_secret','—')) ?></code>
                    </li>
                </ul>
                <p style="font-size:12px;color:#646970;margin-top:10px">
                    El addon <em>loyalty_rewards_api</em> enviará automáticamente una llamada a esta URL cada vez que un producto sea creado, editado o eliminado en Odoo.
                </p>
            </div>
        </div>
    </div>
</div>
