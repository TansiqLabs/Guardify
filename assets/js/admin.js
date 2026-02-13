/**
 * Guardify Admin Scripts - Premium Edition
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Tab switching with smooth animation
        $('.guardify-tab').on('click', function(e) {
            e.preventDefault();
            
            var tabUrl = $(this).attr('href');
            var tab = new URL(tabUrl, window.location).searchParams.get('tab');
            
            // Update active tab with animation
            $('.guardify-tab').removeClass('active');
            $(this).addClass('active');
            
            // Fade out then show active tab content
            $('.guardify-tab-content.active').removeClass('active');
            $('[data-tab="' + tab + '"]').addClass('active');
        });

        // Toggle switch with label update
        $('.guardify-toggle input').on('change', function() {
            var label = $(this).closest('tr').find('.toggle-label');
            if ($(this).is(':checked')) {
                label.text('Enabled').addClass('active');
            } else {
                label.text('Disabled').removeClass('active');
            }
        });
        
        // Initialize toggle labels
        $('.guardify-toggle input').each(function() {
            var label = $(this).closest('tr').find('.toggle-label');
            if ($(this).is(':checked')) {
                label.addClass('active');
            }
        });

        // Form validation
        $('form').on('submit', function(e) {
            var hasErrors = false;
            
            // Validate phone cooldown time
            var phoneCooldownTime = $('input[name="guardify_phone_cooldown_time"]').val();
            if (phoneCooldownTime && (phoneCooldownTime < 1 || phoneCooldownTime > 720)) {
                alert('Phone cooldown time must be between 1 and 720 hours');
                hasErrors = true;
            }
            
            // Validate IP cooldown time
            var ipCooldownTime = $('input[name="guardify_ip_cooldown_time"]').val();
            if (ipCooldownTime && (ipCooldownTime < 1 || ipCooldownTime > 720)) {
                alert('IP cooldown time must be between 1 and 720 hours');
                hasErrors = true;
            }
            
            if (hasErrors) {
                e.preventDefault();
                return false;
            }
        });

        // Color picker preview update
        $('.guardify-color-select').on('change', function() {
            var colorIndex = $(this).val();
            var preview = $(this).closest('.guardify-color-picker').find('.guardify-color-preview');
            
            // Color schemes (must match PHP)
            var colorSchemes = {
                '0': {bg: '#667eea', text: '#ffffff'},
                '1': {bg: '#f093fb', text: '#ffffff'},
                '2': {bg: '#4facfe', text: '#ffffff'},
                '3': {bg: '#43e97b', text: '#ffffff'},
                '4': {bg: '#fa709a', text: '#ffffff'},
                '5': {bg: '#feca57', text: '#2c3e50'},
                '6': {bg: '#ff6348', text: '#ffffff'},
                '7': {bg: '#00d2ff', text: '#ffffff'},
                '8': {bg: '#a29bfe', text: '#ffffff'},
                '9': {bg: '#fd79a8', text: '#ffffff'}
            };
            
            var scheme = colorSchemes[colorIndex];
            if (scheme) {
                preview.css({
                    'background': scheme.bg,
                    'color': scheme.text
                });
            }
        });

        // API License activation
        $('#guardify-activate-license').on('click', function() {
            var button = $(this);
            var apiKey = $('#guardify-api-key').val();
            
            if (!apiKey || !apiKey.trim()) {
                showNotice('Please enter your license key', 'error');
                return;
            }
            
            apiKey = apiKey.trim();
            button.prop('disabled', true).addClass('guardify-loading').html('<span class="dashicons dashicons-update spinning"></span> Activating...');
            
            $.ajax({
                url: guardifyAjax.ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'guardify_activate_license',
                    nonce: guardifyAjax.nonce,
                    api_key: apiKey
                },
                success: function(response) {
                    if (response.success) {
                        showNotice(response.data.message || 'License activated!', 'success');
                        if (response.data.reload) {
                            setTimeout(function() { location.reload(); }, 1500);
                        }
                    } else {
                        showNotice(response.data.message || 'Activation failed. Please check your license key.', 'error');
                        button.prop('disabled', false).removeClass('guardify-loading').html('<span class="dashicons dashicons-yes-alt"></span> <span>Activate</span>');
                    }
                },
                error: function(xhr, status, error) {
                    var msg = 'Connection error. Please try again.';
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp && resp.data && resp.data.message) {
                            msg = resp.data.message;
                        }
                    } catch(e) {}
                    showNotice(msg, 'error');
                    button.prop('disabled', false).removeClass('guardify-loading').html('<span class="dashicons dashicons-yes-alt"></span> <span>Activate</span>');
                }
            });
        });
        
        // License Deactivation
        $('#guardify-deactivate-license').on('click', function() {
            if (!confirm('Are you sure you want to deactivate the license from this site? The license slot will be freed.')) {
                return;
            }
            
            var button = $(this);
            button.prop('disabled', true).text('Deactivating...');
            
            $.ajax({
                url: guardifyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'guardify_deactivate_license',
                    nonce: guardifyAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showNotice(response.data.message, 'success');
                        if (response.data.reload) {
                            setTimeout(function() { location.reload(); }, 1000);
                        }
                    } else {
                        showNotice(response.data.message || 'Deactivation failed', 'error');
                        button.prop('disabled', false).text('Deactivate');
                    }
                },
                error: function() {
                    showNotice('Connection error.', 'error');
                    button.prop('disabled', false).text('Deactivate');
                }
            });
        });
        
        // License Verification
        $('#guardify-check-license').on('click', function() {
            var button = $(this);
            button.prop('disabled', true).html('<span class="dashicons dashicons-update spinning"></span> Verifying...');
            
            $.ajax({
                url: guardifyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'guardify_check_license',
                    nonce: guardifyAjax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showNotice(response.data.message, 'success');
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        showNotice(response.data.message || 'Verification failed', 'error');
                        button.prop('disabled', false).html('<span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;line-height:1.4;"></span> Verify License');
                    }
                },
                error: function() {
                    showNotice('Connection error.', 'error');
                    button.prop('disabled', false).html('<span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;line-height:1.4;"></span> Verify License');
                }
            });
        });
        
        // SSO Login — Open TansiqLabs Console
        $('#guardify-sso-login').on('click', function() {
            var button = $(this);
            button.prop('disabled', true).html('<span class="dashicons dashicons-update spinning" style="font-size:18px;width:18px;height:18px;"></span> Connecting...');
            
            $.ajax({
                url: guardifyAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'guardify_sso_login',
                    nonce: guardifyAjax.nonce
                },
                success: function(response) {
                    if (response.success && response.data.sso_url) {
                        window.open(response.data.sso_url, '_blank');
                        showNotice('Console opened in a new tab', 'success');
                    } else {
                        showNotice(response.data.message || 'SSO login failed', 'error');
                    }
                    button.prop('disabled', false).html('<span class="dashicons dashicons-admin-links" style="font-size:18px;width:18px;height:18px;"></span> Open Console');
                },
                error: function() {
                    showNotice('Connection error. Please try again.', 'error');
                    button.prop('disabled', false).html('<span class="dashicons dashicons-admin-links" style="font-size:18px;width:18px;height:18px;"></span> Open Console');
                }
            });
        });
        
        // Helper function for notices
        function showNotice(message, type) {
            var notice = $('<div class="guardify-notice"></div>').addClass('guardify-notice-' + type);
            notice.append($('<p></p>').text(message));
            $('.guardify-header').after(notice);
            setTimeout(function() {
                notice.fadeOut(300, function() { $(this).remove(); });
            }, 4000);
        }
    });

})(jQuery);
