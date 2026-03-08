<?php
/**
 * Guardify Order Columns Class
 * Score, Duplicate Detection, and Block Action columns for WooCommerce orders
 * 
 * @package Guardify
 * @since 2.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Guardify_Order_Columns {
    private static ?self $instance = null;
    
    /**
     * Cached blocked lists (loaded once per page)
     */
    private ?array $blocked_phones_cache = null;
    private ?array $blocked_ips_cache = null;
    private ?array $blocked_devices_cache = null;

    /**
     * Generate all possible phone number format variants for DB matching.
     * Handles BD numbers: 01XXXXXXXXX, +8801XXXXXXXXX, 8801XXXXXXXXX, etc.
     */
    private function get_phone_variants(string $phone): array {
        // Strip everything except digits
        $digits = preg_replace('/\D/', '', $phone);

        if (empty($digits)) {
            return array();
        }

        $variants = array($phone); // original value as-is
        $variants[] = $digits;     // digits only

        // BD country code: 880 + 1XXXXXXXXX (10 digits)
        if (strlen($digits) >= 13 && substr($digits, 0, 3) === '880') {
            // Has country code: 8801XXXXXXXXX
            $local = '0' . substr($digits, 3); // 01XXXXXXXXX
            $variants[] = $local;
            $variants[] = '+880' . substr($digits, 3);
            $variants[] = '+' . $digits;
        } elseif (strlen($digits) === 11 && $digits[0] === '0') {
            // Local format: 01XXXXXXXXX
            $variants[] = '880' . substr($digits, 1);
            $variants[] = '+880' . substr($digits, 1);
        } elseif (strlen($digits) === 10 && $digits[0] === '1') {
            // Without leading zero: 1XXXXXXXXX
            $variants[] = '0' . $digits;
            $variants[] = '880' . $digits;
            $variants[] = '+880' . $digits;
        }

        // Also add the partially-cleaned version (only strip spaces/dashes/parens)
        $partial = preg_replace('/[\s\-\(\)]+/', '', $phone);
        $variants[] = $partial;

        return array_values(array_unique(array_filter($variants)));
    }

    /**
     * Build a SQL IN(...) clause with placeholders for phone variants.
     * Returns [clause, params] — e.g. ["IN (%s,%s,%s)", ['01...', '880...', '+880...']]
     */
    private function phone_in_clause(string $phone): array {
        $variants = $this->get_phone_variants($phone);
        if (empty($variants)) {
            return array("= %s", array(''));
        }
        $placeholders = implode(',', array_fill(0, count($variants), '%s'));
        return array("IN ({$placeholders})", $variants);
    }

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Feature gate: only enable Score columns if license allows it
        $features = get_option('guardify_license_features', array());
        $license_status = get_option('guardify_license_status', '');
        
        // Allow columns if: no license yet (free mode), or license is active with score_column feature
        $score_enabled = empty($license_status) || $license_status === 'active';
        if (is_array($features) && !empty($features) && isset($features['score_column'])) {
            $score_enabled = !empty($features['score_column']);
        }
        
        if (!$score_enabled) {
            return; // Score feature not available on this plan
        }
        
        // Add columns (Legacy)
        add_filter('manage_edit-shop_order_columns', array($this, 'add_custom_columns'), 20);
        add_action('manage_shop_order_posts_custom_column', array($this, 'render_custom_column_legacy'), 10, 2);
        
        // Add columns (HPOS)
        add_filter('woocommerce_shop_order_list_table_columns', array($this, 'add_custom_columns'), 20);
        add_action('woocommerce_shop_order_list_table_custom_column', array($this, 'render_custom_column'), 10, 2);
        
        // AJAX handlers for blocking
        add_action('wp_ajax_guardify_block_phone', array($this, 'ajax_block_phone'));
        add_action('wp_ajax_guardify_block_ip', array($this, 'ajax_block_ip'));
        add_action('wp_ajax_guardify_block_device', array($this, 'ajax_block_device'));
        add_action('wp_ajax_guardify_block_device_direct', array($this, 'ajax_block_device_direct'));
        add_action('wp_ajax_guardify_unblock_phone', array($this, 'ajax_unblock_phone'));
        add_action('wp_ajax_guardify_unblock_ip', array($this, 'ajax_unblock_ip'));
        add_action('wp_ajax_guardify_unblock_device', array($this, 'ajax_unblock_device'));
        
        // Enqueue scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Add custom columns to order list
     */
    public function add_custom_columns(array $columns): array {
        $new_columns = array();
        
        foreach ($columns as $column_name => $column_info) {
            $new_columns[$column_name] = $column_info;
            
            // Add Courier Report and Duplicate after order_number
            if ($column_name === 'order_number') {
                $new_columns['guardify_courier_report'] = __('Courier Report', 'guardify');
                $new_columns['guardify_duplicate'] = __('Duplicate?', 'guardify');
            }
            
            // Add Block Action at the end (after order_status)
            if ($column_name === 'order_status') {
                // Note: SteadFast columns will be added by Guardify_SteadFast class
            }
        }
        
        // Add Block Action column at the end
        $new_columns['guardify_block_action'] = __('Block Action', 'guardify');
        
        return $new_columns;
    }

    /**
     * Render custom column (HPOS)
     */
    public function render_custom_column(string $column, $order): void {
        $order_id = is_object($order) ? $order->get_id() : $order;
        
        if (!is_object($order)) {
            $order = wc_get_order($order_id);
        }
        
        if (!$order) {
            return;
        }
        
        $this->render_column_content($column, $order);
    }

    /**
     * Render custom column (Legacy)
     */
    public function render_custom_column_legacy(string $column, int $post_id): void {
        $order = wc_get_order($post_id);
        
        if (!$order) {
            return;
        }
        
        $this->render_column_content($column, $order);
    }

    /**
     * Render column content
     */
    private function render_column_content(string $column, $order): void {
        $order_id = $order->get_id();
        $phone = $order->get_billing_phone();
        
        switch ($column) {
            case 'guardify_courier_report':
                $this->render_courier_report_column($order, $phone);
                break;
                
            case 'guardify_duplicate':
                $this->render_duplicate_column($order, $phone);
                break;
                
            case 'guardify_block_action':
                $this->render_block_action_column($order);
                break;
        }
    }

    /**
     * Render Courier Report column
     * Shows global courier data (Steadfast + Pathao) from TansiqLabs Fraud Check API.
     * Links to the TansiqLabs fraud-check page for detailed analysis.
     */
    private function render_courier_report_column($order, string $phone): void {
        if (empty($phone)) {
            echo '<span class="guardify-courier-na">—</span>';
            return;
        }

        // Check for cached global courier data from fraud-check API
        $courier = null;
        if (class_exists('Guardify_Fraud_Check')) {
            $courier = Guardify_Fraud_Check::get_cached_courier_data($phone);
        }

        $has_data = $courier && !empty($courier['totalParcels']);
        $total = $has_data ? intval($courier['totalParcels']) : 0;
        $delivered = $has_data ? intval($courier['totalDelivered']) : 0;
        $cancelled = $has_data ? intval($courier['totalCancelled']) : 0;
        $success_rate = $has_data ? intval($courier['successRate']) : 0;

        // Determine color based on success rate
        $success_class = 'courier-success-low';
        if ($has_data && $success_rate >= 80) {
            $success_class = 'courier-success-high';
        } elseif ($has_data && $success_rate >= 50) {
            $success_class = 'courier-success-medium';
        }

        // Build fraud-check URL with phone parameter for auto-search
        $fraud_check_url = add_query_arg('phone', rawurlencode($phone), 'https://tansiqlabs.com/console/apps/guardify/fraud-check');
        ?>
        <div class="guardify-courier-wrap" data-phone="<?php echo esc_attr($phone); ?>"<?php echo !$has_data ? ' data-needs-courier="1"' : ''; ?> title="<?php esc_attr_e('Global courier data via Guardify Network (Steadfast + Pathao)', 'guardify'); ?>">
            <?php if ($has_data): ?>
                <div class="guardify-courier-stats">
                    <span class="guardify-courier-item guardify-courier-total">
                        <span class="courier-value"><?php echo esc_html($total); ?></span>
                        <span class="courier-label">📦 <?php _e('PARCELS', 'guardify'); ?></span>
                    </span>
                    <span class="guardify-courier-item guardify-courier-delivered">
                        <span class="courier-value"><?php echo esc_html($delivered); ?></span>
                        <span class="courier-label">✅ <?php _e('DELIVERED', 'guardify'); ?></span>
                    </span>
                    <span class="guardify-courier-item guardify-courier-cancelled">
                        <span class="courier-value"><?php echo esc_html($cancelled); ?></span>
                        <span class="courier-label">❌ <?php _e('CANCELLED', 'guardify'); ?></span>
                    </span>
                    <span class="guardify-courier-item guardify-courier-success <?php echo esc_attr($success_class); ?>">
                        <span class="courier-value"><?php echo esc_html($success_rate); ?>%</span>
                        <span class="courier-label">🌐 <?php _e('SUCCESS', 'guardify'); ?></span>
                    </span>
                </div>
            <?php else: ?>
                <div class="guardify-courier-loading">
                    <span class="guardify-courier-loading-text"><?php _e('Loading...', 'guardify'); ?></span>
                </div>
            <?php endif; ?>
            <a href="<?php echo esc_url($fraud_check_url); ?>" target="_blank" rel="noopener" class="guardify-courier-link" title="<?php esc_attr_e('View full fraud report on TansiqLabs', 'guardify'); ?>">
                🔍 <?php _e('Fraud Check', 'guardify'); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Render Duplicate column
     */
    private function render_duplicate_column($order, string $phone): void {
        if (empty($phone)) {
            echo '<span class="guardify-duplicate-na">0%</span>';
            return;
        }
        
        $order_id = $order->get_id();
        $duplicate_info = $this->check_duplicate_order($order);
        $percentage = $duplicate_info['percentage'];
        
        // Determine color based on duplicate percentage
        $class = 'duplicate-low';
        if ($percentage >= 80) {
            $class = 'duplicate-high';
        } elseif ($percentage >= 50) {
            $class = 'duplicate-medium';
        }
        
        ?>
        <span class="guardify-duplicate-badge <?php echo esc_attr($class); ?>" 
              title="<?php echo esc_attr($duplicate_info['reason']); ?>">
            <?php echo esc_html($percentage); ?>%
        </span>
        <?php
    }

    /**
     * Render Block Action column
     */
    private function render_block_action_column($order): void {
        $order_id = $order->get_id();
        $phone = trim($order->get_billing_phone());
        $ip = $order->get_customer_ip_address();
        $device_id = $order->get_meta('_guardify_device_id');
        
        // Normalize phone - remove spaces, dashes, parentheses
        $phone = preg_replace('/[\s\-\(\)]+/', '', $phone);
        
        // Check if already blocked
        $phone_blocked = $this->is_phone_blocked($phone);
        $ip_blocked = $this->is_ip_blocked($ip);
        $device_blocked = !empty($device_id) && $this->is_device_blocked($device_id);
        
        ?>
        <div class="guardify-block-actions" data-order-id="<?php echo esc_attr($order_id); ?>">
            <?php if (!empty($phone)): ?>
                <?php if ($phone_blocked): ?>
                    <button type="button" class="guardify-btn-blocked guardify-btn-phone-blocked" 
                            data-phone="<?php echo esc_attr($phone); ?>" 
                            title="<?php echo esc_attr(sprintf(__('Click to unblock %s', 'guardify'), $phone)); ?>">
                        <?php _e('Phone Blocked', 'guardify'); ?>
                    </button>
                <?php else: ?>
                    <button type="button" class="guardify-btn-block guardify-btn-block-phone" 
                            data-phone="<?php echo esc_attr($phone); ?>"
                            title="<?php echo esc_attr(sprintf(__('Block phone: %s', 'guardify'), $phone)); ?>">
                        <?php _e('Block Phone', 'guardify'); ?>
                    </button>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if (!empty($ip) && $ip !== '0.0.0.0'): ?>
                <?php if ($ip_blocked): ?>
                    <button type="button" class="guardify-btn-blocked guardify-btn-ip-blocked" 
                            data-ip="<?php echo esc_attr($ip); ?>"
                            title="<?php echo esc_attr(sprintf(__('Click to unblock IP: %s', 'guardify'), $ip)); ?>">
                        <?php _e('IP Blocked', 'guardify'); ?>
                    </button>
                <?php else: ?>
                    <button type="button" class="guardify-btn-block guardify-btn-block-ip" 
                            data-ip="<?php echo esc_attr($ip); ?>"
                            title="<?php echo esc_attr(sprintf(__('Block IP: %s', 'guardify'), $ip)); ?>">
                        <?php _e('Block IP', 'guardify'); ?>
                    </button>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if (!empty($device_id) && $device_blocked): ?>
                <button type="button" class="guardify-btn-blocked guardify-btn-device-blocked" 
                        data-device="<?php echo esc_attr($device_id); ?>"
                        data-order-id="<?php echo esc_attr($order_id); ?>"
                        title="<?php echo esc_attr(sprintf(__('Click to unblock device: %s', 'guardify'), substr($device_id, 0, 8) . '...')); ?>">
                    <?php _e('Device Blocked', 'guardify'); ?>
                </button>
            <?php else: ?>
                <button type="button" class="guardify-btn-block guardify-btn-block-device" 
                        data-order-id="<?php echo esc_attr($order_id); ?>"
                        <?php if (!empty($device_id)): ?>data-device="<?php echo esc_attr($device_id); ?>"<?php endif; ?>
                        title="<?php esc_attr_e('Block this device (fingerprint)', 'guardify'); ?>">
                    <?php _e('Block Device', 'guardify'); ?>
                </button>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Check for duplicate order
     * Returns percentage of similarity with recent orders
     *
     * Performance: Uses single SQL query with conditional aggregation instead of 3 separate queries.
     */
    private function check_duplicate_order($order): array {
        $order_id = $order->get_id();
        $phone = $order->get_billing_phone();
        $address = $order->get_billing_address_1();
        $ip = $order->get_customer_ip_address();
        
        $duplicate_score = 0;
        $reasons = array();
        
        if (empty($phone)) {
            return array('percentage' => 0, 'reason' => '');
        }
        
        // Build phone variants for fuzzy matching
        list($phone_clause, $phone_params) = $this->phone_in_clause($phone);
        
        global $wpdb;
        
        try {
            $cutoff_time = gmdate('Y-m-d H:i:s', time() - (24 * 3600));
            
            if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
                \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
                $orders_table = $wpdb->prefix . 'wc_orders';
                $addresses_table = $wpdb->prefix . 'wc_order_addresses';
                
                // Build params: phone_params + ip + address + order_id + cutoff
                $query_params = $phone_params;
                
                $ip_condition = "0";
                if (!empty($ip) && $ip !== '0.0.0.0') {
                    $ip_condition = "SUM(CASE WHEN o.ip_address = %s THEN 1 ELSE 0 END)";
                    $query_params[] = $ip;
                }
                
                $addr_condition = "0";
                if (!empty($address)) {
                    $addr_condition = "SUM(CASE WHEN a.address_1 = %s THEN 1 ELSE 0 END)";
                    $query_params[] = $address;
                }
                
                $query_params[] = $order_id;
                $query_params[] = $cutoff_time;
                
                // Single query — replaces 3 separate queries
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT 
                        SUM(CASE WHEN a.phone {$phone_clause} THEN 1 ELSE 0 END) as same_phone,
                        {$ip_condition} as same_ip,
                        {$addr_condition} as same_address
                    FROM {$orders_table} o
                    INNER JOIN {$addresses_table} a ON o.id = a.order_id AND a.address_type = 'billing'
                    WHERE o.id != %d
                    AND o.date_created_gmt > %s
                    AND o.status NOT IN ('trash', 'wc-cancelled', 'cancelled')",
                    ...$query_params
                ));
                
                if ($row) {
                    $same_phone = (int) $row->same_phone;
                    if ($same_phone > 0) {
                        $duplicate_score += 40;
                        $reasons[] = sprintf(__('%d orders with same phone', 'guardify'), $same_phone);
                    }
                    $same_ip = (int) $row->same_ip;
                    if ($same_ip > 0) {
                        $duplicate_score += 30;
                        $reasons[] = sprintf(__('%d orders with same IP', 'guardify'), $same_ip);
                    }
                    $same_address = (int) $row->same_address;
                    if ($same_address > 0) {
                        $duplicate_score += 30;
                        $reasons[] = sprintf(__('%d orders with same address', 'guardify'), $same_address);
                    }
                }
                
            } else {
                // Legacy query — single combined query
                $same_phone = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                    JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE pm.meta_key = '_billing_phone'
                    AND pm.meta_value {$phone_clause}
                    AND p.ID != %d
                    AND p.post_type = 'shop_order'
                    AND p.post_date > %s
                    AND p.post_status NOT IN ('trash', 'wc-cancelled')",
                    ...array_merge($phone_params, array($order_id, $cutoff_time))
                ));
                
                if ($same_phone > 0) {
                    $duplicate_score += 40;
                    $reasons[] = sprintf(__('%d orders with same phone', 'guardify'), $same_phone);
                }
            }
            
        } catch (Exception $e) {
            // Return 0 on error
        }
        
        // Cap at 100%
        $duplicate_score = min(100, $duplicate_score);
        
        return array(
            'percentage' => $duplicate_score,
            'reason' => implode(', ', $reasons)
        );
    }

    /**
     * Load blocked lists into cache (once per page load)
     */
    private function ensure_blocked_cache(): void {
        if ($this->blocked_phones_cache === null) {
            $this->blocked_phones_cache = (array) get_option('guardify_blocked_phones', array());
        }
        if ($this->blocked_ips_cache === null) {
            $this->blocked_ips_cache = (array) get_option('guardify_blocked_ips', array());
        }
        if ($this->blocked_devices_cache === null) {
            $this->blocked_devices_cache = (array) get_option('guardify_blocked_devices', array());
        }
    }

    /**
     * Check if phone is blocked
     */
    private function is_phone_blocked(string $phone): bool {
        if (empty($phone)) {
            return false;
        }
        
        $this->ensure_blocked_cache();
        $variants = $this->get_phone_variants($phone);
        
        foreach ($variants as $variant) {
            if (in_array($variant, $this->blocked_phones_cache, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if IP is blocked
     */
    private function is_ip_blocked(string $ip): bool {
        if (empty($ip) || $ip === '0.0.0.0') {
            return false;
        }
        
        $this->ensure_blocked_cache();
        return in_array($ip, $this->blocked_ips_cache, true);
    }

    /**
     * Check if device is blocked
     */
    private function is_device_blocked(string $device_id): bool {
        if (empty($device_id)) {
            return false;
        }
        
        $this->ensure_blocked_cache();
        return in_array($device_id, $this->blocked_devices_cache, true);
    }

    /**
     * AJAX: Block phone
     */
    public function ajax_block_phone(): void {
        check_ajax_referer('guardify-order-columns-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
        }
        
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $phone = preg_replace('/[\s\-\(\)]+/', '', $phone);
        
        if (empty($phone)) {
            wp_send_json_error(array('message' => __('Invalid phone number', 'guardify')));
        }
        
        $blocked_phones = get_option('guardify_blocked_phones', array());
        
        if (!is_array($blocked_phones)) {
            $blocked_phones = array();
        }
        
        if (!in_array($phone, $blocked_phones, true)) {
            $blocked_phones[] = $phone;
            update_option('guardify_blocked_phones', $blocked_phones);
        }
        
        wp_send_json_success(array('message' => __('Phone blocked successfully', 'guardify')));
    }

    /**
     * AJAX: Unblock phone
     */
    public function ajax_unblock_phone(): void {
        check_ajax_referer('guardify-order-columns-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
        }
        
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $phone = preg_replace('/[\s\-\(\)]+/', '', $phone);
        
        if (empty($phone)) {
            wp_send_json_error(array('message' => __('Invalid phone number', 'guardify')));
        }
        
        $blocked_phones = get_option('guardify_blocked_phones', array());
        
        if (!is_array($blocked_phones)) {
            $blocked_phones = array();
        }
        
        $blocked_phones = array_values(array_diff($blocked_phones, array($phone)));
        update_option('guardify_blocked_phones', $blocked_phones);
        
        wp_send_json_success(array('message' => __('Phone unblocked successfully', 'guardify')));
    }

    /**
     * AJAX: Block IP
     */
    public function ajax_block_ip(): void {
        check_ajax_referer('guardify-order-columns-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
        }
        
        $ip = isset($_POST['ip']) ? sanitize_text_field($_POST['ip']) : '';
        
        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            wp_send_json_error(array('message' => __('Invalid IP address', 'guardify')));
        }
        
        $blocked_ips = get_option('guardify_blocked_ips', array());
        
        if (!is_array($blocked_ips)) {
            $blocked_ips = array();
        }
        
        if (!in_array($ip, $blocked_ips, true)) {
            $blocked_ips[] = $ip;
            update_option('guardify_blocked_ips', $blocked_ips);
        }
        
        wp_send_json_success(array('message' => __('IP blocked successfully', 'guardify')));
    }

    /**
     * AJAX: Unblock IP
     */
    public function ajax_unblock_ip(): void {
        check_ajax_referer('guardify-order-columns-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
        }
        
        $ip = isset($_POST['ip']) ? sanitize_text_field($_POST['ip']) : '';
        
        if (empty($ip)) {
            wp_send_json_error(array('message' => __('Invalid IP address', 'guardify')));
        }
        
        $blocked_ips = get_option('guardify_blocked_ips', array());
        
        if (!is_array($blocked_ips)) {
            $blocked_ips = array();
        }
        
        $blocked_ips = array_values(array_diff($blocked_ips, array($ip)));
        update_option('guardify_blocked_ips', $blocked_ips);
        
        wp_send_json_success(array('message' => __('IP unblocked successfully', 'guardify')));
    }

    /**
     * AJAX: Block device
     */
    public function ajax_block_device(): void {
        check_ajax_referer('guardify-order-columns-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
        }
        
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        
        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid order', 'guardify')));
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Order not found', 'guardify')));
        }
        
        // Generate device ID from user agent + IP
        $user_agent = $order->get_meta('_customer_user_agent');
        $ip = $order->get_customer_ip_address();
        $device_id = md5($user_agent . $ip);
        
        // Store device ID on order
        $order->update_meta_data('_guardify_device_id', $device_id);
        $order->save();
        
        $blocked_devices = get_option('guardify_blocked_devices', array());
        
        if (!is_array($blocked_devices)) {
            $blocked_devices = array();
        }
        
        if (!in_array($device_id, $blocked_devices, true)) {
            $blocked_devices[] = $device_id;
            update_option('guardify_blocked_devices', $blocked_devices);
        }
        
        wp_send_json_success(array(
            'message' => __('Device blocked successfully', 'guardify'),
            'device_id' => $device_id
        ));
    }

    /**
     * AJAX: Block device directly (with device_id already provided)
     */
    public function ajax_block_device_direct(): void {
        check_ajax_referer('guardify-order-columns-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
        }
        
        $device_id = isset($_POST['device']) ? sanitize_text_field($_POST['device']) : '';
        
        if (empty($device_id)) {
            wp_send_json_error(array('message' => __('Invalid device ID', 'guardify')));
        }
        
        $blocked_devices = get_option('guardify_blocked_devices', array());
        
        if (!is_array($blocked_devices)) {
            $blocked_devices = array();
        }
        
        if (!in_array($device_id, $blocked_devices, true)) {
            $blocked_devices[] = $device_id;
            update_option('guardify_blocked_devices', $blocked_devices);
        }
        
        wp_send_json_success(array(
            'message' => __('Device blocked successfully', 'guardify'),
            'device_id' => $device_id
        ));
    }

    /**
     * AJAX: Unblock device
     */
    public function ajax_unblock_device(): void {
        check_ajax_referer('guardify-order-columns-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
        }
        
        $device_id = isset($_POST['device']) ? sanitize_text_field($_POST['device']) : '';
        
        if (empty($device_id)) {
            wp_send_json_error(array('message' => __('Invalid device', 'guardify')));
        }
        
        $blocked_devices = get_option('guardify_blocked_devices', array());
        
        if (!is_array($blocked_devices)) {
            $blocked_devices = array();
        }
        
        $blocked_devices = array_values(array_diff($blocked_devices, array($device_id)));
        update_option('guardify_blocked_devices', $blocked_devices);
        
        wp_send_json_success(array('message' => __('Device unblocked successfully', 'guardify')));
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts(string $hook): void {
        // Only on order pages and Guardify settings pages
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }
        
        $is_orders_page = (
            $screen->id === 'edit-shop_order' || 
            $screen->id === 'woocommerce_page_wc-orders'
        );
        
        // Also load on Guardify settings pages
        $is_guardify_page = (
            strpos($screen->id, 'guardify') !== false
        );
        
        if (!$is_orders_page && !$is_guardify_page) {
            return;
        }
        
        wp_enqueue_script(
            'guardify-order-columns',
            GUARDIFY_PLUGIN_URL . 'assets/js/order-columns.js',
            array('jquery'),
            GUARDIFY_VERSION,
            true
        );
        
        // Use wp_add_inline_script instead of wp_localize_script
        // This is immune to LiteSpeed Cache JS combination/reordering
        wp_add_inline_script('guardify-order-columns',
            'var guardifyOrderColumns = ' . wp_json_encode(array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('guardify-order-columns-nonce'),
                'fraud_nonce' => wp_create_nonce('guardify_ajax_nonce'),
                'strings' => array(
                    'blocking' => __('Blocking...', 'guardify'),
                    'unblocking' => __('Unblocking...', 'guardify'),
                    'phone_blocked' => __('Phone Blocked', 'guardify'),
                    'ip_blocked' => __('IP Blocked', 'guardify'),
                    'device_blocked' => __('Device Blocked', 'guardify'),
                    'block_phone' => __('Block Phone', 'guardify'),
                    'block_ip' => __('Block IP', 'guardify'),
                    'block_device' => __('Block Device', 'guardify'),
                    'error' => __('Error occurred', 'guardify'),
                    'no_data' => __('No data available', 'guardify'),
                    'confirm_block' => __('Are you sure you want to block this %s?', 'guardify'),
                    'confirm_unblock' => __('Are you sure you want to unblock this %s?', 'guardify'),
                ),
            )) . ';',
            'before'
        );
        
        wp_enqueue_style(
            'guardify-order-columns',
            GUARDIFY_PLUGIN_URL . 'assets/css/order-columns.css',
            array(),
            GUARDIFY_VERSION
        );
    }
}
