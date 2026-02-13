/**
 * Guardify Phone History JavaScript (Admin)
 * Modern popup for order history
 */

(function($) {
    'use strict';

    // Check if settings are available
    if (typeof guardifyPhoneHistory === 'undefined') {
        return;
    }

    var settings = guardifyPhoneHistory;

    /**
     * Show history popup
     */
    function showHistoryPopup(orderId, html) {
        // Remove any existing popup
        $('.guardify-history-popup').remove();
        
        var popup = $('<div class="guardify-history-popup"></div>');
        popup.html(html);
        $('body').append(popup);
        
        // Prevent body scroll
        $('body').css('overflow', 'hidden');
        
        popup.fadeIn(200);

        // Close handlers
        function closePopup() {
            popup.fadeOut(200, function() {
                $(this).remove();
                $('body').css('overflow', '');
            });
            $(document).off('keydown.guardifyPopup');
        }

        // Close on button click
        popup.on('click', '.guardify-close-popup, .guardify-modal-close', function(e) {
            e.preventDefault();
            closePopup();
        });

        // Close on overlay click (not on content)
        popup.on('click', function(e) {
            if (e.target === this) {
                closePopup();
            }
        });

        // Close on escape key
        $(document).on('keydown.guardifyPopup', function(e) {
            if (e.key === 'Escape') {
                closePopup();
            }
        });
    }

    /**
     * Fetch phone history
     */
    function fetchPhoneHistory(orderId, phone) {
        // Show loading state
        var loadingHtml = '<div class="guardify-modal-container" style="max-width:400px;text-align:center;padding:60px 40px;">';
        loadingHtml += '<div style="font-size:48px;margin-bottom:20px;">⏳</div>';
        loadingHtml += '<h3 style="margin:0 0 10px;color:#1e293b;">লোড হচ্ছে...</h3>';
        loadingHtml += '<p style="margin:0;color:#64748b;">অর্ডার তথ্য খুঁজছি</p>';
        loadingHtml += '</div>';
        
        showHistoryPopup(orderId, loadingHtml);
        
        $.ajax({
            url: settings.ajaxurl,
            type: 'POST',
            data: {
                action: 'guardify_get_phone_history',
                nonce: settings.nonce,
                order_id: orderId,
                phone: phone
            },
            success: function(response) {
                if (response.success) {
                    showHistoryPopup(orderId, response.data);
                } else {
                    var errorHtml = '<div class="guardify-modal-container" style="max-width:400px;text-align:center;padding:60px 40px;">';
                    errorHtml += '<div style="font-size:48px;margin-bottom:20px;">😕</div>';
                    errorHtml += '<h3 style="margin:0 0 10px;color:#ef4444;">' + (response.data || 'ত্রুটি হয়েছে') + '</h3>';
                    errorHtml += '<button type="button" class="guardify-btn-close guardify-close-popup" style="margin-top:20px;">বন্ধ করুন</button>';
                    errorHtml += '</div>';
                    showHistoryPopup(orderId, errorHtml);
                }
            },
            error: function() {
                var errorHtml = '<div class="guardify-modal-container" style="max-width:400px;text-align:center;padding:60px 40px;">';
                errorHtml += '<div style="font-size:48px;margin-bottom:20px;">❌</div>';
                errorHtml += '<h3 style="margin:0 0 10px;color:#ef4444;">সার্ভার ত্রুটি</h3>';
                errorHtml += '<p style="margin:0 0 20px;color:#64748b;">আবার চেষ্টা করুন</p>';
                errorHtml += '<button type="button" class="guardify-btn-close guardify-close-popup">বন্ধ করুন</button>';
                errorHtml += '</div>';
                showHistoryPopup(orderId, errorHtml);
            }
        });
    }

    /**
     * Initialize
     */
    $(document).ready(function() {
        // View history button click
        $(document).on('click', '.guardify-view-history', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            var orderId = $(this).data('order-id');
            var phone = $(this).data('phone');
            
            if (phone) {
                fetchPhoneHistory(orderId, phone);
            }
        });
    });

})(jQuery);
