<?php
/**
 * Guardify Phone History Class
 * একটি নাম্বার থেকে কতগুলো অর্ডার হয়েছে দেখার অপশন
 * 
 * @package Guardify
 * @since 1.0.0
 * @updated 2.1.0 - HPOS compatible queries, performance optimization
 */

if (!defined('ABSPATH')) {
    exit;
}

class Guardify_Phone_History {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ($this->is_enabled()) {
            // Add column to order list (Legacy)
            add_filter('manage_edit-shop_order_columns', array($this, 'add_phone_history_column'));
            add_action('manage_shop_order_posts_custom_column', array($this, 'display_phone_history_column'), 10, 2);
            
            // Add column to WooCommerce Orders page (HPOS)
            add_filter('woocommerce_shop_order_list_table_columns', array($this, 'add_phone_history_column'));
            add_action('woocommerce_shop_order_list_table_custom_column', array($this, 'display_phone_history_column_hpos'), 10, 2);
            
            // AJAX handlers
            add_action('wp_ajax_guardify_get_phone_history', array($this, 'ajax_get_phone_history'));
            
            // Add styles and scripts
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        }
    }

    /**
     * Check if feature is enabled
     */
    public function is_enabled() {
        return get_option('guardify_phone_history_enabled', '1') === '1';
    }

    /**
     * Add phone history column
     */
    public function add_phone_history_column($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            // Add after billing_address column
            if ($key === 'billing_address') {
                $new_columns['guardify_phone_history'] = __('Order History', 'guardify');
            }
        }
        
        // If billing_address not found, add at end
        if (!isset($new_columns['guardify_phone_history'])) {
            $new_columns['guardify_phone_history'] = __('Order History', 'guardify');
        }
        
        return $new_columns;
    }

    /**
     * Display column content (Legacy)
     */
    public function display_phone_history_column($column, $post_id) {
        if ($column !== 'guardify_phone_history') {
            return;
        }

        $order = wc_get_order($post_id);
        if (!$order) {
            return;
        }

        $this->render_phone_history_info($order);
    }

    /**
     * Display column content (HPOS)
     */
    public function display_phone_history_column_hpos($column, $order) {
        if ($column !== 'guardify_phone_history') {
            return;
        }

        $this->render_phone_history_info($order);
    }

    /**
     * Get order count for a phone number
     * HPOS compatible with optimized COUNT query
     */
    public function get_order_count_by_phone($phone, $exclude_order_id = 0) {
        if (empty($phone)) {
            return 0;
        }

        // Normalize phone number - remove spaces, dashes, parentheses
        $phone = preg_replace('/[\s\-\(\)]+/', '', $phone);
        
        global $wpdb;
        
        try {
            // Check for HPOS (High-Performance Order Storage)
            if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
                \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
                // HPOS enabled - use direct query on wc_orders table
                $orders_table = $wpdb->prefix . 'wc_orders';
                
                if ($exclude_order_id) {
                    $count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$orders_table}
                        WHERE billing_phone = %s
                        AND id != %d
                        AND status NOT IN ('trash')",
                        $phone,
                        $exclude_order_id
                    ));
                } else {
                    $count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$orders_table}
                        WHERE billing_phone = %s
                        AND status NOT IN ('trash')",
                        $phone
                    ));
                }
                
                return intval($count);
            } else {
                // Legacy post meta
                if ($exclude_order_id) {
                    $count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                        JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                        WHERE pm.meta_key = '_billing_phone'
                        AND pm.meta_value = %s
                        AND p.ID != %d
                        AND p.post_type = 'shop_order'
                        AND p.post_status NOT IN ('trash')",
                        $phone,
                        $exclude_order_id
                    ));
                } else {
                    $count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                        JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                        WHERE pm.meta_key = '_billing_phone'
                        AND pm.meta_value = %s
                        AND p.post_type = 'shop_order'
                        AND p.post_status NOT IN ('trash')",
                        $phone
                    ));
                }
                
                return intval($count);
            }
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Get orders by phone number
     * HPOS compatible
     */
    public function get_orders_by_phone($phone, $exclude_order_id = 0, $limit = 10) {
        if (empty($phone)) {
            return array();
        }

        // Normalize phone number
        $phone = preg_replace('/[\s\-\(\)]+/', '', $phone);
        
        global $wpdb;
        
        try {
            // Check for HPOS
            if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
                \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
                // HPOS enabled
                $orders_table = $wpdb->prefix . 'wc_orders';
                
                if ($exclude_order_id) {
                    $order_ids = $wpdb->get_col($wpdb->prepare(
                        "SELECT id FROM {$orders_table}
                        WHERE billing_phone = %s
                        AND id != %d
                        AND status NOT IN ('trash')
                        ORDER BY date_created_gmt DESC
                        LIMIT %d",
                        $phone,
                        $exclude_order_id,
                        $limit
                    ));
                } else {
                    $order_ids = $wpdb->get_col($wpdb->prepare(
                        "SELECT id FROM {$orders_table}
                        WHERE billing_phone = %s
                        AND status NOT IN ('trash')
                        ORDER BY date_created_gmt DESC
                        LIMIT %d",
                        $phone,
                        $limit
                    ));
                }
                
                $orders = array();
                foreach ($order_ids as $order_id) {
                    $order = wc_get_order($order_id);
                    if ($order) {
                        $orders[] = $order;
                    }
                }
                return $orders;
            } else {
                // Legacy - use wc_get_orders
                $args = array(
                    'billing_phone' => $phone,
                    'limit' => $limit,
                    'orderby' => 'date',
                    'order' => 'DESC',
                );

                if ($exclude_order_id) {
                    $args['exclude'] = array($exclude_order_id);
                }

                return wc_get_orders($args);
            }
        } catch (Exception $e) {
            return array();
        }
    }

    /**
     * Render phone history info
     */
    private function render_phone_history_info($order) {
        $phone = $order->get_billing_phone();
        $order_id = $order->get_id();
        
        if (empty($phone)) {
            echo '<span class="guardify-no-phone">—</span>';
            return;
        }

        $order_count = $this->get_order_count_by_phone($phone, $order_id);
        $total_count = $order_count + 1; // Including current order

        // Determine badge color based on order count
        if ($total_count === 1) {
            $badge_class = 'guardify-count-new';
            $badge_text = __('নতুন', 'guardify');
        } elseif ($total_count <= 3) {
            $badge_class = 'guardify-count-low';
            $badge_text = $total_count;
        } elseif ($total_count <= 5) {
            $badge_class = 'guardify-count-medium';
            $badge_text = $total_count;
        } else {
            $badge_class = 'guardify-count-high';
            $badge_text = $total_count;
        }

        echo '<div class="guardify-phone-history">';
        echo '<span class="guardify-order-count ' . esc_attr($badge_class) . '" title="' . sprintf(__('এই নাম্বার থেকে মোট %d টি অর্ডার', 'guardify'), $total_count) . '">' . esc_html($badge_text) . '</span>';
        
        if ($order_count > 0) {
            echo ' <button type="button" class="button button-small guardify-view-history" data-order-id="' . esc_attr($order_id) . '" data-phone="' . esc_attr($phone) . '">' . __('বিস্তারিত', 'guardify') . '</button>';
        }
        
        echo '</div>';
        
        // Add popup container
        echo '<div id="guardify-history-popup-' . esc_attr($order_id) . '" class="guardify-history-popup" style="display:none;"></div>';
    }

    /**
     * AJAX handler for getting phone history
     */
    public function ajax_get_phone_history() {
        check_ajax_referer('guardify-admin-nonce', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(__('অনুমতি নেই', 'guardify'));
            return;
        }

        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $current_order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;

        if (empty($phone)) {
            wp_send_json_error(__('ফোন নাম্বার পাওয়া যায়নি', 'guardify'));
            return;
        }

        $orders = $this->get_orders_by_phone($phone, 0, 20);
        
        if (empty($orders)) {
            wp_send_json_error(__('কোন অর্ডার পাওয়া যায়নি', 'guardify'));
            return;
        }

        // Calculate statistics
        $total_spent = 0;
        $completed_orders = 0;
        $cancelled_orders = 0;
        $customer_name = '';
        $customer_address = '';
        $referral_source = '';
        
        foreach ($orders as $order) {
            if (empty($customer_name)) {
                $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                $customer_address = $order->get_billing_address_1();
                if ($order->get_billing_city()) {
                    $customer_address .= ', ' . $order->get_billing_city();
                }
            }
            
            // Get referral from first order
            if (empty($referral_source)) {
                $ref = $order->get_meta('_wc_order_attribution_referrer');
                if (!empty($ref)) {
                    $referral_source = $ref;
                }
            }
            
            $status = $order->get_status();
            if ($status === 'completed') {
                $completed_orders++;
                $total_spent += $order->get_total();
            } elseif ($status === 'cancelled' || $status === 'failed') {
                $cancelled_orders++;
            } else {
                $total_spent += $order->get_total();
            }
        }

        // Build modern popup HTML
        $html = '<div class="guardify-modal-container">';
        
        // Header with close button
        $html .= '<div class="guardify-modal-header">';
        $html .= '<div class="guardify-modal-title">';
        $html .= '<span class="guardify-modal-icon">📱</span>';
        $html .= '<div>';
        $html .= '<h2>' . esc_html($phone) . '</h2>';
        $html .= '<p class="guardify-customer-name">' . esc_html($customer_name) . '</p>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<button type="button" class="guardify-modal-close" aria-label="Close">&times;</button>';
        $html .= '</div>';
        
        // Stats cards
        $html .= '<div class="guardify-stats-grid">';
        
        // Total Orders
        $html .= '<div class="guardify-stat-card">';
        $html .= '<div class="guardify-stat-icon">📦</div>';
        $html .= '<div class="guardify-stat-info">';
        $html .= '<span class="guardify-stat-value">' . count($orders) . '</span>';
        $html .= '<span class="guardify-stat-label">মোট অর্ডার</span>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Completed Orders
        $html .= '<div class="guardify-stat-card guardify-stat-success">';
        $html .= '<div class="guardify-stat-icon">✅</div>';
        $html .= '<div class="guardify-stat-info">';
        $html .= '<span class="guardify-stat-value">' . $completed_orders . '</span>';
        $html .= '<span class="guardify-stat-label">সম্পন্ন</span>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Cancelled Orders
        $html .= '<div class="guardify-stat-card guardify-stat-danger">';
        $html .= '<div class="guardify-stat-icon">❌</div>';
        $html .= '<div class="guardify-stat-info">';
        $html .= '<span class="guardify-stat-value">' . $cancelled_orders . '</span>';
        $html .= '<span class="guardify-stat-label">বাতিল</span>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Total Spent
        $html .= '<div class="guardify-stat-card guardify-stat-primary">';
        $html .= '<div class="guardify-stat-icon">💰</div>';
        $html .= '<div class="guardify-stat-info">';
        $html .= '<span class="guardify-stat-value">' . wc_price($total_spent) . '</span>';
        $html .= '<span class="guardify-stat-label">মোট খরচ</span>';
        $html .= '</div>';
        $html .= '</div>';
        
        $html .= '</div>'; // End stats grid
        
        // Customer info bar
        if (!empty($customer_address) || !empty($referral_source)) {
            $html .= '<div class="guardify-info-bar">';
            if (!empty($customer_address)) {
                $html .= '<span class="guardify-info-item"><strong>📍</strong> ' . esc_html($customer_address) . '</span>';
            }
            if (!empty($referral_source)) {
                $html .= '<span class="guardify-info-item"><strong>🔗</strong> ' . esc_html($referral_source) . '</span>';
            }
            $html .= '</div>';
        }
        
        // Orders table
        $html .= '<div class="guardify-orders-section">';
        $html .= '<h3>অর্ডার তালিকা</h3>';
        $html .= '<div class="guardify-table-wrapper">';
        $html .= '<table class="guardify-orders-table">';
        $html .= '<thead><tr>';
        $html .= '<th>অর্ডার</th>';
        $html .= '<th>তারিখ</th>';
        $html .= '<th>স্ট্যাটাস</th>';
        $html .= '<th>মোট</th>';
        $html .= '<th>অ্যাকশন</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($orders as $order) {
            $is_current = ($order->get_id() == $current_order_id);
            $status = $order->get_status();
            $row_class = $is_current ? 'guardify-row-current' : '';
            
            $html .= '<tr class="' . esc_attr($row_class) . '">';
            
            // Order number
            $html .= '<td class="guardify-order-num">';
            $html .= '<strong>#' . $order->get_order_number() . '</strong>';
            if ($is_current) {
                $html .= '<span class="guardify-badge-current">এই অর্ডার</span>';
            }
            $html .= '</td>';
            
            // Date
            $html .= '<td>' . esc_html($order->get_date_created()->date_i18n('M j, Y')) . '</td>';
            
            // Status with colored badge
            $html .= '<td><span class="guardify-badge guardify-badge-' . esc_attr($status) . '">' . esc_html(wc_get_order_status_name($status)) . '</span></td>';
            
            // Total
            $html .= '<td class="guardify-order-total">' . wp_kses_post($order->get_formatted_order_total()) . '</td>';
            
            // Action button
            $html .= '<td><a href="' . esc_url($order->get_edit_order_url()) . '" target="_blank" class="guardify-btn-view">দেখুন →</a></td>';
            
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>'; // End table wrapper
        $html .= '</div>'; // End orders section
        
        // Footer
        $html .= '<div class="guardify-modal-footer">';
        $html .= '<button type="button" class="guardify-btn-close guardify-close-popup">বন্ধ করুন</button>';
        $html .= '</div>';
        
        $html .= '</div>'; // End modal container

        wp_send_json_success($html);
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        global $post_type;
        
        if ($post_type !== 'shop_order' && $hook !== 'woocommerce_page_wc-orders') {
            return;
        }

        wp_enqueue_script(
            'guardify-phone-history',
            GUARDIFY_PLUGIN_URL . 'assets/js/phone-history.js',
            array('jquery'),
            GUARDIFY_VERSION,
            true
        );

        wp_localize_script('guardify-phone-history', 'guardifyPhoneHistory', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('guardify-admin-nonce'),
        ));

        wp_add_inline_style('woocommerce_admin_styles', $this->get_admin_styles());
    }

    /**
     * Get admin styles
     */
    private function get_admin_styles() {
        return "
            /* Column styles */
            .guardify-phone-history {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .guardify-order-count {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 28px;
                height: 28px;
                padding: 0 8px;
                border-radius: 14px;
                font-size: 12px;
                font-weight: 600;
                color: #fff;
            }
            .guardify-count-new {
                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            }
            .guardify-count-low {
                background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            }
            .guardify-count-medium {
                background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            }
            .guardify-count-high {
                background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            }
            .guardify-view-history {
                font-size: 11px !important;
                padding: 2px 8px !important;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
                color: #fff !important;
                border: none !important;
                border-radius: 4px !important;
                cursor: pointer;
                transition: all 0.2s ease;
            }
            .guardify-view-history:hover {
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
            }
            .guardify-no-phone {
                color: #999;
            }
            .column-guardify_phone_history {
                width: 140px;
            }
            
            /* Modal Overlay */
            .guardify-history-popup {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(17, 24, 39, 0.7);
                backdrop-filter: blur(4px);
                z-index: 100000;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
                box-sizing: border-box;
            }
            
            /* Modal Container */
            .guardify-modal-container {
                background: #fff;
                border-radius: 16px;
                max-width: 800px;
                width: 100%;
                max-height: 90vh;
                overflow: hidden;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
                animation: guardifySlideUp 0.3s ease;
            }
            
            @keyframes guardifySlideUp {
                from {
                    opacity: 0;
                    transform: translateY(20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            /* Modal Header */
            .guardify-modal-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 24px 28px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: #fff;
            }
            
            .guardify-modal-title {
                display: flex;
                align-items: center;
                gap: 16px;
            }
            
            .guardify-modal-icon {
                font-size: 40px;
                background: rgba(255,255,255,0.2);
                width: 64px;
                height: 64px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .guardify-modal-title h2 {
                margin: 0;
                font-size: 24px;
                font-weight: 700;
                color: #fff;
            }
            
            .guardify-customer-name {
                margin: 4px 0 0;
                opacity: 0.9;
                font-size: 14px;
            }
            
            .guardify-modal-close {
                background: rgba(255,255,255,0.2);
                border: none;
                color: #fff;
                font-size: 28px;
                width: 44px;
                height: 44px;
                border-radius: 50%;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: all 0.2s ease;
            }
            
            .guardify-modal-close:hover {
                background: rgba(255,255,255,0.3);
                transform: rotate(90deg);
            }
            
            /* Stats Grid */
            .guardify-stats-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 16px;
                padding: 24px 28px;
                background: #f8fafc;
                border-bottom: 1px solid #e2e8f0;
            }
            
            .guardify-stat-card {
                background: #fff;
                border-radius: 12px;
                padding: 16px;
                display: flex;
                align-items: center;
                gap: 12px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                border: 1px solid #e2e8f0;
            }
            
            .guardify-stat-icon {
                font-size: 28px;
                width: 48px;
                height: 48px;
                background: #f1f5f9;
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .guardify-stat-info {
                display: flex;
                flex-direction: column;
            }
            
            .guardify-stat-value {
                font-size: 20px;
                font-weight: 700;
                color: #1e293b;
            }
            
            .guardify-stat-label {
                font-size: 12px;
                color: #64748b;
            }
            
            .guardify-stat-success .guardify-stat-icon { background: #dcfce7; }
            .guardify-stat-danger .guardify-stat-icon { background: #fee2e2; }
            .guardify-stat-primary .guardify-stat-icon { background: #dbeafe; }
            
            /* Info Bar */
            .guardify-info-bar {
                display: flex;
                flex-wrap: wrap;
                gap: 20px;
                padding: 16px 28px;
                background: #fffbeb;
                border-bottom: 1px solid #fef3c7;
            }
            
            .guardify-info-item {
                display: flex;
                align-items: center;
                gap: 6px;
                font-size: 13px;
                color: #78716c;
            }
            
            /* Orders Section */
            .guardify-orders-section {
                padding: 24px 28px;
                max-height: 350px;
                overflow-y: auto;
            }
            
            .guardify-orders-section h3 {
                margin: 0 0 16px;
                font-size: 16px;
                font-weight: 600;
                color: #1e293b;
            }
            
            .guardify-table-wrapper {
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                overflow: hidden;
            }
            
            .guardify-orders-table {
                width: 100%;
                border-collapse: collapse;
            }
            
            .guardify-orders-table th {
                background: #f8fafc;
                padding: 12px 16px;
                text-align: left;
                font-size: 12px;
                font-weight: 600;
                color: #64748b;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                border-bottom: 1px solid #e2e8f0;
            }
            
            .guardify-orders-table td {
                padding: 14px 16px;
                border-bottom: 1px solid #f1f5f9;
                font-size: 14px;
                color: #334155;
            }
            
            .guardify-orders-table tr:last-child td {
                border-bottom: none;
            }
            
            .guardify-orders-table tr:hover {
                background: #f8fafc;
            }
            
            .guardify-row-current {
                background: #fef3c7 !important;
            }
            
            .guardify-order-num strong {
                color: #1e293b;
            }
            
            .guardify-badge-current {
                display: inline-block;
                background: #10b981;
                color: #fff;
                font-size: 10px;
                padding: 3px 8px;
                border-radius: 20px;
                margin-left: 8px;
                font-weight: 500;
            }
            
            /* Status Badges */
            .guardify-badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: 600;
            }
            
            .guardify-badge-completed { background: #dcfce7; color: #166534; }
            .guardify-badge-processing { background: #dbeafe; color: #1e40af; }
            .guardify-badge-pending { background: #fef3c7; color: #92400e; }
            .guardify-badge-on-hold { background: #fce7f3; color: #9d174d; }
            .guardify-badge-cancelled, .guardify-badge-failed { background: #fee2e2; color: #991b1b; }
            .guardify-badge-refunded { background: #e0e7ff; color: #3730a3; }
            
            .guardify-order-total {
                font-weight: 600;
                color: #1e293b;
            }
            
            .guardify-btn-view {
                color: #667eea;
                text-decoration: none;
                font-size: 13px;
                font-weight: 500;
                transition: color 0.2s;
            }
            
            .guardify-btn-view:hover {
                color: #764ba2;
            }
            
            /* Modal Footer */
            .guardify-modal-footer {
                padding: 20px 28px;
                background: #f8fafc;
                border-top: 1px solid #e2e8f0;
                display: flex;
                justify-content: flex-end;
            }
            
            .guardify-btn-close {
                background: linear-gradient(135deg, #64748b 0%, #475569 100%);
                color: #fff;
                border: none;
                padding: 12px 28px;
                border-radius: 8px;
                font-size: 14px;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s ease;
            }
            
            .guardify-btn-close:hover {
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(100, 116, 139, 0.4);
            }
            
            /* Responsive */
            @media (max-width: 768px) {
                .guardify-stats-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
                .guardify-modal-container {
                    max-height: 95vh;
                }
            }
        ";
    }
}
