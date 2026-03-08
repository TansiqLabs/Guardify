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

    // Initialize global flags for form submission prevention integration
    window.guardifyPhoneBlocked = false;
    window.guardifyCooldownBlocked = false;

    /**
     * Set blocked state globally
     */
    function setBlockedState(blocked) {
        isBlocked = blocked;
        window.guardifyPhoneBlocked = blocked;
        window.guardifyCooldownBlocked = blocked;
        
        // Update place order button state
        var $placeOrderBtn = $('#place_order, .wcf-btn-place-order, .wc-block-components-checkout-place-order-button');
        if (blocked) {
            $placeOrderBtn.addClass('guardify-blocked').prop('disabled', true);
        } else {
            $placeOrderBtn.removeClass('guardify-blocked').prop('disabled', false);
        }
    }

    /**
     * Show blocked popup
     */
    function showBlockedPopup(message) {
        // Remove any existing popup first
        $('.guardify-blocked-popup').remove();
        
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
                    setBlockedState(true);
                    showBlockedPopup(response.data.message);
                } else {
                    // Reset blocked state when check passes
                    setBlockedState(false);
                }
            },
            error: function() {
                // On AJAX error, don't block the order
                // Let server-side validation handle it
                setBlockedState(false);
            }
        });
    }

    /**
     * Initialize
     */
    $(document).ready(function() {
        // Only proceed if at least one cooldown is enabled
        if (!settings.phoneCooldownEnabled && !settings.ipCooldownEnabled) {
            return;
        }

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
                } else {
                    // Reset blocked state if phone is too short (user is editing)
                    setBlockedState(false);
                }
            });

            $(document).on('blur', '#billing_phone', function() {
                var phone = $(this).val();
                if (phone.length >= 11) {
                    checkCooldown(phone);
                }
            });

            // Also check on page load if phone field already has a value
            var existingPhone = $('#billing_phone').val();
            if (existingPhone && existingPhone.length >= 11) {
                checkCooldown(existingPhone);
            }
        }

        // Prevent form submission if blocked - HARD BLOCK at JS level
        $('form.checkout, form.woocommerce-checkout, .wcf-checkout-form').on('checkout_place_order submit', function(e) {
            if (isBlocked) {
                e.preventDefault();
                e.stopImmediatePropagation();
                var message = settings.phoneCooldownMessage || settings.ipCooldownMessage;
                showBlockedPopup(message);
                return false;
            }
            return true;
        });

        // Block place order button clicks
        $(document).on('click', '#place_order, .wcf-btn-place-order, .wcf-checkout-place-order, .wc-block-components-checkout-place-order-button', function(e) {
            if (isBlocked) {
                e.preventDefault();
                e.stopImmediatePropagation();
                var message = settings.phoneCooldownMessage || settings.ipCooldownMessage;
                showBlockedPopup(message);
                return false;
            }
        });
    });

})(jQuery);
