/**
 * Guardify — Incomplete Order Capture (Frontend)
 *
 * Advanced checkout form monitoring with full CartFlows / FunnelKit / Block Checkout support.
 * Captures incomplete order data when customer fills checkout fields but does not complete.
 *
 * Flow:
 *  1. Monitor checkout form inputs with debounce (2s)
 *  2. When phone (11+ digits BD) + at least 1 other field → send AJAX
 *  3. Allow data updates: if customer fills more fields after first capture, re-capture
 *  4. On beforeunload / visibility hidden → send via sendBeacon (last-chance capture)
 *  5. Stop capturing after form submission
 *
 * Supported checkout builders:
 *  - WooCommerce Classic Checkout
 *  - WooCommerce Block Checkout
 *  - CartFlows / FunnelKit Checkout
 *  - Fluid Checkout
 *  - CheckoutWC
 *
 * @package Guardify
 * @since 1.3.0
 */
(function ($) {
    'use strict';

    // ─── Checkout page detection (broad) ───
    var isCheckout = (
        $('body').hasClass('woocommerce-checkout') ||
        $('.wcf-checkout-form').length > 0 ||
        $('.wc-block-checkout').length > 0 ||
        $('[data-block-name="woocommerce/checkout"]').length > 0 ||
        $('.fc-checkout-form').length > 0 ||
        $('#cfw-checkout-before-order-review').length > 0
    );

    if (!isCheckout) {
        return;
    }

    // Don't run on thank-you / order-received pages
    if ($('.woocommerce-order-received').length > 0 ||
        window.location.href.indexOf('order-received') > -1 ||
        window.location.href.indexOf('thank-you') > -1) {
        return;
    }

    var config = window.guardify_incomplete || {};
    if (!config.ajax_url || !config.nonce) {
        return;
    }

    var formSubmitted = false;
    var lastStoredHash = '';   // Hash of last stored data — allows re-capture when data changes
    var captureCount = 0;     // Track how many captures sent (max 5 per page load)
    var MAX_CAPTURES = 5;
    var ajaxInFlight = false;

    // ─── Mark form submission (prevent capture during/after submit) ───
    $(document.body).on('checkout_place_order', function () {
        formSubmitted = true;
        return true;
    });
    // CartFlows / FunnelKit
    $(document.body).on('wcf_checkout_place_order', function () {
        formSubmitted = true;
        return true;
    });
    $('form.woocommerce-checkout, form.checkout, .wcf-checkout-form form').on('submit', function () {
        formSubmitted = true;
        return true;
    });
    // Block checkout
    $(document.body).on('wc-blocks_checkout_submit', function () {
        formSubmitted = true;
    });

    // ─── Smart field value getter with fallback selectors ───
    // CartFlows, FunnelKit, Fluid Checkout, and Block Checkout can wrap fields
    // in custom containers with different selectors.
    function getFieldValue(fieldName) {
        var val = '';

        // Priority 1: Standard WooCommerce ID selector
        val = ($('#' + fieldName).val() || '').trim();
        if (val) return val;

        // Priority 2: name attribute (works across all builders)
        val = ($('[name="' + fieldName + '"]').val() || '').trim();
        if (val) return val;

        // Priority 3: CartFlows wrapper — #wcf-{fieldName}-field input
        var wcfId = '#wcf-' + fieldName.replace('billing_', 'billing-') + '-field';
        val = ($(wcfId + ' input, ' + wcfId + ' select').val() || '').trim();
        if (val) return val;

        // Priority 4: Block checkout — data attributes
        val = ($('[data-checkout-field="' + fieldName.replace('billing_', '') + '"] input').val() || '').trim();
        if (val) return val;

        // Priority 5: Fluid Checkout field wrappers
        val = ($('.fc-' + fieldName + ' input, .fc-' + fieldName + ' select').val() || '').trim();

        return val;
    }

    // ─── Collect form data ───
    function collectFormData() {
        return {
            billing_phone:      getFieldValue('billing_phone'),
            billing_first_name: getFieldValue('billing_first_name'),
            billing_last_name:  getFieldValue('billing_last_name'),
            billing_email:      getFieldValue('billing_email'),
            billing_address_1:  getFieldValue('billing_address_1'),
            billing_city:       getFieldValue('billing_city'),
            billing_state:      getFieldValue('billing_state'),
            billing_country:    getFieldValue('billing_country'),
            billing_postcode:   getFieldValue('billing_postcode')
        };
    }

    // ─── Validate BD phone: 01[3-9]XXXXXXXX (11 digits) ───
    function isValidPhone(phone) {
        var digits = phone.replace(/\D/g, '');
        // Strip +88 or 88 prefix if present
        if (digits.length === 13 && digits.substring(0, 2) === '88') {
            digits = digits.substring(2);
        }
        if (digits.length === 14 && digits.substring(0, 3) === '880') {
            digits = '0' + digits.substring(3);
        }
        return /^01[3-9]\d{8}$/.test(digits);
    }

    // ─── Create a simple hash of data to detect changes ───
    function hashData(data) {
        var parts = [];
        for (var key in data) {
            if (data.hasOwnProperty(key)) {
                parts.push(key + '=' + (data[key] || ''));
            }
        }
        return parts.join('|');
    }

    // ─── Count filled fields (excluding phone) ───
    function filledFieldCount(data) {
        var count = 0;
        if (data.billing_first_name) count++;
        if (data.billing_last_name) count++;
        if (data.billing_email) count++;
        if (data.billing_address_1) count++;
        if (data.billing_city) count++;
        return count;
    }

    // ─── Should we store? ───
    function shouldStore(data) {
        if (formSubmitted) return false;
        if (captureCount >= MAX_CAPTURES) return false;
        if (!data.billing_phone || !isValidPhone(data.billing_phone)) return false;

        // Need at least 1 other field filled
        if (filledFieldCount(data) < 1) return false;

        // Check if data actually changed since last store
        var currentHash = hashData(data);
        if (currentHash === lastStoredHash) return false;

        return true;
    }

    // ─── Send via AJAX ───
    function storeIncompleteOrder(data) {
        if (formSubmitted || ajaxInFlight) return;
        ajaxInFlight = true;

        $.ajax({
            url: config.ajax_url,
            type: 'POST',
            data: $.extend({}, data, {
                action: 'guardify_capture_checkout',
                nonce: config.nonce
            }),
            success: function (response) {
                ajaxInFlight = false;
                if (response.success) {
                    lastStoredHash = hashData(data);
                    captureCount++;
                    if (response.data && response.data.status) {
                        console.log('Guardify: ' + response.data.status + ' — ' + (response.data.message || ''));
                    }
                }
            },
            error: function () {
                ajaxInFlight = false;
                console.log('Guardify: Error storing incomplete order');
            }
        });
    }

    // ─── Send via sendBeacon (page unload) ───
    function beaconStore(data) {
        if (formSubmitted) return;
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

    // ─── All checkout form selectors ───
    var formSelectors = [
        'form.checkout',
        'form.woocommerce-checkout',
        '.wcf-checkout-form',
        '.wc-block-checkout',
        '.fc-checkout-form',
        '#cfw-checkout-before-order-review'
    ].join(', ');

    // ─── Monitor form changes ───
    $(formSelectors)
        .on('change input', 'input, select, textarea', function () {
            if (formSubmitted) return;

            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                var data = collectFormData();
                if (shouldStore(data)) {
                    storeIncompleteOrder(data);
                }
            }, DEBOUNCE_MS);
        });

    // Also monitor at document level for dynamically loaded forms (CartFlows AJAX)
    $(document).on('change input',
        '[name="billing_phone"], [name="billing_first_name"], [name="billing_last_name"], [name="billing_email"], [name="billing_address_1"]',
        function () {
            if (formSubmitted) return;
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                var data = collectFormData();
                if (shouldStore(data)) {
                    storeIncompleteOrder(data);
                }
            }, DEBOUNCE_MS);
        }
    );

    // ─── Immediate capture on valid phone blur ───
    $(document).on('blur', '#billing_phone, [name="billing_phone"]', function () {
        if (formSubmitted) return;

        var data = collectFormData();
        if (shouldStore(data)) {
            clearTimeout(debounceTimer);
            storeIncompleteOrder(data);
        }
    });

    // ─── Immediate capture on name/email blur (second chance) ───
    $(document).on('blur', '#billing_first_name, #billing_last_name, #billing_email, [name="billing_first_name"], [name="billing_last_name"], [name="billing_email"]', function () {
        if (formSubmitted) return;

        // Small delay to let other fields settle
        setTimeout(function () {
            var data = collectFormData();
            if (shouldStore(data)) {
                clearTimeout(debounceTimer);
                storeIncompleteOrder(data);
            }
        }, 500);
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
        if (formSubmitted) return;

        var data = collectFormData();
        if (shouldStore(data)) {
            beaconStore(data);
        }
    });

})(jQuery);
