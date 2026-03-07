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

    // ==========================================
    // AUTO-LOAD GLOBAL COURIER DATA (Courier Report Column)
    // Runs after page paint — no manual button needed
    // ==========================================
    function updateCourierWrap($wrap, c) {
        // Replace loading state with stats
        $wrap.find('.guardify-courier-loading').remove();
        
        var statsHtml = '<div class="guardify-courier-stats">' +
            '<span class="guardify-courier-item guardify-courier-total">' +
                '<span class="courier-value">' + (c.totalParcels || 0) + '</span>' +
                '<span class="courier-label">📦 PARCELS</span>' +
            '</span>' +
            '<span class="guardify-courier-item guardify-courier-delivered">' +
                '<span class="courier-value">' + (c.totalDelivered || 0) + '</span>' +
                '<span class="courier-label">✅ DELIVERED</span>' +
            '</span>' +
            '<span class="guardify-courier-item guardify-courier-cancelled">' +
                '<span class="courier-value">' + (c.totalCancelled || 0) + '</span>' +
                '<span class="courier-label">❌ CANCELLED</span>' +
            '</span>' +
            '<span class="guardify-courier-item guardify-courier-success ' + getCourierSuccessClass(c.successRate) + '">' +
                '<span class="courier-value">' + (c.successRate || 0) + '%</span>' +
                '<span class="courier-label">🌐 SUCCESS</span>' +
            '</span>' +
        '</div>';
        
        // Insert stats before the fraud check link
        $wrap.find('.guardify-courier-link').before(statsHtml);
        $wrap.removeAttr('data-needs-courier');
    }

    function getCourierSuccessClass(rate) {
        if (rate >= 80) return 'courier-success-high';
        if (rate >= 50) return 'courier-success-medium';
        return 'courier-success-low';
    }

    // Auto-fetch after page paint: collect all phones needing courier data, batch fetch
    setTimeout(function() {
        var phones = {};
        $('.guardify-courier-wrap[data-needs-courier="1"]').each(function() {
            var phone = $(this).attr('data-phone');
            if (phone) phones[phone] = true;
        });

        var phoneList = Object.keys(phones);
        if (phoneList.length === 0) return;

        console.log('Guardify: Auto-fetching courier data for', phoneList.length, 'phone(s)');

        $.post(guardifyOrderColumns.ajax_url, {
            action: 'guardify_batch_refresh_courier',
            nonce: guardifyOrderColumns.fraud_nonce,
            phones: phoneList
        }, function(response) {
            if (response.success && response.data) {
                $.each(response.data, function(phone, courierData) {
                    if (!courierData || !courierData.totalParcels) return;
                    // Update ALL Courier Report columns with this phone number
                    $('.guardify-courier-wrap[data-phone="' + phone + '"]').each(function() {
                        updateCourierWrap($(this), courierData);
                    });
                });
                console.log('Guardify: Courier data loaded for', Object.keys(response.data).length, 'phone(s)');
            }
        }).fail(function() {
            console.warn('Guardify: Failed to fetch courier data');
        });
    }, 800); // 800ms delay ensures page is fully painted first

