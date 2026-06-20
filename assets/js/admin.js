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

    // ── Sincronización manual (chunked para evitar timeouts) ──
    $('#oc-sync-btn').on('click', function () {
        var $btn    = $(this);
        var $result = $('#oc-sync-result');

        if (!confirm('¿Sincronizar todos los productos desde Odoo?')) return;

        $btn.addClass('loading').prop('disabled', true);
        $result.show().removeClass('success error').html(
            '<span class="oc-progress-msg">Obteniendo lista de productos…</span>' +
            '<div class="oc-progress-bar"><div class="oc-progress-fill" style="width:0%"></div></div>'
        );

        var CHUNK = 6;
        var totals = {created:0, updated:0, skipped:0, unpublished:0, errors:0};

        // Paso 1: obtener todos los IDs
        $.post(OdooConnect.ajax_url, {action: 'odoo_connect_get_ids', nonce: OdooConnect.nonce})
        .done(function (res) {
            if (!res.success) {
                $result.addClass('error').html('✗ ' + (res.data ? res.data.message : 'Error al obtener IDs'));
                $btn.removeClass('loading').prop('disabled', false);
                return;
            }

            var allIds  = res.data.ids;
            var total   = allIds.length;
            var done    = 0;
            var chunks  = [];

            for (var i = 0; i < total; i += CHUNK) {
                chunks.push(allIds.slice(i, i + CHUNK));
            }

            // Paso 2: sincronizar chunk a chunk en serie
            function syncNext(idx, allIds) {
                if (idx >= chunks.length) {
                    // Paso 3: detectar y despublicar productos eliminados en Odoo
                    $result.find('.oc-progress-msg').text('Verificando eliminaciones…');
                    $.post(OdooConnect.ajax_url, {
                        action: 'odoo_connect_run_deletions',
                        nonce:  OdooConnect.nonce,
                        ids:    allIds,
                    })
                    .done(function (r) {
                        if (r.success && r.data.stats) {
                            totals.unpublished += r.data.stats.unpublished || 0;
                        }
                    })
                    .always(function () {
                        $result.find('.oc-progress-fill').css('width', '100%');
                        $result.addClass('success').html(
                            '<strong>✓ Sincronización completada</strong><br>' +
                            'Creados: <strong>' + totals.created + '</strong> &nbsp;|&nbsp; ' +
                            'Actualizados: <strong>' + totals.updated + '</strong> &nbsp;|&nbsp; ' +
                            'Sin cambios: <strong>' + totals.skipped + '</strong> &nbsp;|&nbsp; ' +
                            'Despublicados: <strong>' + totals.unpublished + '</strong>' +
                            (totals.errors ? ' &nbsp;|&nbsp; ⚠️ Errores: <strong>' + totals.errors + '</strong>' : '')
                        );
                        $('#oc-last-sync').text(new Date().toLocaleString('es-PE'));
                        $btn.removeClass('loading').prop('disabled', false);
                    });
                    return;
                }

                var chunk   = chunks[idx];
                var isLast  = (idx === chunks.length - 1);
                done       += chunk.length;
                var pct     = Math.round((done / total) * 100);

                $result.find('.oc-progress-msg').text(
                    'Sincronizando ' + done + ' / ' + total + ' productos…'
                );
                $result.find('.oc-progress-fill').css('width', pct + '%');

                $.post(OdooConnect.ajax_url, {
                    action: 'odoo_connect_sync_chunk',
                    nonce:  OdooConnect.nonce,
                    ids:    chunk,
                })
                .done(function (r) {
                    if (r.success && r.data.stats) {
                        var s = r.data.stats;
                        totals.created += s.created || 0;
                        totals.updated += s.updated || 0;
                        totals.skipped += s.skipped || 0;
                        totals.errors  += s.errors  || 0;
                    } else if (!r.success) {
                        totals.errors += chunk.length;
                    }
                    syncNext(idx + 1, allIds);
                })
                .fail(function () {
                    totals.errors += chunk.length;
                    syncNext(idx + 1, allIds);
                });
            }

            if (total === 0) {
                $result.addClass('success').html('✓ No hay productos en Odoo.');
                $btn.removeClass('loading').prop('disabled', false);
            } else {
                syncNext(0, allIds);
            }
        })
        .fail(function () {
            $result.show().addClass('error').html('✗ No se pudo conectar a Odoo.');
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
