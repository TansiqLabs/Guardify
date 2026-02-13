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

        // API License activation — handled by inline script in PHP template
        // (bypasses LiteSpeed Cache JS optimization issues)
        
        // Helper function for notices (used by tabs/other features)
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
