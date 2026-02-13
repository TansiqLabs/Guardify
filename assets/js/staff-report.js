/**
 * Guardify Staff Report JavaScript
 */

(function($) {
    'use strict';

    if (typeof guardifyStaffReport === 'undefined') {
        return;
    }

    var settings = guardifyStaffReport;
    var currentPeriod = 'all';

    /**
     * Period labels
     */
    var periodLabels = {
        'all': 'সর্বমোট',
        'today': 'আজ',
        'yesterday': 'গতকাল',
        'week': 'গত ৭ দিন',
        'month': 'গত ৩০ দিন',
        'year': 'গত ১ বছর'
    };

    /**
     * Color schemes for avatars
     */
    var colorSchemes = [
        '#667eea', '#f093fb', '#4facfe', '#43e97b', '#fa709a',
        '#feca57', '#ff6348', '#00d2ff', '#a29bfe', '#fd79a8'
    ];

    /**
     * Fetch report data
     */
    function fetchReport(period) {
        $('#guardify-loading').show();
        $('#guardify-staff-tbody').css('opacity', '0.5');

        $.ajax({
            url: settings.ajaxurl,
            type: 'POST',
            data: {
                action: 'guardify_get_staff_report',
                nonce: settings.nonce,
                period: period
            },
            success: function(response) {
                $('#guardify-loading').hide();
                $('#guardify-staff-tbody').css('opacity', '1');

                if (response.success) {
                    renderTable(response.data.stats);
                    updatePeriodLabel(period);
                } else {
                    console.error('Error fetching report:', response);
                }
            },
            error: function(xhr, status, error) {
                $('#guardify-loading').hide();
                $('#guardify-staff-tbody').css('opacity', '1');
                console.error('AJAX error:', error);
            }
        });
    }

    /**
     * Update period label
     */
    function updatePeriodLabel(period) {
        var label = periodLabels[period] || period;
        $('#guardify-period-label').text('(' + label + ')');
    }

    /**
     * Render table
     */
    function renderTable(stats) {
        var tbody = $('#guardify-staff-tbody');
        tbody.empty();

        if (!stats || stats.length === 0) {
            tbody.append(
                '<tr><td colspan="8" class="no-data">' +
                'এই সময়কালে কোন ডেটা পাওয়া যায়নি।' +
                '</td></tr>'
            );
            return;
        }

        $.each(stats, function(index, staff) {
            var rank = index + 1;
            var rankBadge = getRankBadge(rank);
            var color = colorSchemes[index % colorSchemes.length];
            var initial = staff.name ? staff.name.charAt(0).toUpperCase() : '?';
            var miniChart = getMiniChart(staff);

            var row = '<tr>' +
                '<td class="column-rank">' + rankBadge + '</td>' +
                '<td class="column-staff">' +
                    '<div class="staff-info">' +
                        '<span class="staff-avatar" style="background: ' + color + ';">' + initial + '</span>' +
                        '<div class="staff-details">' +
                            '<strong>' + escapeHtml(staff.name) + '</strong>' +
                            '<small>' + escapeHtml(staff.role) + '</small>' +
                        '</div>' +
                    '</div>' +
                '</td>' +
                '<td class="column-total"><span class="count-badge total">' + formatNumber(staff.total_processed) + '</span></td>' +
                '<td class="column-completed"><span class="count-badge completed">' + formatNumber(staff.completed) + '</span></td>' +
                '<td class="column-processing"><span class="count-badge processing">' + formatNumber(staff.processing) + '</span></td>' +
                '<td class="column-hold"><span class="count-badge on-hold">' + formatNumber(staff.on_hold) + '</span></td>' +
                '<td class="column-cancelled"><span class="count-badge cancelled">' + formatNumber(staff.cancelled) + '</span></td>' +
                '<td class="column-chart">' + miniChart + '</td>' +
                '</tr>';

            tbody.append(row);
        });
    }

    /**
     * Get rank badge
     */
    function getRankBadge(rank) {
        if (rank === 1) {
            return '<span class="rank-badge rank-1">🥇</span>';
        } else if (rank === 2) {
            return '<span class="rank-badge rank-2">🥈</span>';
        } else if (rank === 3) {
            return '<span class="rank-badge rank-3">🥉</span>';
        } else {
            return '<span class="rank-number">' + rank + '</span>';
        }
    }

    /**
     * Get mini chart HTML
     */
    function getMiniChart(staff) {
        var total = Math.max(1, staff.total_processed);
        var completedPct = Math.round((staff.completed / total) * 100);
        var processingPct = Math.round((staff.processing / total) * 100);
        var cancelledPct = Math.round((staff.cancelled / total) * 100);

        return '<div class="mini-chart" title="সম্পন্ন: ' + completedPct + '%, প্রসেসিং: ' + processingPct + '%, বাতিল: ' + cancelledPct + '%">' +
            '<div class="chart-bar completed" style="width: ' + completedPct + '%;"></div>' +
            '<div class="chart-bar processing" style="width: ' + processingPct + '%;"></div>' +
            '<div class="chart-bar cancelled" style="width: ' + cancelledPct + '%;"></div>' +
            '</div>';
    }

    /**
     * Format number
     */
    function formatNumber(num) {
        return new Intl.NumberFormat('bn-BD').format(num || 0);
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Initialize
     */
    $(document).ready(function() {
        // Period filter change
        $('#guardify-period-filter').on('change', function() {
            currentPeriod = $(this).val();
            fetchReport(currentPeriod);
        });

        // Refresh button
        $('#guardify-refresh-report').on('click', function() {
            var $btn = $(this);
            var $icon = $btn.find('.dashicons');
            
            $icon.addClass('dashicons-update-spin');
            
            fetchReport(currentPeriod);
            
            setTimeout(function() {
                $icon.removeClass('dashicons-update-spin');
            }, 1000);
        });

        // Set initial period label
        updatePeriodLabel('all');
    });

})(jQuery);

// Add spin animation for refresh button
var style = document.createElement('style');
style.textContent = '.dashicons-update-spin { animation: spin 1s linear infinite; } @keyframes spin { 100% { transform: rotate(360deg); } }';
document.head.appendChild(style);
