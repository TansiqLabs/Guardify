/**
 * Guardify Fraud Check — Admin JS
 * Auto-loads fraud score on order detail pages and handles refresh.
 * Includes bulk update functionality for old orders.
 *
 * @package Guardify
 * @since   1.2.0
 */

(function ($) {
    'use strict';

    // ── Auto-load on order detail page ────────────────────────────────────

    $(function () {
        const $box = $('#guardify-fraud-check-box');
        if (!$box.length) return;

        const phone = $box.data('phone');
        const orderId = $box.data('order-id');
        if (!phone) return;

        // If no cached result was rendered server-side, fetch via AJAX
        if ($box.find('.guardify-fc-loading').length) {
            runFraudCheck(phone, $box, orderId);
        }
    });

    /**
     * Perform an AJAX fraud check and inject the result HTML.
     */
    function runFraudCheck(phone, $container, orderId) {
        $container.html(
            '<div class="guardify-fc-loading">' +
            '<div class="guardify-fc-spinner"></div>' +
            '<p>Checking fraud risk for <strong>' + escHtml(phone) + '</strong>...</p>' +
            '</div>'
        );

        $.ajax({
            url: guardifyFraudCheck.ajaxurl,
            type: 'POST',
            data: {
                action: 'guardify_fraud_check',
                nonce: guardifyFraudCheck.nonce,
                phone: phone,
                order_id: orderId || 0,
            },
            success: function (response) {
                if (response.success && response.data && response.data.html) {
                    $container.html(response.data.html);
                } else {
                    $container.html(
                        '<div class="guardify-fc-result">' +
                        '<p class="guardify-fc-empty">⚠ ' + (response.data?.message || 'Could not fetch fraud data') + '</p>' +
                        '<button type="button" class="guardify-fc-refresh" onclick="guardifyRefreshFraudCheck()">↻ Retry</button>' +
                        '</div>'
                    );
                }
            },
            error: function () {
                $container.html(
                    '<div class="guardify-fc-result">' +
                    '<p class="guardify-fc-empty">⚠ Connection error</p>' +
                    '<button type="button" class="guardify-fc-refresh" onclick="guardifyRefreshFraudCheck()">↻ Retry</button>' +
                    '</div>'
                );
            },
        });
    }

    /**
     * Global refresh handler (called from inline onclick)
     */
    window.guardifyRefreshFraudCheck = function () {
        const $box = $('#guardify-fraud-check-box');
        if (!$box.length) return;
        const phone = $box.data('phone');
        const orderId = $box.data('order-id');
        if (phone) runFraudCheck(phone, $box, orderId);
    };

    // ── Lazy-load badges in order list ────────────────────────────────────

    $(function () {
        const $pending = $('.guardify-fc-badge--pending');
        if (!$pending.length) return;

        // Batch-load first 20 visible pending badges
        const phones = [];
        const phoneOrderMap = {};
        $pending.each(function (i) {
            if (i >= 20) return false; // limit
            const p = $(this).data('phone');
            const oid = $(this).data('order-id');
            if (p && phones.indexOf(p) === -1) {
                phones.push(p);
                phoneOrderMap[p] = oid || 0;
            }
        });

        // Load one at a time with a small delay to avoid slamming the server
        let idx = 0;
        function loadNext() {
            if (idx >= phones.length) return;
            const phone = phones[idx++];
            const orderId = phoneOrderMap[phone] || 0;

            $.ajax({
                url: guardifyFraudCheck.ajaxurl,
                type: 'POST',
                data: {
                    action: 'guardify_fraud_check',
                    nonce: guardifyFraudCheck.nonce,
                    phone: phone,
                    order_id: orderId,
                },
                success: function (response) {
                    if (response.success && response.data) {
                        const score = response.data.score || 0;
                        const risk = response.data.risk || 'low';
                        const title = risk.charAt(0).toUpperCase() + risk.slice(1) + ' Risk — Score: ' + score + '/100';

                        // Update all badges for this phone
                        $pending.filter('[data-phone="' + phone + '"]').each(function () {
                            $(this)
                                .removeClass('guardify-fc-badge--pending')
                                .addClass('guardify-fc-badge--' + risk)
                                .attr('title', title)
                                .text(score);
                        });
                    }
                },
                complete: function () {
                    setTimeout(loadNext, 300);
                },
            });
        }
        loadNext();
    });

    // ── Click-to-check pending badges ─────────────────────────────────────
    $(document).on('click', '.guardify-fc-badge--pending', function () {
        const $badge = $(this);
        const phone = $badge.data('phone');
        const orderId = $badge.data('order-id') || 0;
        if (!phone) return;

        $badge.text('…').css('cursor', 'wait');

        $.ajax({
            url: guardifyFraudCheck.ajaxurl,
            type: 'POST',
            data: {
                action: 'guardify_fraud_check',
                nonce: guardifyFraudCheck.nonce,
                phone: phone,
                order_id: orderId,
            },
            success: function (response) {
                if (response.success && response.data) {
                    const score = response.data.score || 0;
                    const risk = response.data.risk || 'low';
                    const title = risk.charAt(0).toUpperCase() + risk.slice(1) + ' Risk — Score: ' + score + '/100';

                    $badge
                        .removeClass('guardify-fc-badge--pending')
                        .addClass('guardify-fc-badge--' + risk)
                        .attr('title', title)
                        .text(score)
                        .css('cursor', 'default');
                } else {
                    $badge.text('!').css('cursor', 'pointer');
                }
            },
            error: function () {
                $badge.text('!').css('cursor', 'pointer');
            },
        });
    });

    function escHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ── Bulk Update All Order Scores ──────────────────────────────────────

    $(function () {
        const $btn = $('#guardify-bulk-fraud-update');
        if (!$btn.length) return;

        const $progress = $('#guardify-bulk-fraud-progress');
        let isRunning = false;
        let totalUpdated = 0;
        let totalFailed = 0;
        let totalProcessed = 0;

        $btn.on('click', function () {
            if (isRunning) return;

            const mode = confirm(
                'Update fraud scores for ALL orders?\n\n' +
                'Click OK to update ALL orders (including already scored).\n' +
                'Click Cancel to update only orders WITHOUT scores.'
            ) ? 'all' : 'missing';

            isRunning = true;
            totalUpdated = 0;
            totalFailed = 0;
            totalProcessed = 0;
            $btn.prop('disabled', true).text('⏳ Updating...');
            $progress.show().text('Starting...');

            processBatch(0, mode);
        });

        function processBatch(offset, mode) {
            $.ajax({
                url: guardifyFraudCheck.ajaxurl,
                type: 'POST',
                data: {
                    action: 'guardify_bulk_fraud_update',
                    nonce: guardifyFraudCheck.nonce,
                    batch_size: 10,
                    offset: offset,
                    mode: mode,
                },
                success: function (response) {
                    if (response.success && response.data) {
                        totalUpdated += response.data.updated || 0;
                        totalFailed += response.data.failed || 0;
                        totalProcessed += response.data.processed || 0;

                        const total = response.data.total || totalProcessed;
                        const pct = total > 0 ? Math.round((totalProcessed / total) * 100) : 100;
                        $progress.text(pct + '% — ' + totalUpdated + ' updated, ' + totalFailed + ' failed (' + totalProcessed + '/' + total + ')');

                        if (!response.data.completed) {
                            // Process next batch
                            setTimeout(function() {
                                processBatch(response.data.offset, mode);
                            }, 500);
                        } else {
                            finishBulkUpdate();
                        }
                    } else {
                        $progress.text('Error: ' + (response.data?.message || 'Unknown error'));
                        finishBulkUpdate();
                    }
                },
                error: function () {
                    $progress.text('Connection error. Please try again.');
                    finishBulkUpdate();
                },
            });
        }

        function finishBulkUpdate() {
            isRunning = false;
            $btn.prop('disabled', false).text('🛡️ Update All Scores');
            $progress.text('✅ Done! ' + totalUpdated + ' updated, ' + totalFailed + ' failed. Reload to see results.');
            setTimeout(function () {
                if (totalUpdated > 0) location.reload();
            }, 2000);
        }
    });
})(jQuery);
