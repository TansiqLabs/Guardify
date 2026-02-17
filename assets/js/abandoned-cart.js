/**
 * Guardify — Incomplete Order Capture (Frontend)
 *
 * Simple, reliable checkout form monitoring.
 * Captures incomplete order data when customer fills checkout fields but does not complete.
 *
 * Flow:
 *  1. Monitor checkout form inputs with debounce
 *  2. When phone (11+ digits BD) + at least 1 other field filled → send AJAX
 *  3. On beforeunload / visibility hidden → send via sendBeacon
 *  4. Stop capturing after form submission or successful store
 *
 * @package Guardify
 * @since 1.2.3
 */
(function ($) {
    'use strict';

    // Only run on checkout
    if (!$('body').hasClass('woocommerce-checkout') && !$('.wcf-checkout-form').length) {
        return;
    }

    var config = window.guardify_incomplete || {};
    if (!config.ajax_url || !config.nonce) {
        return;
    }

    var stored = false;
    var formSubmitted = false;

    // ─── Mark form submission (prevent capture during/after submit) ───
    $(document.body).on('checkout_place_order', function () {
        formSubmitted = true;
        return true;
    });
    $('form.woocommerce-checkout, form.checkout').on('submit', function () {
        formSubmitted = true;
        return true;
    });

    // ─── Collect form data ───
    function collectFormData() {
        return {
            billing_phone:      ($('#billing_phone').val() || '').trim(),
            billing_first_name: ($('#billing_first_name').val() || '').trim(),
            billing_email:      ($('#billing_email').val() || '').trim(),
            billing_address_1:  ($('#billing_address_1').val() || '').trim(),
            billing_city:       ($('#billing_city').val() || '').trim(),
            billing_state:      ($('#billing_state').val() || '').trim(),
            billing_country:    ($('#billing_country').val() || '').trim(),
            billing_postcode:   ($('#billing_postcode').val() || '').trim()
        };
    }

    // ─── Validate BD phone: 01[3-9]XXXXXXXX (11 digits) ───
    function isValidPhone(phone) {
        var digits = phone.replace(/\D/g, '');
        // Strip 88 prefix if present
        if (digits.length === 13 && digits.substring(0, 2) === '88') {
            digits = digits.substring(2);
        }
        return /^01[3-9]\d{8}$/.test(digits);
    }

    // ─── Should we store? ───
    function shouldStore(data) {
        if (formSubmitted || stored) return false;
        if (!data.billing_phone || !isValidPhone(data.billing_phone)) return false;

        // Need at least 1 other field filled (name, email, or address)
        var extra = 0;
        if (data.billing_first_name) extra++;
        if (data.billing_email) extra++;
        if (data.billing_address_1) extra++;
        return extra >= 1;
    }

    // ─── Send via AJAX ───
    function storeIncompleteOrder(data) {
        if (formSubmitted || stored) return;

        $.ajax({
            url: config.ajax_url,
            type: 'POST',
            data: $.extend({}, data, {
                action: 'guardify_capture_checkout',
                nonce: config.nonce
            }),
            success: function (response) {
                if (response.success) {
                    stored = true;
                    if (response.data && response.data.status) {
                        console.log('Guardify: ' + response.data.status + ' — ' + (response.data.message || ''));
                    }
                }
            },
            error: function () {
                console.log('Guardify: Error storing incomplete order');
            }
        });
    }

    // ─── Send via sendBeacon (page unload) ───
    function beaconStore(data) {
        if (formSubmitted || stored) return;
        if (!navigator.sendBeacon) return;

        var formData = new FormData();
        formData.append('action', 'guardify_capture_checkout');
        formData.append('nonce', config.nonce);

        Object.keys(data).forEach(function (key) {
            formData.append(key, data[key] || '');
        });

        navigator.sendBeacon(config.ajax_url, formData);
    }

    // ─── Debounce timer ───
    var debounceTimer;
    var DEBOUNCE_MS = 2000; // 2 seconds

    // ─── Monitor form changes ───
    $('form.checkout, form.woocommerce-checkout, .wcf-checkout-form')
        .on('change input', 'input, select, textarea', function () {
            if (formSubmitted || stored) return;

            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                var data = collectFormData();
                if (shouldStore(data)) {
                    storeIncompleteOrder(data);
                }
            }, DEBOUNCE_MS);
        });

    // ─── Immediate capture on valid phone blur ───
    $(document).on('blur', '#billing_phone, [name="billing_phone"]', function () {
        if (formSubmitted || stored) return;

        var data = collectFormData();
        if (shouldStore(data)) {
            clearTimeout(debounceTimer);
            storeIncompleteOrder(data);
        }
    });

    // ─── Page visibility change (tab switch) → beacon ───
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            var data = collectFormData();
            if (shouldStore(data)) {
                beaconStore(data);
            }
        }
    });

    // ─── Page unload → beacon ───
    window.addEventListener('beforeunload', function () {
        if (formSubmitted || stored) return;

        var data = collectFormData();
        if (shouldStore(data)) {
            beaconStore(data);
        }
    });

    // ─── Don't run on thank-you page ───
    $(function () {
        if ($('.woocommerce-order-received').length > 0 ||
            window.location.href.indexOf('order-received') > -1) {
            stored = true; // prevent any capture
        }
    });

})(jQuery);
