/**
 * Guardify Blocklist Manager JavaScript
 */
(function($) {
    'use strict';

    var GuardifyBlocklist = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Add to blocklist
            $(document).on('click', '#guardify-add-btn', this.handleAdd);
            $(document).on('keypress', '#guardify-add-value', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    $('#guardify-add-btn').trigger('click');
                }
            });
            
            // Remove from blocklist
            $(document).on('click', '.guardify-remove-btn', this.handleRemove);
            
            // View orders modal
            $(document).on('click', '.guardify-view-orders', this.handleViewOrders);
            
            // Close modal
            $(document).on('click', '.guardify-modal-close', this.closeModal);
            $(document).on('click', '.guardify-modal', function(e) {
                if (e.target === this) {
                    GuardifyBlocklist.closeModal();
                }
            });
            
            // ESC key to close modal
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    GuardifyBlocklist.closeModal();
                }
            });
        },

        handleAdd: function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var $input = $('#guardify-add-value');
            var value = $input.val().trim();
            var type = $btn.data('type');
            
            if (!value) {
                $input.focus();
                return;
            }
            
            if (!confirm(guardifyBlocklist.strings.confirm_add)) {
                return;
            }
            
            $btn.prop('disabled', true).text(guardifyBlocklist.strings.adding);
            
            $.ajax({
                url: guardifyBlocklist.ajax_url,
                type: 'POST',
                data: {
                    action: 'guardify_add_to_blocklist',
                    nonce: guardifyBlocklist.nonce,
                    type: type,
                    value: value
                },
                success: function(response) {
                    if (response.success) {
                        // Reload page to show new item
                        location.reload();
                    } else {
                        alert(response.data.message || guardifyBlocklist.strings.error);
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> Add to Blocklist');
                    }
                },
                error: function() {
                    alert(guardifyBlocklist.strings.error);
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> Add to Blocklist');
                }
            });
        },

        handleRemove: function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var $row = $btn.closest('tr');
            var type = $btn.data('type');
            var value = $btn.data('value');
            
            if (!confirm(guardifyBlocklist.strings.confirm_remove)) {
                return;
            }
            
            $btn.prop('disabled', true).text(guardifyBlocklist.strings.removing);
            
            $.ajax({
                url: guardifyBlocklist.ajax_url,
                type: 'POST',
                data: {
                    action: 'guardify_remove_from_blocklist',
                    nonce: guardifyBlocklist.nonce,
                    type: type,
                    value: value
                },
                success: function(response) {
                    if (response.success) {
                        // Animate row removal
                        $row.fadeOut(300, function() {
                            $(this).remove();
                            
                            // Update count in tab
                            GuardifyBlocklist.updateTabCount(type, response.data.count);
                            
                            // Check if table is empty
                            if ($('.guardify-blocklist-table tbody tr').length === 0) {
                                $('.guardify-blocklist-table tbody').html(
                                    '<tr><td colspan="3" class="no-items">' +
                                    '<span class="dashicons dashicons-yes-alt" style="color: #22c55e;"></span>' +
                                    'No blocked items. Your blocklist is empty.</td></tr>'
                                );
                            }
                        });
                    } else {
                        alert(response.data.message || guardifyBlocklist.strings.error);
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Remove');
                    }
                },
                error: function() {
                    alert(guardifyBlocklist.strings.error);
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Remove');
                }
            });
        },

        handleViewOrders: function(e) {
            e.preventDefault();
            
            var $link = $(this);
            var type = $link.data('type');
            var value = $link.data('value');
            
            // Show modal
            $('#guardify-orders-modal').show();
            $('#guardify-modal-title').text('Orders for: ' + value);
            $('#guardify-modal-body').html(
                '<div class="guardify-loading">' +
                '<span class="spinner is-active"></span> Loading orders...</div>'
            );
            
            $.ajax({
                url: guardifyBlocklist.ajax_url,
                type: 'POST',
                data: {
                    action: 'guardify_get_blocked_orders',
                    nonce: guardifyBlocklist.nonce,
                    type: type,
                    value: value
                },
                success: function(response) {
                    if (response.success) {
                        $('#guardify-modal-body').html(response.data.html);
                    } else {
                        $('#guardify-modal-body').html(
                            '<p class="no-orders-found">' + 
                            (response.data.message || guardifyBlocklist.strings.no_orders) + 
                            '</p>'
                        );
                    }
                },
                error: function() {
                    $('#guardify-modal-body').html(
                        '<p class="no-orders-found">' + guardifyBlocklist.strings.error + '</p>'
                    );
                }
            });
        },

        closeModal: function() {
            $('#guardify-orders-modal').hide();
        },

        updateTabCount: function(type, count) {
            var $tab = $('.nav-tab[href*="tab=' + type + '"]');
            if ($tab.length) {
                $tab.find('.count').text('(' + count + ')');
            }
            
            // Update stat card
            var $stat = $('.stat-' + type + ' .stat-value');
            if ($stat.length) {
                $stat.text(count);
            }
        }
    };

    $(document).ready(function() {
        GuardifyBlocklist.init();
    });

})(jQuery);
