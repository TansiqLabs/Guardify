/**
 * Guardify Pathao Courier Integration Scripts
 */

(function($) {
    'use strict';

    if (typeof guardifyPathao === 'undefined') {
        return;
    }

    var settings = guardifyPathao;
    var i18n = settings.i18n || {};

    // ─── Modal Management ───────────────────────────────────

    var $modal = null;
    var storesLoaded = false;
    var citiesLoaded = false;

    function openModal(orderId) {
        $modal = $('#guardify-pathao-modal');
        if (!$modal.length) return;

        $('#guardify-pt-modal-order-id').val(orderId);
        $modal.fadeIn(200);

        // Load stores and cities if not already loaded
        if (!storesLoaded) loadStores();
        if (!citiesLoaded) loadCities();

        // Reset zone and area
        $('#guardify-pt-zone').html('<option value="">' + (i18n.selectZone || 'Select Zone') + '</option>').prop('disabled', true);
        $('#guardify-pt-area').html('<option value="">' + (i18n.selectArea || 'Select Area') + '</option>').prop('disabled', true);
    }

    function closeModal() {
        if ($modal) {
            $modal.fadeOut(200);
        }
    }

    // Close modal events
    $(document).on('click', '.guardify-pt-modal-close, .guardify-pt-modal-overlay, #guardify-pt-modal-cancel', function(e) {
        e.preventDefault();
        closeModal();
    });

    $(document).on('keyup', function(e) {
        if (e.key === 'Escape') closeModal();
    });

    // ─── Load Locations ─────────────────────────────────────

    function loadStores() {
        var $select = $('#guardify-pt-store');
        $select.html('<option value="">' + (i18n.loading || 'Loading...') + '</option>');

        $.ajax({
            type: 'POST',
            url: settings.ajaxurl,
            data: {
                action: 'guardify_pathao_stores',
                nonce: settings.nonce
            },
            success: function(response) {
                storesLoaded = true;
                $select.html('<option value="">' + (i18n.selectStore || 'Select Store') + '</option>');

                if (response.success && response.data.stores) {
                    $.each(response.data.stores, function(i, store) {
                        var selected = (settings.defaultStore && settings.defaultStore == store.store_id) ? ' selected' : '';
                        $select.append('<option value="' + store.store_id + '"' + selected + '>' + store.store_name + '</option>');
                    });
                }
            },
            error: function() {
                $select.html('<option value="">Error loading stores</option>');
            }
        });
    }

    function loadCities() {
        var $select = $('#guardify-pt-city');
        $select.html('<option value="">' + (i18n.loading || 'Loading...') + '</option>');

        $.ajax({
            type: 'POST',
            url: settings.ajaxurl,
            data: {
                action: 'guardify_pathao_locations',
                nonce: settings.nonce,
                type: 'cities'
            },
            success: function(response) {
                citiesLoaded = true;
                $select.html('<option value="">' + (i18n.selectCity || 'Select City') + '</option>');

                if (response.success && response.data.locations) {
                    $.each(response.data.locations, function(i, city) {
                        $select.append('<option value="' + city.city_id + '">' + city.city_name + '</option>');
                    });
                }
            },
            error: function() {
                $select.html('<option value="">Error loading cities</option>');
            }
        });
    }

    // City → Zone cascade
    $(document).on('change', '#guardify-pt-city', function() {
        var cityId = $(this).val();
        var $zoneSelect = $('#guardify-pt-zone');
        var $areaSelect = $('#guardify-pt-area');

        $areaSelect.html('<option value="">' + (i18n.selectArea || 'Select Area') + '</option>').prop('disabled', true);

        if (!cityId) {
            $zoneSelect.html('<option value="">' + (i18n.selectZone || 'Select Zone') + '</option>').prop('disabled', true);
            return;
        }

        $zoneSelect.html('<option value="">' + (i18n.loading || 'Loading...') + '</option>').prop('disabled', true);

        $.ajax({
            type: 'POST',
            url: settings.ajaxurl,
            data: {
                action: 'guardify_pathao_locations',
                nonce: settings.nonce,
                type: 'zones',
                city_id: cityId
            },
            success: function(response) {
                $zoneSelect.html('<option value="">' + (i18n.selectZone || 'Select Zone') + '</option>').prop('disabled', false);

                if (response.success && response.data.locations) {
                    $.each(response.data.locations, function(i, zone) {
                        $zoneSelect.append('<option value="' + zone.zone_id + '">' + zone.zone_name + '</option>');
                    });
                }
            },
            error: function() {
                $zoneSelect.html('<option value="">Error loading zones</option>').prop('disabled', false);
            }
        });
    });

    // Zone → Area cascade
    $(document).on('change', '#guardify-pt-zone', function() {
        var zoneId = $(this).val();
        var $areaSelect = $('#guardify-pt-area');

        if (!zoneId) {
            $areaSelect.html('<option value="">' + (i18n.selectArea || 'Select Area') + '</option>').prop('disabled', true);
            return;
        }

        $areaSelect.html('<option value="">' + (i18n.loading || 'Loading...') + '</option>').prop('disabled', true);

        $.ajax({
            type: 'POST',
            url: settings.ajaxurl,
            data: {
                action: 'guardify_pathao_locations',
                nonce: settings.nonce,
                type: 'areas',
                zone_id: zoneId
            },
            success: function(response) {
                $areaSelect.html('<option value="">' + (i18n.selectArea || 'Select Area') + '</option>').prop('disabled', false);

                if (response.success && response.data.locations) {
                    $.each(response.data.locations, function(i, area) {
                        $areaSelect.append('<option value="' + area.area_id + '">' + area.area_name + '</option>');
                    });
                }
            },
            error: function() {
                $areaSelect.html('<option value="">Error loading areas</option>').prop('disabled', false);
            }
        });
    });

    // ─── Send Order (opens modal) ───────────────────────────

    $(document).on('click', '.guardify-pt-send', function(e) {
        e.preventDefault();

        var $button = $(this);
        var orderId = $button.data('order-id');

        if (!orderId || $button.prop('disabled') || $button.hasClass('loading')) {
            return;
        }

        openModal(orderId);
    });

    // Modal Send button
    $(document).on('click', '#guardify-pt-modal-send', function(e) {
        e.preventDefault();

        var $button = $(this);
        var orderId = $('#guardify-pt-modal-order-id').val();
        var storeId = $('#guardify-pt-store').val();
        var cityId = $('#guardify-pt-city').val();
        var zoneId = $('#guardify-pt-zone').val();
        var areaId = $('#guardify-pt-area').val();

        // Validate
        if (!storeId) { alert('Please select a store.'); return; }
        if (!cityId) { alert('Please select a city.'); return; }
        if (!zoneId) { alert('Please select a zone.'); return; }
        if (!areaId) { alert('Please select an area.'); return; }

        $button.prop('disabled', true).text(i18n.sending || 'Sending...');

        $.ajax({
            type: 'POST',
            url: settings.ajaxurl,
            data: {
                action: 'guardify_pathao_send',
                nonce: settings.nonce,
                order_id: orderId,
                store_id: storeId,
                city_id: cityId,
                zone_id: zoneId,
                area_id: areaId
            },
            success: function(response) {
                $button.prop('disabled', false).text(i18n.sendOrder || 'Send to Pathao');

                if (response.success) {
                    closeModal();

                    // Update the Send button in the table
                    var $sendBtn = $('.guardify-pt-send[data-order-id="' + orderId + '"]');
                    $sendBtn
                        .removeClass('guardify-pt-send')
                        .addClass('guardify-pt-sent')
                        .text(i18n.sent || '✓ Sent')
                        .prop('disabled', true);

                    // Reload after 1.5s to show consignment
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    var message = response.data && response.data.message ? response.data.message : 'Error';
                    alert('Pathao Error: ' + message);
                }
            },
            error: function() {
                $button.prop('disabled', false).text(i18n.sendOrder || 'Send to Pathao');
                alert('Network error. Please try again.');
            }
        });
    });

    // ─── Update COD Amount ──────────────────────────────────

    $(document).on('blur', '.guardify-pt-amount', function() {
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
                action: 'guardify_pathao_amount',
                nonce: settings.nonce,
                order_id: orderId,
                amount: amount
            },
            success: function(response) {
                if (response.success) {
                    $input.css('border-color', '#059669');
                    setTimeout(function() {
                        $input.css('border-color', '');
                    }, 2000);
                }
            }
        });
    });

    // ─── Check Delivery Status ──────────────────────────────

    $(document).on('click', '.guardify-pt-check-status, .guardify-pt-refresh-status', function(e) {
        e.preventDefault();

        var $button = $(this);
        var orderId = $button.data('order-id');
        var consignmentId = $button.data('consignment-id');
        var $statusSpan = $button.siblings('.guardify-pt-status');

        if (!orderId || !consignmentId) {
            return;
        }

        if ($button.hasClass('guardify-pt-check-status')) {
            $button.text(i18n.checking || 'Checking...');
        } else {
            $statusSpan.text(i18n.checking || 'Checking...');
        }

        $.ajax({
            type: 'POST',
            url: settings.ajaxurl,
            data: {
                action: 'guardify_pathao_status',
                nonce: settings.nonce,
                order_id: orderId,
                consignment_id: consignmentId
            },
            success: function(response) {
                if (response.success && response.data.status) {
                    var status = response.data.status;
                    var displayStatus = status.replace(/_/g, ' ');

                    $statusSpan
                        .text(displayStatus)
                        .removeClass()
                        .addClass('guardify-pt-status guardify-pt-status-' + status.toLowerCase().replace(/_/g, '-'));

                    if ($button.hasClass('guardify-pt-check-status')) {
                        $button.hide();
                        $button.siblings('.guardify-pt-refresh-status').show();
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

    // ─── Bulk Refresh Statuses ──────────────────────────────

    $(document).on('click', '.guardify-pt-bulk-refresh', function(e) {
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
                action: 'guardify_pathao_bulk_status',
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

})(jQuery);
