<?php
/**
 * Guardify Staff Performance Report Class
 * কে কতগুলো অর্ডার প্রসেস করেছে তার রিপোর্ট
 * 
 * @package Guardify
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Guardify_Staff_Report {
    private static ?self $instance = null;

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Add submenu for reports
        add_action('admin_menu', array($this, 'add_report_menu'), 20);
        
        // AJAX handlers for report data
        add_action('wp_ajax_guardify_get_staff_report', array($this, 'ajax_get_staff_report'));
        
        // Enqueue scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Add report submenu
     */
    public function add_report_menu(): void {
        add_submenu_page(
            'guardify-settings',
            __('Staff Reports', 'guardify'),
            __('📊 Staff Reports', 'guardify'),
            'manage_woocommerce',
            'guardify-staff-report',
            array($this, 'render_report_page')
        );
    }

    /**
     * Enqueue scripts
     */
    public function enqueue_scripts(string $hook): void {
        if ($hook !== 'guardify_page_guardify-staff-report') {
            return;
        }

        wp_enqueue_style(
            'guardify-staff-report',
            GUARDIFY_PLUGIN_URL . 'assets/css/staff-report.css',
            array(),
            GUARDIFY_VERSION
        );

        wp_enqueue_script(
            'guardify-staff-report',
            GUARDIFY_PLUGIN_URL . 'assets/js/staff-report.js',
            array('jquery'),
            GUARDIFY_VERSION,
            true
        );

        // Use wp_add_inline_script instead of wp_localize_script
        // This is immune to LiteSpeed Cache JS combination/reordering
        wp_add_inline_script('guardify-staff-report',
            'var guardifyStaffReport = ' . wp_json_encode(array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('guardify_staff_report_nonce'),
            )) . ';',
            'before'
        );
    }

    /**
     * Get staff members who have processed orders
     */
    private function get_staff_members() {
        global $wpdb;

        // Get users who have changed order status
        $user_ids = array();
        
        // Check for HPOS
        if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $table_name = $wpdb->prefix . 'wc_orders_meta';
            $results = $wpdb->get_col(
                "SELECT DISTINCT meta_value FROM {$table_name} 
                WHERE meta_key = '_guardify_status_changed_by' 
                AND meta_value != ''"
            );
        } else {
            $results = $wpdb->get_col(
                "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} 
                WHERE meta_key = '_guardify_status_changed_by' 
                AND meta_value != ''"
            );
        }

        $staff = array();
        foreach ($results as $user_id) {
            if ($user_id) {
                $user = get_user_by('id', $user_id);
                if ($user) {
                    $staff[$user_id] = array(
                        'id' => $user_id,
                        'name' => $user->display_name,
                        'email' => $user->user_email,
                        'role' => implode(', ', $user->roles),
                    );
                }
            }
        }

        return $staff;
    }

    /**
     * Get order stats for a user
     */
    public function get_user_order_stats($user_id, $period = 'all') {
        global $wpdb;

        $date_query = $this->get_date_query($period);
        
        $stats = array(
            'total_processed' => 0,
            'completed' => 0,
            'processing' => 0,
            'on_hold' => 0,
            'cancelled' => 0,
            'refunded' => 0,
        );

        // Check for HPOS
        if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            
            $meta_table = $wpdb->prefix . 'wc_orders_meta';
            $orders_table = $wpdb->prefix . 'wc_orders';

            // Total processed
            $stats['total_processed'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT om.order_id) 
                FROM {$meta_table} om
                JOIN {$orders_table} o ON om.order_id = o.id
                WHERE om.meta_key = '_guardify_status_changed_by' 
                AND om.meta_value = %s
                {$date_query}",
                $user_id
            ));

            // By status
            $status_counts = $wpdb->get_results($wpdb->prepare(
                "SELECT om2.meta_value as status, COUNT(DISTINCT om.order_id) as count
                FROM {$meta_table} om
                JOIN {$meta_table} om2 ON om.order_id = om2.order_id AND om2.meta_key = '_guardify_status_changed_to'
                JOIN {$orders_table} o ON om.order_id = o.id
                WHERE om.meta_key = '_guardify_status_changed_by' 
                AND om.meta_value = %s
                {$date_query}
                GROUP BY om2.meta_value",
                $user_id
            ));

        } else {
            // Legacy post meta
            
            // Total processed
            $stats['total_processed'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT pm.post_id) 
                FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE pm.meta_key = '_guardify_status_changed_by' 
                AND pm.meta_value = %s
                AND p.post_type = 'shop_order'
                {$date_query}",
                $user_id
            ));

            // By status
            $status_counts = $wpdb->get_results($wpdb->prepare(
                "SELECT pm2.meta_value as status, COUNT(DISTINCT pm.post_id) as count
                FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->postmeta} pm2 ON pm.post_id = pm2.post_id AND pm2.meta_key = '_guardify_status_changed_to'
                JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE pm.meta_key = '_guardify_status_changed_by' 
                AND pm.meta_value = %s
                AND p.post_type = 'shop_order'
                {$date_query}
                GROUP BY pm2.meta_value",
                $user_id
            ));
        }

        // Map status counts
        if ($status_counts) {
            foreach ($status_counts as $row) {
                $status_key = str_replace('wc-', '', $row->status);
                if (isset($stats[$status_key])) {
                    $stats[$status_key] = (int) $row->count;
                }
            }
        }

        return $stats;
    }

    /**
     * Get date query based on period
     */
    private function get_date_query($period) {
        $date_column = '';
        
        // Check for HPOS
        if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $date_column = 'o.date_created_gmt';
        } else {
            $date_column = 'p.post_date';
        }

        switch ($period) {
            case 'today':
                return "AND DATE({$date_column}) = CURDATE()";
            case 'yesterday':
                return "AND DATE({$date_column}) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            case 'week':
                return "AND {$date_column} >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            case 'month':
                return "AND {$date_column} >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            case 'year':
                return "AND {$date_column} >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
            default:
                return '';
        }
    }

    /**
     * Get all staff stats for a period
     */
    public function get_all_staff_stats($period = 'all') {
        $staff = $this->get_staff_members();
        $stats = array();

        foreach ($staff as $user_id => $user_info) {
            $user_stats = $this->get_user_order_stats($user_id, $period);
            $stats[] = array_merge($user_info, $user_stats);
        }

        // Sort by total processed (descending)
        usort($stats, function($a, $b) {
            return $b['total_processed'] - $a['total_processed'];
        });

        return $stats;
    }

    /**
     * AJAX handler for getting report data
     */
    public function ajax_get_staff_report() {
        check_ajax_referer('guardify_staff_report_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }

        $period = isset($_POST['period']) ? sanitize_text_field($_POST['period']) : 'all';
        $stats = $this->get_all_staff_stats($period);

        wp_send_json_success(array(
            'stats' => $stats,
            'period' => $period,
        ));
    }

    /**
     * Get summary stats
     */
    private function get_summary_stats() {
        $periods = array('today', 'week', 'month', 'all');
        $summary = array();

        foreach ($periods as $period) {
            $stats = $this->get_all_staff_stats($period);
            $total = 0;
            foreach ($stats as $s) {
                $total += $s['total_processed'];
            }
            $summary[$period] = $total;
        }

        return $summary;
    }

    /**
     * Render report page
     */
    public function render_report_page() {
        $staff_stats = $this->get_all_staff_stats('all');
        $summary = $this->get_summary_stats();
        
        // Get user colors
        $user_colors = get_option('guardify_user_colors', array());
        $color_schemes = array(
            0 => '#667eea', 1 => '#f093fb', 2 => '#4facfe', 3 => '#43e97b', 4 => '#fa709a',
            5 => '#feca57', 6 => '#ff6348', 7 => '#00d2ff', 8 => '#a29bfe', 9 => '#fd79a8',
        );
        ?>
        <div class="wrap guardify-staff-report-wrap">
            <div class="guardify-report-header">
                <div class="guardify-report-title">
                    <h1>📊 <?php _e('Staff Performance Report', 'guardify'); ?></h1>
                    <p class="description"><?php _e('কে কতগুলো অর্ডার প্রসেস করেছে তার বিস্তারিত রিপোর্ট', 'guardify'); ?></p>
                </div>
                <div class="guardify-report-actions">
                    <select id="guardify-period-filter" class="guardify-period-select">
                        <option value="all"><?php _e('সর্বমোট', 'guardify'); ?></option>
                        <option value="today"><?php _e('আজ', 'guardify'); ?></option>
                        <option value="yesterday"><?php _e('গতকাল', 'guardify'); ?></option>
                        <option value="week"><?php _e('গত ৭ দিন', 'guardify'); ?></option>
                        <option value="month"><?php _e('গত ৩০ দিন', 'guardify'); ?></option>
                        <option value="year"><?php _e('গত ১ বছর', 'guardify'); ?></option>
                    </select>
                    <button type="button" id="guardify-refresh-report" class="button button-secondary">
                        <span class="dashicons dashicons-update"></span> <?php _e('রিফ্রেশ', 'guardify'); ?>
                    </button>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="guardify-summary-cards">
                <div class="guardify-summary-card today">
                    <div class="card-icon">📅</div>
                    <div class="card-content">
                        <h3><?php echo number_format_i18n($summary['today']); ?></h3>
                        <p><?php _e('আজ প্রসেস', 'guardify'); ?></p>
                    </div>
                </div>
                <div class="guardify-summary-card week">
                    <div class="card-icon">📆</div>
                    <div class="card-content">
                        <h3><?php echo number_format_i18n($summary['week']); ?></h3>
                        <p><?php _e('এই সপ্তাহে', 'guardify'); ?></p>
                    </div>
                </div>
                <div class="guardify-summary-card month">
                    <div class="card-icon">🗓️</div>
                    <div class="card-content">
                        <h3><?php echo number_format_i18n($summary['month']); ?></h3>
                        <p><?php _e('এই মাসে', 'guardify'); ?></p>
                    </div>
                </div>
                <div class="guardify-summary-card total">
                    <div class="card-icon">🏆</div>
                    <div class="card-content">
                        <h3><?php echo number_format_i18n($summary['all']); ?></h3>
                        <p><?php _e('সর্বমোট', 'guardify'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Staff Performance Table -->
            <div class="guardify-report-table-container">
                <h2><?php _e('স্টাফ পারফরম্যান্স', 'guardify'); ?> <span id="guardify-period-label"></span></h2>
                
                <div id="guardify-loading" style="display: none;">
                    <span class="spinner is-active"></span> <?php _e('লোড হচ্ছে...', 'guardify'); ?>
                </div>

                <table class="wp-list-table widefat fixed striped guardify-staff-table" id="guardify-staff-table">
                    <thead>
                        <tr>
                            <th class="column-rank">#</th>
                            <th class="column-staff"><?php _e('স্টাফ', 'guardify'); ?></th>
                            <th class="column-total"><?php _e('মোট প্রসেস', 'guardify'); ?></th>
                            <th class="column-completed"><?php _e('সম্পন্ন', 'guardify'); ?></th>
                            <th class="column-processing"><?php _e('প্রসেসিং', 'guardify'); ?></th>
                            <th class="column-hold"><?php _e('হোল্ড', 'guardify'); ?></th>
                            <th class="column-cancelled"><?php _e('বাতিল', 'guardify'); ?></th>
                            <th class="column-chart"><?php _e('চার্ট', 'guardify'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="guardify-staff-tbody">
                        <?php if (empty($staff_stats)) : ?>
                            <tr>
                                <td colspan="8" class="no-data">
                                    <?php _e('কোন ডেটা পাওয়া যায়নি। অর্ডার স্ট্যাটাস পরিবর্তন করলে এখানে দেখাবে।', 'guardify'); ?>
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php $rank = 1; foreach ($staff_stats as $staff) : 
                                $color_index = isset($user_colors[$staff['id']]) ? $user_colors[$staff['id']] : 0;
                                $color = isset($color_schemes[$color_index]) ? $color_schemes[$color_index] : $color_schemes[0];
                            ?>
                                <tr>
                                    <td class="column-rank">
                                        <?php if ($rank <= 3) : ?>
                                            <span class="rank-badge rank-<?php echo $rank; ?>">
                                                <?php echo $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : '🥉'); ?>
                                            </span>
                                        <?php else : ?>
                                            <span class="rank-number"><?php echo $rank; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="column-staff">
                                        <div class="staff-info">
                                            <span class="staff-avatar" style="background: <?php echo esc_attr($color); ?>;">
                                                <?php echo esc_html(mb_substr($staff['name'], 0, 1)); ?>
                                            </span>
                                            <div class="staff-details">
                                                <strong><?php echo esc_html($staff['name']); ?></strong>
                                                <small><?php echo esc_html($staff['role']); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="column-total">
                                        <span class="count-badge total"><?php echo number_format_i18n($staff['total_processed']); ?></span>
                                    </td>
                                    <td class="column-completed">
                                        <span class="count-badge completed"><?php echo number_format_i18n($staff['completed']); ?></span>
                                    </td>
                                    <td class="column-processing">
                                        <span class="count-badge processing"><?php echo number_format_i18n($staff['processing']); ?></span>
                                    </td>
                                    <td class="column-hold">
                                        <span class="count-badge on-hold"><?php echo number_format_i18n($staff['on_hold']); ?></span>
                                    </td>
                                    <td class="column-cancelled">
                                        <span class="count-badge cancelled"><?php echo number_format_i18n($staff['cancelled']); ?></span>
                                    </td>
                                    <td class="column-chart">
                                        <?php $this->render_mini_chart($staff); ?>
                                    </td>
                                </tr>
                            <?php $rank++; endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Footer -->
            <div class="guardify-report-footer">
                <p>
                    <strong><?php _e('নোট:', 'guardify'); ?></strong> 
                    <?php _e('এই রিপোর্টে শুধুমাত্র সেই অর্ডারগুলো দেখাচ্ছে যেগুলোর স্ট্যাটাস Guardify সক্রিয় থাকা অবস্থায় পরিবর্তন করা হয়েছে।', 'guardify'); ?>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Render mini progress chart
     */
    private function render_mini_chart($staff) {
        $total = max(1, $staff['total_processed']);
        $completed_pct = round(($staff['completed'] / $total) * 100);
        $processing_pct = round(($staff['processing'] / $total) * 100);
        $cancelled_pct = round(($staff['cancelled'] / $total) * 100);
        ?>
        <div class="mini-chart" title="<?php echo esc_attr(sprintf(
            __('সম্পন্ন: %d%%, প্রসেসিং: %d%%, বাতিল: %d%%', 'guardify'),
            $completed_pct, $processing_pct, $cancelled_pct
        )); ?>">
            <div class="chart-bar completed" style="width: <?php echo $completed_pct; ?>%;"></div>
            <div class="chart-bar processing" style="width: <?php echo $processing_pct; ?>%;"></div>
            <div class="chart-bar cancelled" style="width: <?php echo $cancelled_pct; ?>%;"></div>
        </div>
        <?php
    }
}
