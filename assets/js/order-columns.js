/**
 * Guardify Order Columns JavaScript
 * Handle block/unblock AJAX actions for WooCommerce Orders page
 * @version 2.4.1
 */
jQuery(document).ready(function($) {
    'use strict';
    
    // Check if localized variables exist
    if (typeof guardifyOrderColumns === 'undefined') {
        console.error('Guardify: guardifyOrderColumns not defined');
        return;
    }
    
    console.log('Guardify Order Columns JS loaded successfully');
    var i18n = guardifyOrderColumns.strings || {};

    // ==========================================
    // BLOCK PHONE
    // ==========================================
    $(document).on('click', '.guardify-btn-block-phone', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var $btn = $(this);
        if ($btn.hasClass('loading')) return false;
        
        // Get phone from data attribute
        var phone = $btn.attr('data-phone') || '';
        
        console.log('Guardify: Block Phone clicked');
        console.log('Guardify: Phone value:', phone);
        
        if (!phone) {
            alert('Error: Phone number not found on this order.');
            return false;
        }
        
        if (!confirm((i18n.confirm_block || 'Are you sure you want to block this %s?').replace('%s', phone))) {
            return false;
        }
        
        // Send AJAX
        $btn.addClass('loading').text(i18n.blocking || 'Blocking...');
        
        $.post(guardifyOrderColumns.ajax_url, {
            action: 'guardify_block_phone',
            nonce: guardifyOrderColumns.nonce,
            phone: phone
        }, function(response) {
            if (response.success) {
                $btn.removeClass('loading guardify-btn-block guardify-btn-block-phone')
                    .addClass('guardify-btn-blocked guardify-btn-phone-blocked')
                    .text(i18n.phone_blocked || 'Phone Blocked');
            } else {
                alert(response.data && response.data.message ? response.data.message : (i18n.error || 'Error occurred'));
                $btn.removeClass('loading').text(i18n.block_phone || 'Block Phone');
            }
        }).fail(function() {
            alert(i18n.error || 'Network error. Please try again.');
            $btn.removeClass('loading').text(i18n.block_phone || 'Block Phone');
        });
        
        return false;
    });

    // ==========================================
    // UNBLOCK PHONE
    // ==========================================
    $(document).on('click', '.guardify-btn-phone-blocked', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var $btn = $(this);
        if ($btn.hasClass('loading')) return false;
        
        var phone = $btn.attr('data-phone') || '';
        
        if (!confirm('Are you sure you want to unblock this phone: ' + phone + '?')) {
            return false;
        }
        
        $btn.addClass('loading').text(i18n.unblocking || 'Unblocking...');
        
        $.post(guardifyOrderColumns.ajax_url, {
            action: 'guardify_unblock_phone',
            nonce: guardifyOrderColumns.nonce,
            phone: phone
        }, function(response) {
            if (response.success) {
                $btn.removeClass('loading guardify-btn-blocked guardify-btn-phone-blocked')
                    .addClass('guardify-btn-block guardify-btn-block-phone')
                    .text(i18n.block_phone || 'Block Phone');
            } else {
                alert(response.data && response.data.message ? response.data.message : (i18n.error || 'Error occurred'));
                $btn.removeClass('loading').text(i18n.phone_blocked || 'Phone Blocked');
            }
        }).fail(function() {
            alert(i18n.error || 'Network error. Please try again.');
            $btn.removeClass('loading').text(i18n.phone_blocked || 'Phone Blocked');
        });
        
        return false;
    });

    // ==========================================
    // BLOCK IP
    // ==========================================
    $(document).on('click', '.guardify-btn-block-ip', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var $btn = $(this);
        if ($btn.hasClass('loading')) return false;
        
        var ip = $btn.attr('data-ip') || '';
        
        console.log('Guardify: Block IP clicked');
        console.log('Guardify: IP value:', ip);
        
        if (!ip) {
            alert('Error: IP address not found on this order.');
            return false;
        }
        
        if (!confirm((i18n.confirm_block || 'Are you sure you want to block this %s?').replace('%s', ip))) {
            return false;
        }
        
        $btn.addClass('loading').text(i18n.blocking || 'Blocking...');
        
        $.post(guardifyOrderColumns.ajax_url, {
            action: 'guardify_block_ip',
            nonce: guardifyOrderColumns.nonce,
            ip: ip
        }, function(response) {
            if (response.success) {
                $btn.removeClass('loading guardify-btn-block guardify-btn-block-ip')
                    .addClass('guardify-btn-blocked guardify-btn-ip-blocked')
                    .text(i18n.ip_blocked || 'IP Blocked');
            } else {
                alert(response.data && response.data.message ? response.data.message : (i18n.error || 'Error occurred'));
                $btn.removeClass('loading').text(i18n.block_ip || 'Block IP');
            }
        }).fail(function() {
            alert(i18n.error || 'Network error. Please try again.');
            $btn.removeClass('loading').text(i18n.block_ip || 'Block IP');
        });
        
        return false;
    });

    // ==========================================
    // UNBLOCK IP
    // ==========================================
    $(document).on('click', '.guardify-btn-ip-blocked', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var $btn = $(this);
        if ($btn.hasClass('loading')) return false;
        
        var ip = $btn.attr('data-ip') || '';
        
        if (!confirm((i18n.confirm_unblock || 'Are you sure you want to unblock this %s?').replace('%s', ip))) {
            return false;
        }
        
        $btn.addClass('loading').text(i18n.unblocking || 'Unblocking...');
        
        $.post(guardifyOrderColumns.ajax_url, {
            action: 'guardify_unblock_ip',
            nonce: guardifyOrderColumns.nonce,
            ip: ip
        }, function(response) {
            if (response.success) {
                $btn.removeClass('loading guardify-btn-blocked guardify-btn-ip-blocked')
                    .addClass('guardify-btn-block guardify-btn-block-ip')
                    .text(i18n.block_ip || 'Block IP');
            } else {
                alert(response.data && response.data.message ? response.data.message : (i18n.error || 'Error occurred'));
                $btn.removeClass('loading').text(i18n.ip_blocked || 'IP Blocked');
            }
        }).fail(function() {
            alert(i18n.error || 'Network error. Please try again.');
            $btn.removeClass('loading').text(i18n.ip_blocked || 'IP Blocked');
        });
        
        return false;
    });

    // ==========================================
    // BLOCK DEVICE
    // ==========================================
    $(document).on('click', '.guardify-btn-block-device', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var $btn = $(this);
        if ($btn.hasClass('loading')) return false;
        
        var orderId = $btn.attr('data-order-id') || '';
        
        console.log('Guardify: Block Device clicked');
        console.log('Guardify: Order ID:', orderId);
        
        if (!orderId) {
            alert('Error: Order ID not found.');
            return false;
        }
        
        if (!confirm(i18n.confirm_block ? (i18n.confirm_block.replace('%s', 'device')) : 'Are you sure you want to block this device?')) {
            return false;
        }
        
        $btn.addClass('loading').text(i18n.blocking || 'Blocking...');
        
        $.post(guardifyOrderColumns.ajax_url, {
            action: 'guardify_block_device',
            nonce: guardifyOrderColumns.nonce,
            order_id: orderId
        }, function(response) {
            if (response.success) {
                $btn.removeClass('loading guardify-btn-block guardify-btn-block-device')
                    .addClass('guardify-btn-blocked guardify-btn-device-blocked')
                    .text(i18n.device_blocked || 'Device Blocked');
                
                // Store device ID for unblocking
                if (response.data && response.data.device_id) {
                    $btn.attr('data-device', response.data.device_id);
                }
            } else {
                alert(response.data && response.data.message ? response.data.message : (i18n.error || 'Error occurred'));
                $btn.removeClass('loading').text(i18n.block_device || 'Block Device');
            }
        }).fail(function() {
            alert(i18n.error || 'Network error. Please try again.');
            $btn.removeClass('loading').text(i18n.block_device || 'Block Device');
        });
        
        return false;
    });

    // ==========================================
    // BLOCK DEVICE DIRECT (with device_id already provided)
    // ==========================================
    $(document).on('click', '.guardify-btn-block-device-direct', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var $btn = $(this);
        if ($btn.hasClass('loading')) return false;
        
        var deviceId = $btn.attr('data-device') || '';
        
        console.log('Guardify: Block Device Direct clicked');
        console.log('Guardify: Device ID:', deviceId);
        
        if (!deviceId) {
            alert('Error: Device ID not found.');
            return false;
        }
        
        if (!confirm(i18n.confirm_block ? (i18n.confirm_block.replace('%s', 'device')) : 'Are you sure you want to block this device?')) {
            return false;
        }
        
        $btn.addClass('loading').text(i18n.blocking || 'Blocking...');
        
        $.post(guardifyOrderColumns.ajax_url, {
            action: 'guardify_block_device_direct',
            nonce: guardifyOrderColumns.nonce,
            device: deviceId
        }, function(response) {
            if (response.success) {
                $btn.removeClass('loading guardify-btn-block guardify-btn-block-device-direct')
                    .addClass('guardify-btn-blocked guardify-btn-device-blocked')
                    .text(i18n.device_blocked || 'Device Blocked');
            } else {
                alert(response.data && response.data.message ? response.data.message : (i18n.error || 'Error occurred'));
                $btn.removeClass('loading').text(i18n.block_device || 'Block Device');
            }
        }).fail(function() {
            alert(i18n.error || 'Network error. Please try again.');
            $btn.removeClass('loading').text(i18n.block_device || 'Block Device');
        });
        
        return false;
    });

    // ==========================================
    // UNBLOCK DEVICE
    // ==========================================
    $(document).on('click', '.guardify-btn-device-blocked', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var $btn = $(this);
        if ($btn.hasClass('loading')) return false;
        
        var device = $btn.attr('data-device') || '';
        var orderId = $btn.attr('data-order-id') || '';
        
        if (!confirm(i18n.confirm_unblock ? (i18n.confirm_unblock.replace('%s', 'device')) : 'Are you sure you want to unblock this device?')) {
            return false;
        }
        
        $btn.addClass('loading').text(i18n.unblocking || 'Unblocking...');
        
        $.post(guardifyOrderColumns.ajax_url, {
            action: 'guardify_unblock_device',
            nonce: guardifyOrderColumns.nonce,
            device: device,
            order_id: orderId
        }, function(response) {
            if (response.success) {
                // Check if it was a direct device block (no order_id)
                if (!orderId) {
                    $btn.removeClass('loading guardify-btn-blocked guardify-btn-device-blocked')
                        .addClass('guardify-btn-block guardify-btn-block-device-direct')
                        .text(i18n.block_device || 'Block Device');
                } else {
                    $btn.removeClass('loading guardify-btn-blocked guardify-btn-device-blocked')
                        .addClass('guardify-btn-block guardify-btn-block-device')
                        .text(i18n.block_device || 'Block Device');
                }
            } else {
                alert(response.data && response.data.message ? response.data.message : (i18n.error || 'Error occurred'));
                $btn.removeClass('loading').text(i18n.device_blocked || 'Device Blocked');
            }
        }).fail(function() {
            alert(i18n.error || 'Network error. Please try again.');
            $btn.removeClass('loading').text(i18n.device_blocked || 'Device Blocked');
        });
        
        return false;
    });

});
