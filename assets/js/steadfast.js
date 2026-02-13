/**
 * Guardify SteadFast Integration Scripts
 */

(function($) {
    'use strict';

    // Check if settings are available
    if (typeof guardifySteadfast === 'undefined') {
        return;
    }

    var settings = guardifySteadfast;
    var i18n = settings.i18n || {};

    /**
     * Send order to SteadFast
     */
    $(document).on('click', '.guardify-sf-send', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var orderId = $button.data('order-id');
        
        if (!orderId || $button.prop('disabled') || $button.hasClass('loading')) {
            return;
        }
        
        // Add loading state
        $button.addClass('loading').prop('disabled', true);
        var originalText = $button.text();
        $button.text(i18n.sending || 'পাঠানো হচ্ছে...');
        
        $.ajax({
            type: 'POST',
            url: settings.ajaxurl,
            data: {
                action: 'guardify_steadfast_send',
                nonce: settings.nonce,
                order_id: orderId
            },
            success: function(response) {
                $button.removeClass('loading');
                
                if (response.success) {
                    $button
                        .removeClass('guardify-sf-send')
                        .addClass('guardify-sf-sent')
                        .text(i18n.sent || '✓ পাঠানো হয়েছে');
                    
                    // Show success animation
                    $button.css('transform', 'scale(1.1)');
                    setTimeout(function() {
                        $button.css('transform', 'scale(1)');
                    }, 200);
                    
                    // Reload after 1.5 seconds to show consignment ID
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    var message = response.data && response.data.message ? response.data.message : 'Error';
                    
                    if (message.toLowerCase().includes('unauthorized')) {
                        $button.addClass('guardify-sf-unauthorized').text(i18n.unauthorized || 'অননুমোদিত');
                    } else {
                        $button
                            .addClass('guardify-sf-failed')
                            .text(i18n.failed || 'ব্যর্থ!')
                            .attr('title', message);
                    }
                    $button.prop('disabled', false);
                }
            },
            error: function() {
                $button
                    .removeClass('loading')
                    .addClass('guardify-sf-failed')
                    .text(i18n.failed || 'ব্যর্থ!')
                    .prop('disabled', false);
            }
        });
    });

    /**
     * Update COD amount
     */
    $(document).on('blur', '.guardify-sf-amount', function() {
        var $input = $(this);
        var orderId = $input.data('order-id');
        var amount = $input.val();
        
        if (!orderId || $input.prop('disabled')) {
            return;
        }
        
        $.ajax({
            type: 'POST',
            url: settings.ajaxurl,
            data: {
                action: 'guardify_steadfast_amount',
                nonce: settings.nonce,
                order_id: orderId,
                amount: amount
            },
            success: function(response) {
                if (response.success) {
                    $input.css('border-color', '#5b841b');
                    setTimeout(function() {
                        $input.css('border-color', '');
                    }, 2000);
                }
            }
        });
    });

    /**
     * Check balance (Dashboard)
     */
    $(document).on('click', '.guardify-sf-balance-check', function() {
        var $button = $(this);
        var $display = $button.siblings('.guardify-sf-balance-display');
        
        $button.prop('disabled', true).text(i18n.checking || 'Checking...');
        
        $.ajax({
            type: 'POST',
            url: settings.ajaxurl,
            data: {
                action: 'guardify_steadfast_balance',
                nonce: settings.nonce
            },
            success: function(response) {
                if (response.success && response.data.balance !== undefined) {
                    $display.html('<strong>' + response.data.balance + ' TK</strong>').show();
                    $button.text(i18n.success || 'Balance');
                } else {
                    $button.text(i18n.failed || 'Failed').addClass('guardify-sf-failed');
                }
            },
            error: function() {
                $button.text(i18n.failed || 'Failed').addClass('guardify-sf-failed');
            }
        });
    });

    /**
     * Check delivery status
     */
    $(document).on('click', '.guardify-sf-check-status, .guardify-sf-refresh-status', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var orderId = $button.data('order-id');
        var consignmentId = $button.data('consignment-id');
        var $statusSpan = $button.siblings('.guardify-sf-status');
        
        if (!orderId || !consignmentId) {
            return;
        }
        
        // Show checking state
        if ($button.hasClass('guardify-sf-check-status')) {
            $button.text(i18n.checking || 'Checking...');
        } else {
            $statusSpan.text(i18n.checking || 'Checking...');
        }
        
        $.ajax({
            type: 'POST',
            url: settings.ajaxurl,
            data: {
                action: 'guardify_steadfast_status',
                nonce: settings.nonce,
                order_id: orderId,
                consignment_id: consignmentId
            },
            success: function(response) {
                if (response.success && response.data.status) {
                    var status = response.data.status;
                    var displayStatus = status.replace(/_/g, ' ');
                    displayStatus = displayStatus.charAt(0).toUpperCase() + displayStatus.slice(1);
                    
                    // Update status display
                    $statusSpan
                        .text(displayStatus)
                        .removeClass()
                        .addClass('guardify-sf-status guardify-sf-status-' + status.replace(/_/g, '-'));
                    
                    // Show refresh button, hide check button
                    if ($button.hasClass('guardify-sf-check-status')) {
                        $button.hide();
                        $button.siblings('.guardify-sf-refresh-status').show();
                    }
                } else {
                    $statusSpan.text(i18n.failed || 'Failed');
                }
            },
            error: function() {
                $statusSpan.text(i18n.failed || 'Failed');
            }
        });
    });

    /**
     * Bulk Refresh All Statuses
     */
    $(document).on('click', '.guardify-sf-bulk-refresh', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        if ($button.prop('disabled') || $button.hasClass('loading')) {
            return;
        }
        
        $button.addClass('loading').prop('disabled', true);
        var originalText = $button.html();
        $button.html('<span class="dashicons dashicons-update spinning"></span> Refreshing...');
        
        $.ajax({
            type: 'POST',
            url: settings.ajaxurl,
            data: {
                action: 'guardify_steadfast_bulk_status',
                nonce: settings.nonce
            },
            success: function(response) {
                $button.removeClass('loading').prop('disabled', false);
                
                if (response.success) {
                    var msg = response.data.message || 'Done';
                    $button.html('<span class="dashicons dashicons-yes"></span> ' + msg);
                    
                    if (response.data.updated > 0) {
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        setTimeout(function() {
                            $button.html(originalText);
                        }, 3000);
                    }
                } else {
                    $button.html(originalText);
                    alert(response.data && response.data.message ? response.data.message : 'Error');
                }
            },
            error: function() {
                $button.removeClass('loading').prop('disabled', false).html(originalText);
                alert('Network error.');
            }
        });
    });

    /**
     * Return Request
     */
    $(document).on('click', '.guardify-sf-return-request', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var orderId = $button.data('order-id');
        
        if (!orderId || $button.prop('disabled')) return;
        
        var reason = prompt('Enter reason for return (optional):');
        if (reason === null) return;
        
        $button.prop('disabled', true).text('Sending...');
        
        $.ajax({
            type: 'POST',
            url: settings.ajaxurl,
            data: {
                action: 'guardify_steadfast_return_request',
                nonce: settings.nonce,
                order_id: orderId,
                reason: reason
            },
            success: function(response) {
                if (response.success) {
                    $button.text('✓ Return Requested').addClass('guardify-sf-return-requested').prop('disabled', true);
                } else {
                    alert(response.data && response.data.message ? response.data.message : 'Error');
                    $button.prop('disabled', false).text('Return Request');
                }
            },
            error: function() {
                alert('Network error.');
                $button.prop('disabled', false).text('Return Request');
            }
        });
    });

    /**
     * Settings page: Balance check (API creds come from TansiqLabs console)
     */
    $(document).ready(function() {
        // Settings page balance check
        $('#guardify-check-sf-balance').on('click', function() {
            var $button = $(this);
            var $result = $('#guardify-sf-balance-result');
            
            $button.prop('disabled', true);
            $result.html('<span style="color: #666;">' + (i18n.checking || 'Checking...') + '</span>');
            
            $.ajax({
                type: 'POST',
                url: settings.ajaxurl,
                data: {
                    action: 'guardify_steadfast_balance',
                    nonce: settings.nonce
                },
                success: function(response) {
                    if (response.success && response.data.balance !== undefined) {
                        $result.html('<span style="color: #5b841b; font-weight: 600;">৳ ' + response.data.balance + '</span>');
                    } else {
                        var errorMsg = response.data && response.data.message ? response.data.message : 'Error checking balance';
                        $result.html('<span style="color: #dc3545;">' + errorMsg + '</span>');
                    }
                    $button.prop('disabled', false);
                },
                error: function() {
                    $result.html('<span style="color: #dc3545;">' + (i18n.failed || 'Error') + '</span>');
                    $button.prop('disabled', false);
                }
            });
        });
    });

})(jQuery);
