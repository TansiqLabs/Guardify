<?php
/**
 * Guardify Advanced Protection Class
 * উন্নত ফ্রড প্রোটেকশন ফিচার - Whitelist, Address Detection, Notifications
 * 
 * @package Guardify
 * @since 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Guardify_Advanced_Protection {
    private static ?self $instance = null;
    
    /**
     * Cache for query results (reduces database calls)
     */
    private array $cache = array();

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Only initialize if any advanced feature is enabled
        if ($this->any_feature_enabled()) {
            // Fraud detection hooks - runs on checkout
            add_action('woocommerce_after_checkout_validation', array($this, 'advanced_fraud_check'), 5, 2);
            add_action('woocommerce_checkout_create_order', array($this, 'advanced_fraud_check_on_create'), 5, 2);
            
            // Cartflows specific hooks
            add_action('cartflows_checkout_before_process_checkout', array($this, 'cartflows_fraud_check'), 1);
            add_action('wcf_checkout_before_process_checkout', array($this, 'cartflows_fraud_check'), 1);
            
            // Log blocked attempts
            add_action('guardify_order_blocked', array($this, 'log_blocked_attempt'), 10, 3);
            
            // Notification hooks
            add_action('guardify_order_blocked', array($this, 'send_notifications'), 10, 3);
        }
    }

    /**
     * Check if any advanced feature is enabled
     */
    private function any_feature_enabled(): bool {
        return $this->is_whitelist_enabled() || 
               $this->is_address_detection_enabled() || 
               $this->is_notification_enabled();
    }

    // ========================================
    // WHITELIST FEATURE
    // ========================================
    
    /**
     * Check if whitelist feature is enabled
     */
    public function is_whitelist_enabled(): bool {
        return get_option('guardify_whitelist_enabled', '0') === '1';
    }

    /**
     * Check if phone is whitelisted
     */
    public function is_phone_whitelisted(string $phone): bool {
        if (!$this->is_whitelist_enabled()) {
            return false;
        }

        $phone = $this->normalize_phone($phone);
        $whitelist = $this->get_whitelisted_phones();
        
        return in_array($phone, $whitelist, true);
    }

    /**
     * Check if IP is whitelisted
     */
    public function is_ip_whitelisted(string $ip): bool {
        if (!$this->is_whitelist_enabled()) {
            return false;
        }

        $whitelist = $this->get_whitelisted_ips();
        
        // Check exact match
        if (in_array($ip, $whitelist, true)) {
            return true;
        }
        
        // Check IP range (CIDR notation support)
        foreach ($whitelist as $whitelist_ip) {
            if (strpos($whitelist_ip, '/') !== false) {
                if ($this->ip_in_range($ip, $whitelist_ip)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Get whitelisted phones
     */
    public function get_whitelisted_phones(): array {
        $phones_string = get_option('guardify_whitelisted_phones', '');
        if (empty($phones_string)) {
            return array();
        }
        
        $phones = array_map('trim', explode("\n", $phones_string));
        $phones = array_filter($phones);
        $phones = array_map(array($this, 'normalize_phone'), $phones);
        
        return $phones;
    }

    /**
     * Get whitelisted IPs
     */
    public function get_whitelisted_ips(): array {
        $ips_string = get_option('guardify_whitelisted_ips', '');
        if (empty($ips_string)) {
            return array();
        }
        
        $ips = array_map('trim', explode("\n", $ips_string));
        $ips = array_filter($ips);
        
        return $ips;
    }

    /**
     * Check if IP is in CIDR range
     */
    private function ip_in_range(string $ip, string $cidr): bool {
        list($subnet, $mask) = explode('/', $cidr);
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip_long = ip2long($ip);
            $subnet_long = ip2long($subnet);
            $mask_long = -1 << (32 - (int)$mask);
            
            return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
        }
        
        return false;
    }

    // ========================================
    // ADVANCED FRAUD DETECTION
    // ========================================

    /**
     * Check if address detection is enabled
     */
    public function is_address_detection_enabled(): bool {
        return get_option('guardify_address_detection_enabled', '0') === '1';
    }

    /**
     * Check if name similarity detection is enabled
     */
    public function is_name_similarity_enabled(): bool {
        return get_option('guardify_name_similarity_enabled', '0') === '1';
    }

    /**
     * Advanced fraud check during checkout validation
     */
    public function advanced_fraud_check(array $data, \WP_Error $errors): void {
        // Skip for admins
        if (current_user_can('manage_options')) {
            return;
        }

        // Skip for trusted customers (3+ orders)
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $order_count = wc_get_customer_order_count($user_id);
            if ($order_count > 2) {
                return;
            }
        }

        // Skip for whitelisted phone
        $phone = isset($data['billing_phone']) ? sanitize_text_field($data['billing_phone']) : '';
        if (!empty($phone) && $this->is_phone_whitelisted($phone)) {
            return;
        }
        
        // Skip for whitelisted IP
        $ip = $this->get_client_ip();
        if ($this->is_ip_whitelisted($ip)) {
            return;
        }

        // Check same address fraud
        if ($this->is_address_detection_enabled()) {
            $address = $this->build_address_string($data);
            if (!empty($address)) {
                $address_orders = $this->count_orders_by_address($address);
                $max_orders = absint(get_option('guardify_max_orders_per_address', '5'));
                
                if ($address_orders >= $max_orders) {
                    $message = get_option('guardify_address_block_message', 'এই ঠিকানা থেকে অনেক অর্ডার হয়েছে। অনুগ্রহ করে যোগাযোগ করুন।');
                    $errors->add('guardify_address_fraud', $message);
                    
                    do_action('guardify_order_blocked', 'address', $data, $address_orders);
                    return;
                }
            }
        }

        // Check similar name fraud
        if ($this->is_name_similarity_enabled()) {
            $first_name = isset($data['billing_first_name']) ? sanitize_text_field($data['billing_first_name']) : '';
            $last_name = isset($data['billing_last_name']) ? sanitize_text_field($data['billing_last_name']) : '';
            $name = trim($first_name . ' ' . $last_name);
            
            if (!empty($name) && strlen($name) >= 3 && $this->has_similar_name_orders($name, $phone)) {
                $message = get_option('guardify_similar_name_message', 'সন্দেহজনক অর্ডার প্যাটার্ন সনাক্ত হয়েছে।');
                $errors->add('guardify_name_fraud', $message);
                
                do_action('guardify_order_blocked', 'similar_name', $data, $name);
                return;
            }
        }
    }

    /**
     * Cartflows specific fraud check
     * Runs before Cartflows processes checkout
     */
    public function cartflows_fraud_check(): void {
        // Skip for admins
        if (current_user_can('manage_options')) {
            return;
        }

        // Skip for trusted customers
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $order_count = wc_get_customer_order_count($user_id);
            if ($order_count > 2) {
                return;
            }
        }

        // Get data from POST
        $phone = isset($_POST['billing_phone']) ? sanitize_text_field($_POST['billing_phone']) : '';
        $ip = $this->get_client_ip();

        // Skip for whitelisted phone/IP
        if (!empty($phone) && $this->is_phone_whitelisted($phone)) {
            return;
        }
        if ($this->is_ip_whitelisted($ip)) {
            return;
        }

        // Check address detection
        if ($this->is_address_detection_enabled()) {
            $address_data = array(
                'billing_address_1' => isset($_POST['billing_address_1']) ? sanitize_text_field($_POST['billing_address_1']) : '',
                'billing_city' => isset($_POST['billing_city']) ? sanitize_text_field($_POST['billing_city']) : '',
                'billing_postcode' => isset($_POST['billing_postcode']) ? sanitize_text_field($_POST['billing_postcode']) : '',
            );
            
            $address = $this->build_address_string($address_data);
            if (!empty($address)) {
                $address_orders = $this->count_orders_by_address($address);
                $max_orders = absint(get_option('guardify_max_orders_per_address', '5'));
                
                if ($address_orders >= $max_orders) {
                    $message = get_option('guardify_address_block_message', 'এই ঠিকানা থেকে অনেক অর্ডার হয়েছে।');
                    wc_add_notice($message, 'error');
                    do_action('guardify_order_blocked', 'address', $_POST, $address_orders);
                    return;
                }
            }
        }

        // Check name similarity
        if ($this->is_name_similarity_enabled()) {
            $first_name = isset($_POST['billing_first_name']) ? sanitize_text_field($_POST['billing_first_name']) : '';
            $last_name = isset($_POST['billing_last_name']) ? sanitize_text_field($_POST['billing_last_name']) : '';
            $name = trim($first_name . ' ' . $last_name);
            
            if (!empty($name) && strlen($name) >= 3 && $this->has_similar_name_orders($name, $phone)) {
                $message = get_option('guardify_similar_name_message', 'সন্দেহজনক অর্ডার প্যাটার্ন সনাক্ত হয়েছে।');
                wc_add_notice($message, 'error');
                do_action('guardify_order_blocked', 'similar_name', $_POST, $name);
                return;
            }
        }
    }

    /**
     * Fraud check before order creation
     */
    public function advanced_fraud_check_on_create($order, $data): void {
        // Skip for admins
        if (current_user_can('manage_options')) {
            return;
        }

        // Skip for trusted customers
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $order_count = wc_get_customer_order_count($user_id);
            if ($order_count > 2) {
                return;
            }
        }

        $phone = $order->get_billing_phone();
        
        // Skip whitelisted phone
        if (!empty($phone) && $this->is_phone_whitelisted($phone)) {
            return;
        }
        
        // Skip whitelisted IP
        $ip = $this->get_client_ip();
        if ($this->is_ip_whitelisted($ip)) {
            return;
        }

        // Build address from order
        $address_data = array(
            'billing_address_1' => $order->get_billing_address_1(),
            'billing_address_2' => $order->get_billing_address_2(),
            'billing_city' => $order->get_billing_city(),
            'billing_postcode' => $order->get_billing_postcode(),
        );

        // Check address
        if ($this->is_address_detection_enabled()) {
            $address = $this->build_address_string($address_data);
            if (!empty($address)) {
                $address_orders = $this->count_orders_by_address($address);
                $max_orders = absint(get_option('guardify_max_orders_per_address', '5'));
                
                if ($address_orders >= $max_orders) {
                    $message = get_option('guardify_address_block_message', 'এই ঠিকানা থেকে অনেক অর্ডার হয়েছে।');
                    throw new \Exception($message);
                }
            }
        }
    }

    /**
     * Build address string for comparison
     */
    private function build_address_string(array $data): string {
        $parts = array();
        
        if (!empty($data['billing_address_1'])) {
            $parts[] = strtolower(trim($data['billing_address_1']));
        }
        if (!empty($data['billing_city'])) {
            $parts[] = strtolower(trim($data['billing_city']));
        }
        if (!empty($data['billing_postcode'])) {
            $parts[] = trim($data['billing_postcode']);
        }
        
        $address = implode('|', $parts);
        
        // Remove common variations
        $address = preg_replace('/\s+/', ' ', $address);
        $address = str_replace(array(',', '.', '-'), '', $address);
        
        return $address;
    }

    /**
     * Count orders by address (with caching)
     */
    private function count_orders_by_address(string $address): int {
        if (empty($address)) {
            return 0;
        }

        // Check cache first
        $cache_key = 'address_' . md5($address);
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $time_limit = absint(get_option('guardify_address_time_hours', '24'));
        $cutoff_time = time() - ($time_limit * 3600);

        global $wpdb;

        try {
            if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
                \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
                // HPOS
                $orders_table = $wpdb->prefix . 'wc_orders';
                
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$orders_table}
                    WHERE LOWER(CONCAT(COALESCE(billing_address_1,''), '|', COALESCE(billing_city,''), '|', COALESCE(billing_postcode,''))) LIKE %s
                    AND date_created_gmt > %s
                    AND status NOT IN ('trash', 'wc-cancelled', 'wc-failed', 'cancelled', 'failed')",
                    '%' . $wpdb->esc_like($address) . '%',
                    gmdate('Y-m-d H:i:s', $cutoff_time)
                ));
            } else {
                // Legacy
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
                    JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_billing_address_1'
                    JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_billing_city'
                    WHERE p.post_type = 'shop_order'
                    AND p.post_date > %s
                    AND p.post_status NOT IN ('trash', 'wc-cancelled', 'wc-failed')
                    AND LOWER(CONCAT(COALESCE(pm1.meta_value,''), '|', COALESCE(pm2.meta_value,''))) LIKE %s",
                    date('Y-m-d H:i:s', $cutoff_time),
                    '%' . $wpdb->esc_like($address) . '%'
                ));
            }

            $this->cache[$cache_key] = intval($count);
            return intval($count);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Check for similar name orders
     */
    private function has_similar_name_orders(string $name, string $phone): bool {
        if (empty($name) || strlen($name) < 3) {
            return false;
        }

        $phone = $this->normalize_phone($phone);
        $time_limit = absint(get_option('guardify_name_check_hours', '24'));
        $cutoff_time = time() - ($time_limit * 3600);
        $similarity_threshold = absint(get_option('guardify_name_similarity_threshold', '80'));

        global $wpdb;

        try {
            if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
                \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
                // HPOS
                $orders_table = $wpdb->prefix . 'wc_orders';
                
                $results = $wpdb->get_results($wpdb->prepare(
                    "SELECT CONCAT(billing_first_name, ' ', billing_last_name) as full_name, billing_phone
                    FROM {$orders_table}
                    WHERE date_created_gmt > %s
                    AND billing_phone != %s
                    AND status NOT IN ('trash', 'wc-cancelled', 'wc-failed', 'cancelled', 'failed')
                    LIMIT 100",
                    gmdate('Y-m-d H:i:s', $cutoff_time),
                    $phone
                ));
            } else {
                // Legacy
                $results = $wpdb->get_results($wpdb->prepare(
                    "SELECT 
                        CONCAT(pm1.meta_value, ' ', pm2.meta_value) as full_name,
                        pm3.meta_value as billing_phone
                    FROM {$wpdb->posts} p
                    JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_billing_first_name'
                    JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_billing_last_name'
                    JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_billing_phone'
                    WHERE p.post_type = 'shop_order'
                    AND p.post_date > %s
                    AND pm3.meta_value != %s
                    AND p.post_status NOT IN ('trash', 'wc-cancelled', 'wc-failed')
                    LIMIT 100",
                    date('Y-m-d H:i:s', $cutoff_time),
                    $phone
                ));
            }

            // Check similarity
            $name_lower = strtolower($name);
            foreach ($results as $row) {
                $existing_name = strtolower(trim($row->full_name));
                similar_text($name_lower, $existing_name, $percent);
                
                if ($percent >= $similarity_threshold) {
                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    // ========================================
    // NOTIFICATION SYSTEM
    // ========================================

    /**
     * Check if notifications are enabled
     */
    public function is_notification_enabled(): bool {
        return get_option('guardify_notification_enabled', '0') === '1';
    }

    /**
     * Send notifications when order is blocked
     */
    public function send_notifications(string $type, array $data, $extra_info): void {
        if (!$this->is_notification_enabled()) {
            return;
        }

        // Email notification
        if (get_option('guardify_email_notification', '1') === '1') {
            $this->send_email_notification($type, $data, $extra_info);
        }
    }

    /**
     * Send email notification
     */
    private function send_email_notification(string $type, array $data, $extra_info): void {
        $admin_email = get_option('guardify_notification_email', get_option('admin_email'));
        
        if (empty($admin_email)) {
            return;
        }

        $phone = isset($data['billing_phone']) ? $data['billing_phone'] : 'N/A';
        $name = isset($data['billing_first_name']) ? $data['billing_first_name'] . ' ' . $data['billing_last_name'] : 'N/A';
        $ip = $this->get_client_ip();

        $type_labels = array(
            'phone' => 'ফোন কুলডাউন',
            'ip' => 'আইপি কুলডাউন',
            'address' => 'একই ঠিকানা',
            'similar_name' => 'সন্দেহজনক নাম',
            'vpn' => 'VPN সনাক্ত',
        );

        $type_label = isset($type_labels[$type]) ? $type_labels[$type] : $type;

        $subject = sprintf('[Guardify] অর্ডার ব্লক হয়েছে - %s', $type_label);
        
        $message = sprintf(
            "একটি সন্দেহজনক অর্ডার ব্লক করা হয়েছে।\n\n" .
            "ব্লক টাইপ: %s\n" .
            "নাম: %s\n" .
            "ফোন: %s\n" .
            "IP: %s\n" .
            "সময়: %s\n\n" .
            "অতিরিক্ত তথ্য: %s\n\n" .
            "— Guardify",
            $type_label,
            $name,
            $phone,
            $ip,
            current_time('mysql'),
            print_r($extra_info, true)
        );

        wp_mail($admin_email, $subject, $message);
    }

    /**
     * Log blocked attempt for analytics
     */
    public function log_blocked_attempt(string $type, array $data, $extra_info): void {
        $log = get_option('guardify_blocked_log', array());
        
        // Keep only last 500 entries
        if (count($log) > 500) {
            $log = array_slice($log, -500);
        }
        
        $ip = $this->get_client_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';

        $log[] = array(
            'type' => $type,
            'phone' => isset($data['billing_phone']) ? $data['billing_phone'] : '',
            'name' => isset($data['billing_first_name']) ? $data['billing_first_name'] . ' ' . $data['billing_last_name'] : '',
            'ip' => $ip,
            'user_agent' => $user_agent,
            'time' => time(),
            'extra' => $extra_info,
        );

        update_option('guardify_blocked_log', $log, false);

        // Update daily counter (use WP timezone)
        $today = wp_date('Y-m-d');
        $daily_stats = get_option('guardify_daily_stats', array());
        if (!isset($daily_stats[$today])) {
            $daily_stats[$today] = array('total' => 0, 'types' => array());
        }
        $daily_stats[$today]['total']++;
        if (!isset($daily_stats[$today]['types'][$type])) {
            $daily_stats[$today]['types'][$type] = 0;
        }
        $daily_stats[$today]['types'][$type]++;
        
        // Keep only last 30 days
        $daily_stats = array_slice($daily_stats, -30, 30, true);
        update_option('guardify_daily_stats', $daily_stats, false);
    }

    // ========================================
    // UTILITY FUNCTIONS
    // ========================================

    /**
     * Normalize phone number
     */
    private function normalize_phone(string $phone): string {
        return preg_replace('/[\s\-\(\)]+/', '', $phone);
    }

    /**
     * Get client IP
     */
    private function get_client_ip(): string {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR'
        );

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    // ========================================
    // ANALYTICS DATA
    // ========================================

    /**
     * Get blocked attempts statistics
     */
    public function get_blocked_stats(string $period = 'week'): array {
        $daily_stats = get_option('guardify_daily_stats', array());
        
        $days = $period === 'month' ? 30 : 7;
        $stats = array(
            'total' => 0,
            'by_type' => array(),
            'by_day' => array(),
        );

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            
            if (isset($daily_stats[$date])) {
                $stats['total'] += $daily_stats[$date]['total'];
                $stats['by_day'][$date] = $daily_stats[$date]['total'];
                
                foreach ($daily_stats[$date]['types'] as $type => $count) {
                    if (!isset($stats['by_type'][$type])) {
                        $stats['by_type'][$type] = 0;
                    }
                    $stats['by_type'][$type] += $count;
                }
            } else {
                $stats['by_day'][$date] = 0;
            }
        }

        return $stats;
    }

    /**
     * Get recent blocked attempts
     */
    public function get_recent_blocked(int $limit = 20): array {
        $log = get_option('guardify_blocked_log', array());
        return array_slice(array_reverse($log), 0, $limit);
    }
}
