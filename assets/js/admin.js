/* global OdooConnect, jQuery */
(function ($) {
    'use strict';

    // ── Test de conexión ──────────────────────────────────────
    $('#oc-test-btn').on('click', function () {
        var $btn    = $(this);
        var $result = $('#oc-test-result');

        $btn.prop('disabled', true).text('Probando…');
        $result.text('').removeClass('success error');

        $.post(OdooConnect.ajax_url, {
            action: 'odoo_connect_test_connection',
            nonce:  OdooConnect.nonce,
            url:    $('#oc_url').val(),
            db:     $('#oc_db').val(),
            user:   $('#oc_user').val(),
            pass:   $('#oc_pass').val(),
        })
        .done(function (res) {
            if (res.success) {
                $result.addClass('success').html('✓ ' + res.data.message);
            } else {
                $result.addClass('error').html('✗ ' + (res.data ? res.data.message : 'Error desconocido'));
            }
        })
        .fail(function () {
            $result.addClass('error').text('✗ Error de red.');
        })
        .always(function () {
            $btn.prop('disabled', false).text('Probar conexión');
        });
    });

    // ── Sincronización manual ─────────────────────────────────
    $('#oc-sync-btn').on('click', function () {
        var $btn    = $(this);
        var $result = $('#oc-sync-result');

        if (!confirm('¿Sincronizar todos los productos desde Odoo?')) return;

        $btn.addClass('loading').prop('disabled', true);
        $btn.find('.dashicons').addClass('spin');
        $result.hide().removeClass('success error');

        $.post(OdooConnect.ajax_url, {
            action: 'odoo_connect_full_sync',
            nonce:  OdooConnect.nonce,
        })
        .done(function (res) {
            $result.show();
            if (res.success) {
                var s = res.data.stats;
                $result.addClass('success').html(
                    '<strong>✓ Sincronización completada</strong><br>' +
                    '📦 Creados: <strong>' + s.created + '</strong> &nbsp;|&nbsp; ' +
                    '✏️ Actualizados: <strong>' + s.updated + '</strong> &nbsp;|&nbsp; ' +
                    '⏭️ Sin cambios: <strong>' + s.skipped + '</strong> &nbsp;|&nbsp; ' +
                    '🚫 Despublicados: <strong>' + s.unpublished + '</strong>' +
                    (s.errors ? ' &nbsp;|&nbsp; ⚠️ Errores: <strong>' + s.errors + '</strong>' : '')
                );
                // Actualizar timestamp
                var now = new Date();
                $('#oc-last-sync').text(now.toLocaleString('es-PE'));
            } else {
                $result.addClass('error').html(
                    '✗ ' + (res.data ? res.data.message : 'Error desconocido')
                );
            }
        })
        .fail(function () {
            $result.show().addClass('error').html('✗ Error de red o timeout.');
        })
        .always(function () {
            $btn.removeClass('loading').prop('disabled', false);
        });
    });

    // ── Limpiar logs ──────────────────────────────────────────
    $('#oc-clear-logs').on('click', function () {
        if (!confirm('¿Eliminar todo el historial?')) return;
        var $btn = $(this);
        $btn.prop('disabled', true);

        $.post(OdooConnect.ajax_url, {
            action: 'odoo_connect_clear_logs',
            nonce:  OdooConnect.nonce,
        }).done(function (res) {
            if (res.success) location.reload();
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    // ── Copiar URL del webhook ────────────────────────────────
    $(document).on('click', '.oc-code', function () {
        var text = $(this).text();
        navigator.clipboard.writeText(text).then(function () {
            var $el = $(this);
            $el.css('background', '#d7f3d7');
            setTimeout(function () { $el.css('background', ''); }, 800);
        }.bind(this));
    });

}(jQuery));
