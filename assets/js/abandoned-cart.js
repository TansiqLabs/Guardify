/**
 * Guardify — Abandoned Cart / Incomplete Order Capture (Frontend JS)
 *
 * Captures checkout data in real-time and sends it to the server via AJAX.
 * Creates "Incomplete" orders IMMEDIATELY — even before the customer fills any field.
 *
 * Capture strategy:
 *  1. Page load → browser metadata (IP handled server-side, UA, referrer, screen, language)
 *  2. Any field interaction → all filled fields + custom WooCommerce fields + browser data
 *  3. Tab switch / browser close → sendBeacon with latest state
 *
 * Works with:
 *  - Standard WooCommerce Checkout
 *  - CartFlows Checkout (all layouts: modern, instant, one-column)
 *  - WooCommerce Block Checkout (limited — captures on blur)
 *  - Custom checkout fields (size, color, any extra WooCommerce field)
 *  - LiteSpeed Cache (aggressive/advanced) — uses admin-ajax.php, never cached
 *  - OpenLiteSpeed server
 *  - Redis object cache — transparent, no impact
 *
 * @package Guardify
 * @since 1.1.0
 */

(function ($) {
    'use strict';

    // Bail if config not available
    if (typeof guardifyAbandonedCart === 'undefined') {
        return;
    }

    var config = guardifyAbandonedCart;
    var draftId = config.draft_id || 0;
    var debounceMs = config.debounce || 5000;
    var captureTimer = null;
    var lastCapturedData = '';
    var isSending = false;
    var initialCaptureSent = false; // Track if the page-load capture was sent
    var formSubmitting = false;
    var formBound = false; // Track if form events have been bound
    var currentNonce = config.nonce; // Live nonce (can be refreshed)
    var lastNonceRefreshTime = 0; // Timestamp of last nonce refresh (allow multiple with cooldown)

    // =========================================================================
    // BROWSER METADATA COLLECTION
    // =========================================================================

    /**
     * Collect browser-level metadata (available without any form interaction)
     */
    function collectBrowserData() {
        var data = {};
        try {
            data.browser_ua         = navigator.userAgent || '';
            data.browser_language   = navigator.language || navigator.userLanguage || '';
            data.browser_platform   = navigator.platform || '';
            data.screen_width       = screen.width || 0;
            data.screen_height      = screen.height || 0;
            data.viewport_width     = window.innerWidth || 0;
            data.viewport_height    = window.innerHeight || 0;
            data.referrer           = document.referrer || '';
            data.timezone           = Intl && Intl.DateTimeFormat ? Intl.DateTimeFormat().resolvedOptions().timeZone : '';
            data.page_url           = window.location.href;
            data.page_title         = document.title || '';
            data.connection_type    = (navigator.connection && navigator.connection.effectiveType) ? navigator.connection.effectiveType : '';
            data.device_memory      = navigator.deviceMemory || 0;
            data.hardware_concurrency = navigator.hardwareConcurrency || 0;
            data.touch_support      = ('ontouchstart' in window || navigator.maxTouchPoints > 0) ? '1' : '0';
            data.cookie_enabled     = navigator.cookieEnabled ? '1' : '0';
            data.do_not_track       = navigator.doNotTrack || window.doNotTrack || '0';
            data.color_depth        = screen.colorDepth || 0;
            data.pixel_ratio        = window.devicePixelRatio || 1;

            // UTM / campaign params
            var urlParams = new URLSearchParams(window.location.search);
            ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid'].forEach(function (param) {
                var val = urlParams.get(param);
                if (val) data['param_' + param] = val;
            });
        } catch (e) {
            // Silent — some fields may fail in restricted environments
        }
        return data;
    }

    // =========================================================================
    // FORM DATA COLLECTION (billing, shipping, custom fields, CartFlows)
    // =========================================================================

    /**
     * Collect ALL checkout field values — standard + custom + CartFlows
     */
    function collectFormData() {
        var data = {
            action: 'guardify_capture_checkout',
            nonce: currentNonce,
            draft_id: draftId,
            capture_url: window.location.href,
            capture_trigger: 'field_blur'
        };

        // Standard billing fields
        var billingFields = [
            'billing_first_name', 'billing_last_name', 'billing_company',
            'billing_address_1', 'billing_address_2', 'billing_city',
            'billing_state', 'billing_postcode', 'billing_country',
            'billing_email', 'billing_phone'
        ];

        billingFields.forEach(function (field) {
            var el = document.getElementById(field) || document.querySelector('[name="' + field + '"]');
            if (el) {
                data[field] = el.value || '';
            }
        });

        // Standard shipping fields
        var shippingFields = [
            'shipping_first_name', 'shipping_last_name', 'shipping_company',
            'shipping_address_1', 'shipping_address_2', 'shipping_city',
            'shipping_state', 'shipping_postcode', 'shipping_country'
        ];

        shippingFields.forEach(function (field) {
            var el = document.getElementById(field) || document.querySelector('[name="' + field + '"]');
            if (el) {
                data[field] = el.value || '';
            }
        });

        // Order comments
        var comments = document.getElementById('order_comments') || document.querySelector('[name="order_comments"]');
        if (comments) {
            data.order_comments = comments.value || '';
        }

        // CartFlows hidden fields
        var flowId = document.querySelector('input[name="_wcf_flow_id"]');
        var checkoutId = document.querySelector('input[name="_wcf_checkout_id"]');
        if (flowId) data._wcf_flow_id = flowId.value;
        if (checkoutId) data._wcf_checkout_id = checkoutId.value;

        // ─── CUSTOM / DYNAMIC CHECKOUT FIELDS ───
        // Scans the checkout form for any non-standard fields (size, color, etc.)
        var customFields = {};
        var forms = document.querySelectorAll('form.checkout, form.woocommerce-checkout, .wcf-checkout-form, #wcf-embed-checkout-form');
        forms.forEach(function (form) {
            var inputs = form.querySelectorAll('input, select, textarea');
            inputs.forEach(function (el) {
                var name = el.name || '';
                if (!name) return;

                // Skip standard fields we already captured
                if (name.indexOf('billing_') === 0 || name.indexOf('shipping_') === 0) return;
                if (name === 'order_comments') return;

                // Skip WooCommerce internals / payment / nonces
                if (name.indexOf('_wp') === 0 || name.indexOf('woocommerce') === 0) return;
                if (name === 'payment_method' || name.indexOf('_token') > -1) return;
                if (name.indexOf('nonce') > -1 || name === 'action' || name === '_wp_http_referer') return;
                if (el.type === 'hidden' && name.indexOf('_wcf_') !== 0) return;
                if (el.type === 'password' || el.type === 'file') return;

                var val = '';
                if (el.type === 'checkbox') {
                    val = el.checked ? '1' : '0';
                } else if (el.type === 'radio') {
                    if (el.checked) val = el.value;
                    else return; // skip unchecked radios
                } else {
                    val = el.value || '';
                }

                if (val !== '') {
                    customFields[name] = val;
                }
            });
        });

        // Attach custom fields as JSON string
        if (Object.keys(customFields).length > 0) {
            data.custom_fields = JSON.stringify(customFields);
        }

        return data;
    }

    /**
     * Create a serialized string of form data for change detection
     */
    function serializeForComparison(data) {
        var keys = Object.keys(data).sort();
        var parts = [];
        keys.forEach(function (k) {
            if (k !== 'action' && k !== 'nonce' && k !== 'capture_trigger' && k !== 'capture_url') {
                parts.push(k + '=' + (data[k] || ''));
            }
        });
        return parts.join('&');
    }

    // =========================================================================
    // SEND TO SERVER
    // =========================================================================

    /**
     * Send captured data to server via AJAX
     * Uses admin-ajax.php — NEVER cached by LiteSpeed (even aggressive preset)
     * Includes nonce refresh if page was served from cache (stale nonce)
     */
    function sendCapture(trigger) {
        if (formSubmitting || isSending) {
            return;
        }

        var data = collectFormData();
        data.capture_trigger = trigger || 'field_blur';

        // Always attach browser metadata
        var browser = collectBrowserData();
        Object.keys(browser).forEach(function (k) {
            data[k] = browser[k];
        });

        // Don't send if data hasn't changed (unless it's the initial page_load capture)
        var serialized = serializeForComparison(data);
        if (trigger !== 'page_load' && serialized === lastCapturedData) {
            return;
        }

        isSending = true;
        lastCapturedData = serialized;

        $.ajax({
            url: config.ajaxurl,
            type: 'POST',
            data: data,
            timeout: 10000,
            success: function (response) {
                if (response && response.success && response.data && response.data.draft_id) {
                    draftId = response.data.draft_id;
                    initialCaptureSent = true;
                }
                // If server returned a fresh nonce, use it
                if (response && response.data && response.data.new_nonce) {
                    currentNonce = response.data.new_nonce;
                }
            },
            error: function (xhr) {
                // If 403 (nonce expired — likely cached page), try to refresh nonce
                // Allow retry every 30 seconds (not just once)
                var now = Date.now();
                if (xhr.status === 403 && (now - lastNonceRefreshTime > 30000)) {
                    lastNonceRefreshTime = now;
                    refreshNonce(function () {
                        lastCapturedData = ''; // Allow retry
                        isSending = false;
                        sendCapture(trigger); // Retry with fresh nonce
                    });
                    return;
                }
                // Silent fail — don't disrupt checkout
                lastCapturedData = ''; // Allow retry on next change
            },
            complete: function () {
                isSending = false;
            }
        });
    }

    /**
     * Refresh the nonce via a lightweight AJAX call
     * This is needed when LiteSpeed/Varnish serves a cached checkout page
     * with a stale nonce embedded in the localized script data
     */
    function refreshNonce(callback) {
        $.ajax({
            url: config.ajaxurl,
            type: 'POST',
            data: {
                action: 'guardify_refresh_nonce'
            },
            timeout: 5000,
            success: function (response) {
                if (response && response.success && response.data && response.data.nonce) {
                    currentNonce = response.data.nonce;
                }
                if (typeof callback === 'function') callback();
            },
            error: function () {
                if (typeof callback === 'function') callback();
            }
        });
    }

    /**
     * Send data via navigator.sendBeacon (for page unload events)
     * sendBeacon is fire-and-forget — doesn't block page unload
     */
    function sendBeaconCapture(trigger) {
        if (formSubmitting) {
            return;
        }

        var data = collectFormData();
        data.capture_trigger = trigger || 'before_unload';

        // Always attach browser metadata
        var browser = collectBrowserData();
        Object.keys(browser).forEach(function (k) {
            data[k] = browser[k];
        });

        // Don't send if unchanged
        var serialized = serializeForComparison(data);
        if (serialized === lastCapturedData) {
            return;
        }

        // Use sendBeacon with FormData (survives page unload)
        if (navigator.sendBeacon) {
            var formData = new FormData();
            Object.keys(data).forEach(function (key) {
                formData.append(key, data[key] || '');
            });

            try {
                navigator.sendBeacon(config.ajaxurl, formData);
            } catch (e) {
                // Fallback: synchronous XHR (last resort)
                sendSyncXhr(data);
            }
        } else {
            // Fallback for very old browsers
            sendSyncXhr(data);
        }
    }

    /**
     * Synchronous XHR fallback (used only when sendBeacon is unavailable)
     */
    function sendSyncXhr(data) {
        try {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', config.ajaxurl, false); // false = synchronous
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

            var params = [];
            Object.keys(data).forEach(function (key) {
                params.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key] || ''));
            });
            xhr.send(params.join('&'));
        } catch (e) {
            // Nothing we can do
        }
    }

    // =========================================================================
    // EVENT HANDLERS
    // =========================================================================

    /**
     * Debounced capture — fires after user stops typing/interacting for N ms
     */
    function debouncedCapture(trigger) {
        if (captureTimer) {
            clearTimeout(captureTimer);
        }
        captureTimer = setTimeout(function () {
            sendCapture(trigger);
        }, debounceMs);
    }

    /**
     * Form selector string (WooCommerce, CartFlows, Block Checkout)
     */
    var FORM_SELECTOR = 'form.checkout, form.woocommerce-checkout, .wcf-checkout-form, #wcf-embed-checkout-form, .wc-block-checkout';

    /**
     * Try to find and bind checkout form. Returns true if form was found and bound.
     */
    function tryBindForm() {
        if (formBound) return true;

        var $form = $(FORM_SELECTOR);
        if ($form.length > 0) {
            bindFormEvents($form);
            formBound = true;
            return true;
        }
        return false;
    }

    /**
     * Initialize event listeners
     */
    function initEventListeners() {
        // ─── WARM UP: Send page_load to pre-validate nonce (no order created) ───
        // Server returns immediately without DB write if no form data filled.
        // This warms the nonce and verifies the AJAX endpoint is reachable.
        sendCapture('page_load');

        // ─── Try to bind form Events ───
        if (!tryBindForm()) {
            // Delayed retries — CartFlows / page builders may render the form later
            var retryDelays = [1000, 2000, 4000, 7000];
            retryDelays.forEach(function (delay) {
                setTimeout(function () {
                    tryBindForm();
                }, delay);
            });

            // MutationObserver — watch for dynamically added forms
            if (typeof MutationObserver !== 'undefined') {
                var observer = new MutationObserver(function (mutations) {
                    if (tryBindForm()) {
                        observer.disconnect(); // Stop watching once form is found
                    }
                });
                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });

                // Safety: disconnect observer after 30 seconds regardless
                setTimeout(function () {
                    observer.disconnect();
                }, 30000);
            }
        }

        // ─── Page visibility change (tab switch) ───
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                sendBeaconCapture('page_hide');
            }
        });

        // ─── Page unload (browser close, navigate away) ───
        window.addEventListener('beforeunload', function () {
            if (!formSubmitting) {
                sendBeaconCapture('before_unload');
            }
        });

        // ─── Detect form submission (don't capture during actual submit) ───
        $(document).on('submit', 'form.checkout, form.woocommerce-checkout', function () {
            formSubmitting = true;
        });

        // Also detect the WC AJAX place order
        $(document.body).on('checkout_place_order', function () {
            formSubmitting = true;
        });
    }

    /**
     * Bind events to checkout form fields
     */
    function bindFormEvents($form) {
        // ─── Field blur / change — capture ALL fields (billing, shipping, custom) ───
        $form.on('blur change', 'input, select, textarea', function () {
            var name = this.name || this.id || '';
            if (!name) return;

            // Skip password/file/hidden internals
            if (this.type === 'password' || this.type === 'file') return;
            if (name.indexOf('_wp') === 0 || name.indexOf('nonce') > -1) return;

            // Immediate capture for phone and email (high-value fields)
            if (name === 'billing_phone' || name === 'billing_email') {
                var val = (this.value || '').trim();
                if (name === 'billing_phone') {
                    var digits = val.replace(/\D/g, '');
                    if (digits.length >= 11) {
                        sendCapture('phone_complete');
                        return;
                    }
                }
                if (name === 'billing_email' && val.indexOf('@') > 0 && val.indexOf('.') > val.indexOf('@')) {
                    sendCapture('field_blur');
                    return;
                }
            }

            // Debounced capture for all other fields (including custom like size, color etc.)
            if (config.capture_on_input) {
                debouncedCapture('field_blur');
            }
        });

        // ─── Phone field: capture on input for real-time detection ───
        $form.on('input', '#billing_phone, [name="billing_phone"]', function () {
            var digits = (this.value || '').replace(/\D/g, '');
            if (digits.length >= 11) {
                debouncedCapture('phone_complete');
            }
        });

        // ─── Periodic auto-save every 30 seconds if user is active ───
        var periodicTimer = null;
        var userActive = false;

        $form.on('input focus', 'input, select, textarea', function () {
            userActive = true;
        });

        periodicTimer = setInterval(function () {
            if (userActive && !formSubmitting) {
                sendCapture('periodic');
                userActive = false;
            }
        }, 30000); // 30 seconds

        // Clear periodic timer on form submit
        $(document).on('submit', 'form.checkout, form.woocommerce-checkout', function () {
            if (periodicTimer) clearInterval(periodicTimer);
        });
    }

    // =========================================================================
    // INIT
    // =========================================================================

    // Wait for DOM ready
    $(function () {
        // Don't run on order-received (thank you) page
        if ($('.woocommerce-order-received').length > 0 || window.location.href.indexOf('order-received') > -1) {
            return;
        }

        initEventListeners();
    });

})(jQuery);
