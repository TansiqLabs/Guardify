/**
 * Guardify Fraud Check — Admin JS
 * Auto-loads fraud score on order detail pages and handles refresh.
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
        if (!phone) return;

        // If no cached result was rendered server-side, fetch via AJAX
        if ($box.find('.guardify-fc-loading').length) {
            runFraudCheck(phone, $box);
        }
    });

    /**
     * Perform an AJAX fraud check and inject the result HTML.
     */
    function runFraudCheck(phone, $container) {
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
        if (phone) runFraudCheck(phone, $box);
    };

    // ── Lazy-load badges in order list ────────────────────────────────────

    $(function () {
        const $pending = $('.guardify-fc-badge--pending');
        if (!$pending.length) return;

        // Batch-load first 20 visible pending badges
        const phones = [];
        $pending.each(function (i) {
            if (i >= 20) return false; // limit
            const p = $(this).data('phone');
            if (p && phones.indexOf(p) === -1) {
                phones.push(p);
            }
        });

        // Load one at a time with a small delay to avoid slamming the server
        let idx = 0;
        function loadNext() {
            if (idx >= phones.length) return;
            const phone = phones[idx++];

            $.ajax({
                url: guardifyFraudCheck.ajaxurl,
                type: 'POST',
                data: {
                    action: 'guardify_fraud_check',
                    nonce: guardifyFraudCheck.nonce,
                    phone: phone,
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
        if (!phone) return;

        $badge.text('…').css('cursor', 'wait');

        $.ajax({
            url: guardifyFraudCheck.ajaxurl,
            type: 'POST',
            data: {
                action: 'guardify_fraud_check',
                nonce: guardifyFraudCheck.nonce,
                phone: phone,
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
})(jQuery);
