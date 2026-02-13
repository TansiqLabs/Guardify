/**
 * Guardify Order Cooldown JavaScript
 */

(function($) {
    'use strict';

    // Check if settings are available
    if (typeof guardifyOrderCooldown === 'undefined') {
        return;
    }

    var settings = guardifyOrderCooldown;
    var isBlocked = false;
    var checkTimeout;

    /**
     * Show blocked popup
     */
    function showBlockedPopup(message) {
        var contactNumber = settings.contactNumber || '';
        var contactButton = '';
        
        if (contactNumber) {
            contactButton = '<a href="tel:' + contactNumber + '" class="guardify-contact-btn">' +
                '<span class="dashicons dashicons-phone"></span> ' +
                'যোগাযোগ করুন: ' + contactNumber +
                '</a>';
        }
        
        var popup = $('<div class="guardify-blocked-popup">' +
            '<div class="guardify-blocked-content">' +
            '<div class="guardify-blocked-icon">⚠️</div>' +
            '<h3>অর্ডার ব্লক করা হয়েছে</h3>' +
            '<p>' + message + '</p>' +
            contactButton +
            '<button type="button" class="guardify-blocked-close">ঠিক আছে</button>' +
            '</div>' +
            '</div>');

        $('body').append(popup);

        popup.on('click', '.guardify-blocked-close', function() {
            popup.fadeOut(300, function() {
                popup.remove();
            });
        });

        popup.on('click', function(e) {
            if (e.target === this) {
                popup.fadeOut(300, function() {
                    popup.remove();
                });
            }
        });
    }

    /**
     * Check cooldown via AJAX
     */
    function checkCooldown(phone) {
        $.ajax({
            url: settings.ajaxurl,
            type: 'POST',
            data: {
                action: 'guardify_check_cooldown',
                nonce: settings.nonce,
                phone: phone
            },
            success: function(response) {
                if (!response.success && response.data && response.data.blocked) {
                    isBlocked = true;
                    showBlockedPopup(response.data.message);
                } else {
                    // IMPORTANT: Reset blocked state when check passes
                    isBlocked = false;
                }
            },
            error: function() {
                // IMPORTANT: On AJAX error, don't block the order
                // Let server-side validation handle it
                isBlocked = false;
            }
        });
    }

    /**
     * Initialize
     */
    $(document).ready(function() {
        // Only proceed if at least one cooldown is enabled
        // PHP passes boolean true/false which JS receives as true/false
        if (!settings.phoneCooldownEnabled && !settings.ipCooldownEnabled) {
            return;
        }

        // DO NOT check IP cooldown on page load - this slows down first load
        // IP will be checked server-side during checkout
        // Only check when user submits or gives phone number

        // Check phone cooldown on input
        if (settings.phoneCooldownEnabled) {
            $(document).on('input', '#billing_phone', function() {
                var phone = $(this).val();
                
                clearTimeout(checkTimeout);
                
                // Only check if phone has minimum length
                if (phone.length >= 11) {
                    checkTimeout = setTimeout(function() {
                        checkCooldown(phone);
                    }, 500);
                }
            });

            $(document).on('blur', '#billing_phone', function() {
                var phone = $(this).val();
                if (phone.length >= 11) {
                    checkCooldown(phone);
                }
            });
        }

        // Prevent form submission if blocked
        // IMPORTANT: Only show warning, don't hard block - server-side handles final validation
        $('form.checkout, form.woocommerce-checkout, .wcf-checkout-form').on('checkout_place_order submit', function(e) {
            if (isBlocked) {
                // Show popup but let form submit anyway
                // Server-side will catch if truly blocked
                var message = settings.phoneCooldownMessage || settings.ipCooldownMessage;
                showBlockedPopup(message);
                
                // Return true - let server decide final outcome
                // This prevents AJAX timing issues from blocking legitimate orders
                return true;
            }
            return true;
        });

        // Remove hard block on place order button
        // Server-side validation is the source of truth
        $(document).on('click', '#place_order, .wcf-checkout-place-order', function(e) {
            // Don't prevent default - let the form submit
            // Server-side validation will handle blocking if needed
            if (isBlocked) {
                var message = settings.phoneCooldownMessage || settings.ipCooldownMessage;
                showBlockedPopup(message);
                // Don't return false - let it proceed
            }
        });
    });

})(jQuery);
