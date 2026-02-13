/**
 * Guardify Phone Validation JavaScript
 */

(function($) {
    'use strict';

    // Check if settings are available
    if (typeof guardifyPhoneValidation === 'undefined') {
        return;
    }

    var settings = guardifyPhoneValidation;
    var validationTimeout;

    /**
     * Validate Bangladeshi phone number
     */
    function validateBDPhone(phone) {
        if (!phone) {
            return false;
        }

        // Remove spaces, dashes, and parentheses
        phone = phone.replace(/[\s\-\(\)]+/g, '');

        // Valid Bangladeshi mobile number patterns
        // Local format: 01XXXXXXXXX (11 digits starting with 01, third digit 3-9)
        if (/^01[3-9]\d{8}$/.test(phone)) {
            return true;
        }

        // International format with +880
        if (/^\+8801[3-9]\d{8}$/.test(phone)) {
            return true;
        }

        // International format without +
        if (/^8801[3-9]\d{8}$/.test(phone)) {
            return true;
        }

        return false;
    }

    /**
     * Show validation message
     */
    function showValidationMessage(field, message, type) {
        var messageDiv = field.next('.guardify-validation-message');
        
        if (messageDiv.length === 0) {
            messageDiv = $('<div class="guardify-validation-message"></div>');
            field.after(messageDiv);
        }

        messageDiv.removeClass('error success').addClass(type);
        messageDiv.text(message);
    }

    /**
     * Hide validation message
     */
    function hideValidationMessage(field) {
        field.next('.guardify-validation-message').removeClass('error success');
    }

    /**
     * Validate phone field
     */
    function validatePhoneField() {
        var $field = $('#billing_phone');
        var phone = $field.val();

        if (!phone) {
            $field.removeClass('guardify-valid guardify-invalid');
            hideValidationMessage($field);
            return;
        }

        if (validateBDPhone(phone)) {
            $field.removeClass('guardify-invalid').addClass('guardify-valid');
            showValidationMessage($field, '✓ সঠিক বাংলাদেশি নাম্বার', 'success');
        } else {
            $field.removeClass('guardify-valid').addClass('guardify-invalid');
            showValidationMessage($field, settings.message, 'error');
        }
    }

    /**
     * Initialize
     */
    $(document).ready(function() {
        // PHP passes boolean true/false which JS receives as true/false
        if (!settings.enabled) {
            return;
        }

        // Add validation on phone field input
        $(document).on('input', '#billing_phone', function() {
            clearTimeout(validationTimeout);
            // Increased delay to 800ms to prevent shake while typing
            validationTimeout = setTimeout(validatePhoneField, 800);
        });

        // Add validation on phone field blur
        $(document).on('blur', '#billing_phone', function() {
            validatePhoneField();
        });

        // Prevent form submission if phone is invalid
        // IMPORTANT: Only show warning, don't block - let server-side validation handle it
        // Support for standard WooCommerce and Cartflows checkout forms
        $('form.checkout, form.woocommerce-checkout, .wcf-checkout-form').on('checkout_place_order submit', function() {
            var phone = $('#billing_phone').val();
            
            // If phone is empty, let WooCommerce handle required field validation
            if (!phone || phone.trim() === '') {
                return true;
            }
            
            if (!validateBDPhone(phone)) {
                // Show warning message but DON'T block submission
                // Server-side validation will catch this
                // This prevents legitimate orders from being blocked by JS errors
                var $form = $(this);
                var $noticeGroup = $form.find('.woocommerce-NoticeGroup-checkout');
                
                if ($noticeGroup.length === 0) {
                    $form.prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"></div>');
                    $noticeGroup = $form.find('.woocommerce-NoticeGroup-checkout');
                }
                
                $noticeGroup.html(
                    '<ul class="woocommerce-error guardify-phone-error" role="alert"><li>' + settings.message + '</li></ul>'
                );
                
                // Scroll to error
                $('html, body').animate({
                    scrollTop: $noticeGroup.offset().top - 100
                }, 500);
                
                // Return true anyway - let server decide
                // This prevents JS errors from blocking orders
                return true;
            }
            
            return true;
        });

        // WooCommerce checkout update event
        $(document.body).on('updated_checkout wcf_checkout_updated', function() {
            var phone = $('#billing_phone').val();
            if (phone) {
                validatePhoneField();
            }
        });
    });

})(jQuery);
