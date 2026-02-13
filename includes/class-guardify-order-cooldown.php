<?php
/**
 * Guardify Order Cooldown Class
 * একই নাম্বার বা আইপি থেকে নির্দিষ্ট সময়ের মধ্যে পুনরায় অর্ডার ব্লক করা
 * Cartflows এর সাথে সম্পূর্ণ compatible
 * 
 * @package Guardify
 * @since 1.0.0
 * @updated 2.1.0 - Cartflows support, HPOS IP query fix, early AJAX intercept
 */

if (!defined('ABSPATH')) {
    exit;
}

class Guardify_Order_Cooldown {
    private static ?self $instance = null;

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Initialize hooks ONLY if at least one cooldown feature is enabled
        // This prevents unnecessary processing when features are disabled
        if ($this->is_phone_cooldown_enabled() || $this->is_ip_cooldown_enabled()) {
            // Standard WooCommerce checkout
            add_action('woocommerce_checkout_process', array($this, 'check_cooldown'), 1);
            add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
            
            // AJAX handlers
            add_action('wp_ajax_guardify_check_cooldown', array($this, 'ajax_check_cooldown'));
            add_action('wp_ajax_nopriv_guardify_check_cooldown', array($this, 'ajax_check_cooldown'));
            
            // Support for WooCommerce Block Checkout
            add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'check_cooldown_block_checkout'), 5, 2);
            
            // ============================================
            // CARTFLOWS SPECIFIC HOOKS - VERY IMPORTANT!
            // ============================================
            // Hook into Cartflows checkout validation (before order is created)
            add_action('woocommerce_after_checkout_validation', array($this, 'check_cooldown_validation'), 1, 2);
            
            // Before order is created hook - this is the FINAL defense
            add_action('woocommerce_checkout_create_order', array($this, 'check_cooldown_before_order_create'), 1, 2);
            
            // Early AJAX intercept for ALL checkout AJAX requests
            add_action('wp_loaded', array($this, 'early_checkout_intercept'), 1);
            
            // Cartflows specific checkout step hook
            add_action('cartflows_checkout_before_process_checkout', array($this, 'check_cooldown'), 1);
            add_action('wcf_checkout_before_process_checkout', array($this, 'check_cooldown'), 1);
            
            // Auto-fail orders that violate cooldown (catches draft/incomplete orders from Cartflows etc.)
            add_action('woocommerce_new_order', array($this, 'check_cooldown_on_new_order'), 5, 2);
            add_action('woocommerce_checkout_order_created', array($this, 'check_cooldown_on_new_order'), 5, 2);
        }
        
        // Always store IP address on order creation (for future reference/analytics)
        add_action('woocommerce_checkout_create_order', array($this, 'store_ip_address'), 10, 1);
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'store_ip_address_from_api'), 10, 2);
        
        // Admin AJAX for debug/test
        add_action('wp_ajax_guardify_debug_ip', array($this, 'ajax_debug_ip'));
    }
    
    /**
     * AJAX handler for IP debugging (admin only)
     */
    public function ajax_debug_ip(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $ip = $this->get_client_ip();
        $has_recent_order = $this->has_recent_order_by_ip($ip);
        
        global $wpdb;
        
        // Get recent orders with this IP
        $recent_orders = array();
        
        if (class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil') && 
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            // HPOS enabled
            $table_name = $wpdb->prefix . 'wc_orders_meta';
            $orders_table = $wpdb->prefix . 'wc_orders';
            
            $recent_orders = $wpdb->get_results($wpdb->prepare(
                "SELECT o.id, o.status, o.date_created_gmt, om.meta_value as ip
                FROM {$table_name} om
                JOIN {$orders_table} o ON om.order_id = o.id
                WHERE om.meta_key = '_customer_ip_address'
                AND om.meta_value = %s
                ORDER BY o.date_created_gmt DESC
                LIMIT 5",
                $ip
            ));
        } else {
            // Legacy
            $recent_orders = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID as id, p.post_status as status, p.post_date as date_created_gmt, pm.meta_value as ip
                FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE pm.meta_key = '_customer_ip_address'
                AND pm.meta_value = %s
                AND p.post_type = 'shop_order'
                ORDER BY p.post_date DESC
                LIMIT 5",
                $ip
            ));
        }
        
        wp_send_json_success(array(
            'current_ip' => $ip,
            'ip_cooldown_enabled' => $this->is_ip_cooldown_enabled(),
            'ip_cooldown_time' => $this->get_ip_cooldown_time() . ' hours',
            'has_recent_order' => $has_recent_order,
            'recent_orders_with_ip' => $recent_orders,
            'hpos_enabled' => class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil') && 
                \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
        ));
    }

    /**
     * Check if phone cooldown is enabled
     */
    public function is_phone_cooldown_enabled(): bool {
        return get_option('guardify_phone_cooldown_enabled', '0') === '1';
    }

    /**
     * Check if IP cooldown is enabled
     */
    public function is_ip_cooldown_enabled(): bool {
        return get_option('guardify_ip_cooldown_enabled', '0') === '1';
    }

    /**
     * Get phone cooldown time in hours
     */
    public function get_phone_cooldown_time(): int {
        return absint(get_option('guardify_phone_cooldown_time', '24'));
    }

    /**
     * Get IP cooldown time in hours
     */
    public function get_ip_cooldown_time(): int {
        return absint(get_option('guardify_ip_cooldown_time', '1'));
    }

    /**
     * Get phone cooldown message
     */
    public function get_phone_cooldown_message(): string {
        $message = get_option('guardify_phone_cooldown_message', 'আপনি ইতিমধ্যে এই নাম্বার থেকে অর্ডার করেছেন। অনুগ্রহ করে %d ঘন্টা পর আবার চেষ্টা করুন।');
        return sprintf($message, $this->get_phone_cooldown_time());
    }

    /**
     * Get IP cooldown message
     */
    public function get_ip_cooldown_message(): string {
        $message = get_option('guardify_ip_cooldown_message', 'আপনি ইতিমধ্যে অর্ডার করেছেন। অনুগ্রহ করে %d ঘন্টা পর আবার চেষ্টা করুন।');
        return sprintf($message, $this->get_ip_cooldown_time());
    }

    /**
     * Enqueue scripts
     */
    public function enqueue_scripts(): void {
        // Check for standard WooCommerce checkout or Cartflows checkout
        $is_checkout_page = is_checkout();
        
        // Also check for Cartflows step pages
        if (!$is_checkout_page && function_exists('wcf_get_current_step_type')) {
            $step_type = wcf_get_current_step_type();
            if ($step_type === 'checkout') {
                $is_checkout_page = true;
            }
        }
        
        // Fallback: Check for Cartflows shortcode or common landing page patterns
        if (!$is_checkout_page) {
            global $post;
            if ($post && (
                has_shortcode($post->post_content, 'cartflows_checkout') ||
                has_shortcode($post->post_content, 'woocommerce_checkout') ||
                strpos($post->post_content, 'wcf-checkout') !== false
            )) {
                $is_checkout_page = true;
            }
        }
        
        if (!$is_checkout_page) {
            return;
        }

        if (!$this->is_phone_cooldown_enabled() && !$this->is_ip_cooldown_enabled()) {
            return;
        }

        wp_enqueue_script(
            'guardify-order-cooldown',
            GUARDIFY_PLUGIN_URL . 'assets/js/order-cooldown.js',
            array('jquery'),
            GUARDIFY_VERSION,
            array(
                'in_footer' => true,
                'strategy'  => 'defer', // Defer script loading for performance
            )
        );

        // Use wp_add_inline_script instead of wp_localize_script
        // This is immune to LiteSpeed Cache JS combination/reordering
        wp_add_inline_script(
            'guardify-order-cooldown',
            'var guardifyOrderCooldown = ' . wp_json_encode(array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('guardify-cooldown-nonce'),
                'phoneCooldownEnabled' => $this->is_phone_cooldown_enabled(),
                'ipCooldownEnabled' => $this->is_ip_cooldown_enabled(),
                'phoneCooldownTime' => $this->get_phone_cooldown_time(),
                'ipCooldownTime' => $this->get_ip_cooldown_time(),
                'phoneCooldownMessage' => $this->get_phone_cooldown_message(),
                'ipCooldownMessage' => $this->get_ip_cooldown_message(),
                'contactNumber' => get_option('guardify_contact_number', ''),
            )) . ';',
            'before'
        );
    }

    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP', // Nginx proxy
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs (for proxies)
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
                // If validation failed, try basic validation
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
    
    /**
     * Get current client IP (public method for debugging)
     */
    public function get_current_ip() {
        return $this->get_client_ip();
    }
    
    /**
     * Store IP address on order creation
     * Note: WooCommerce automatically stores _customer_ip_address
     * We just ensure it's set correctly if WC missed it
     */
    public function store_ip_address($order): void {
        if (is_numeric($order)) {
            $order = wc_get_order($order);
        }
        
        if (!$order) {
            return;
        }
        
        // Only set if not already set by WooCommerce
        $existing_ip = $order->get_customer_ip_address();
        if (empty($existing_ip) || $existing_ip === '0.0.0.0') {
            $ip = $this->get_client_ip();
            $order->set_customer_ip_address($ip);
            // Don't call save() here - let WooCommerce handle it
            // The order will be saved by WooCommerce after checkout
        }
    }
    
    /**
     * Store IP address from Store API (Block checkout)
     */
    public function store_ip_address_from_api($order, $request): void {
        $this->store_ip_address($order);
    }

    /**
     * Check for recent orders by phone number
     * ইনকমপ্লিট অর্ডার সহ সব অর্ডার চেক করা হবে
     */
    public function has_recent_order_by_phone($phone): bool {
        if (!$this->is_phone_cooldown_enabled()) {
            return false;
        }

        $time_limit = $this->get_phone_cooldown_time();
        
        // Normalize phone number - remove spaces, dashes, parentheses
        $phone = preg_replace('/[\s\-\(\)]+/', '', $phone);
        
        if (empty($phone)) {
            return false;
        }

        // Check whitelist - skip cooldown for whitelisted phones
        if (class_exists('Guardify_Advanced_Protection')) {
            $advanced = Guardify_Advanced_Protection::get_instance();
            if ($advanced->is_phone_whitelisted($phone)) {
                return false;
            }
        }

        // Use UTC time for consistency
        $cutoff_time = time() - ($time_limit * 3600);
        $cutoff_date_gmt = gmdate('Y-m-d H:i:s', $cutoff_time);
        $cutoff_date_local = date('Y-m-d H:i:s', $cutoff_time);

        global $wpdb;
        
        try {
            // Check for HPOS (High-Performance Order Storage)
            if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
                \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
                // HPOS enabled - direct query for accuracy
                $orders_table = $wpdb->prefix . 'wc_orders';
                
                // Include all non-cancelled/failed statuses including pending, on-hold
                $result = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$orders_table}
                    WHERE billing_phone = %s
                    AND date_created_gmt > %s
                    AND status NOT IN ('trash', 'wc-cancelled', 'wc-failed', 'cancelled', 'failed')",
                    $phone,
                    $cutoff_date_gmt
                ));
            } else {
                // Legacy post meta - include shop_order and shop_order_placehold (for drafts)
                $result = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                    JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE pm.meta_key = '_billing_phone'
                    AND pm.meta_value = %s
                    AND p.post_type IN ('shop_order', 'shop_order_placehold')
                    AND p.post_date > %s
                    AND p.post_status NOT IN ('trash', 'wc-cancelled', 'wc-failed')",
                    $phone,
                    $cutoff_date_local
                ));
            }
            
            $found = intval($result) > 0;

            return $found;
        } catch (Exception $e) {
            // Don't block on error, only log in debug mode
            return false;
        }
    }

    /**
     * Check for recent orders by IP address
     * একই আইপি থেকে যেকোনো ফোন নাম্বার দিয়ে অর্ডার ব্লক করা হবে
     * ইনকমপ্লিট অর্ডার (pending, on-hold) সহ সব চেক করা হবে
     */
    public function has_recent_order_by_ip($ip): bool {
        if (!$this->is_ip_cooldown_enabled()) {
            return false;
        }
        
        if (empty($ip) || $ip === '0.0.0.0' || $ip === '127.0.0.1' || $ip === '::1') {
            return false;
        }

        // Check whitelist - skip cooldown for whitelisted IPs
        if (class_exists('Guardify_Advanced_Protection')) {
            $advanced = Guardify_Advanced_Protection::get_instance();
            if ($advanced->is_ip_whitelisted($ip)) {
                return false;
            }
        }

        $time_limit = $this->get_ip_cooldown_time();
        
        // Use UTC time for consistency
        $cutoff_time = time() - ($time_limit * 3600);
        $cutoff_date_gmt = gmdate('Y-m-d H:i:s', $cutoff_time);
        $cutoff_date_local = date('Y-m-d H:i:s', $cutoff_time);

        global $wpdb;
        
        try {
            // Check for HPOS (High-Performance Order Storage)
            if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
                \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
                // HPOS enabled - ip_address is stored directly in wc_orders table
                $orders_table = $wpdb->prefix . 'wc_orders';
                
                // First try direct column (newer HPOS)
                $result = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$orders_table}
                    WHERE ip_address = %s
                    AND date_created_gmt > %s
                    AND status NOT IN ('trash', 'wc-cancelled', 'wc-failed', 'cancelled', 'failed')",
                    $ip,
                    $cutoff_date_gmt
                ));
                
                // If no result, try meta table (older HPOS or custom setup)
                if (intval($result) === 0) {
                    $table_name = $wpdb->prefix . 'wc_orders_meta';
                    $result = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table_name} om
                        JOIN {$orders_table} o ON om.order_id = o.id
                        WHERE om.meta_key = '_customer_ip_address'
                        AND om.meta_value = %s
                        AND o.date_created_gmt > %s
                        AND o.status NOT IN ('trash', 'wc-cancelled', 'wc-failed', 'cancelled', 'failed')",
                        $ip,
                        $cutoff_date_gmt
                    ));
                }
            } else {
                // Legacy post meta - include shop_order and shop_order_placehold (for drafts)
                $result = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                    JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE pm.meta_key = '_customer_ip_address'
                    AND pm.meta_value = %s
                    AND p.post_type IN ('shop_order', 'shop_order_placehold')
                    AND p.post_date > %s
                    AND p.post_status NOT IN ('trash', 'wc-cancelled', 'wc-failed')",
                    $ip,
                    $cutoff_date_local
                ));
            }

            return intval($result) > 0;
        } catch (Exception $e) {
            return false; // Don't block on error
        }
    }

    /**
     * Check cooldown during checkout
     */
    public function check_cooldown() {
        try {
            // Skip for admins
            if (current_user_can('manage_options')) {
                return;
            }

            // Skip for logged in users with previous successful orders (trusted customers)
            if (is_user_logged_in()) {
                $user_id = get_current_user_id();
                $order_count = wc_get_customer_order_count($user_id);
                if ($order_count > 2) {
                    return; // Trusted customer with 3+ orders, skip cooldown
                }
            }

            // Check phone cooldown
            if ($this->is_phone_cooldown_enabled() && isset($_POST['billing_phone'])) {
                $phone = sanitize_text_field($_POST['billing_phone']);
                if (!empty($phone) && $this->has_recent_order_by_phone($phone)) {
                    // Log blocked attempt
                    $this->log_blocked_attempt('phone', $_POST);
                    wc_add_notice($this->get_phone_cooldown_message(), 'error');
                    return;
                }
            }

            // Check IP cooldown
            if ($this->is_ip_cooldown_enabled()) {
                $ip = $this->get_client_ip();
                if ($this->has_recent_order_by_ip($ip)) {
                    // Log blocked attempt
                    $this->log_blocked_attempt('ip', $_POST);
                    wc_add_notice($this->get_ip_cooldown_message(), 'error');
                    return;
                }
            }
        } catch (Exception $e) {
            // Don't block on error
        }
    }
    
    /**
     * Log blocked attempt for analytics
     */
    private function log_blocked_attempt(string $type, array $data): void {
        if (class_exists('Guardify_Advanced_Protection')) {
            do_action('guardify_order_blocked', $type, $data, null);
        }
    }
    
    /**
     * Check cooldown during checkout validation (works with Cartflows)
     * This is the MAIN hook that catches Cartflows checkout
     * 
     * @param array $data Checkout data
     * @param \WP_Error $errors Validation errors
     */
    public function check_cooldown_validation($data, $errors): void {
        try {
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

            // Check phone cooldown
            if ($this->is_phone_cooldown_enabled()) {
                $phone = isset($data['billing_phone']) ? $data['billing_phone'] : '';
                if (empty($phone) && isset($_POST['billing_phone'])) {
                    $phone = sanitize_text_field($_POST['billing_phone']);
                }
                if (!empty($phone) && $this->has_recent_order_by_phone($phone)) {
                    // Log blocked attempt
                    $this->log_blocked_attempt('phone', $data);
                    $errors->add('guardify_phone_cooldown', $this->get_phone_cooldown_message());
                    return;
                }
            }

            // Check IP cooldown
            if ($this->is_ip_cooldown_enabled()) {
                $ip = $this->get_client_ip();
                if ($this->has_recent_order_by_ip($ip)) {
                    // Log blocked attempt
                    $this->log_blocked_attempt('ip', $data);
                    $errors->add('guardify_ip_cooldown', $this->get_ip_cooldown_message());
                    return;
                }
            }
        } catch (Exception $e) {
            // Don't block on error
        }
    }
    
    /**
     * Early intercept for ALL checkout AJAX requests
     * This runs BEFORE WooCommerce/Cartflows processes the checkout
     */
    public function early_checkout_intercept(): void {
        // Only run for AJAX requests
        if (!wp_doing_ajax() && !defined('DOING_AJAX')) {
            return;
        }
        
        // Check if this is a checkout request
        $is_checkout_request = false;
        $action = isset($_REQUEST['wc-ajax']) ? sanitize_text_field($_REQUEST['wc-ajax']) : '';
        
        if (empty($action)) {
            $action = isset($_REQUEST['action']) ? sanitize_text_field($_REQUEST['action']) : '';
        }
        
        // List of checkout-related AJAX actions
        $checkout_actions = array(
            'checkout',
            'wcf_checkout',
            'woocommerce_checkout',
            'wcf_ajax_checkout',
            'wcf_save_checkout_fields',
            'cartflows_save_checkout_data',
            'wcf_validate_checkout',
        );
        
        if (in_array($action, $checkout_actions)) {
            $is_checkout_request = true;
        }
        
        if (!$is_checkout_request) {
            return;
        }
        
        // Now check cooldown
        try {
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

            // Check phone cooldown
            if ($this->is_phone_cooldown_enabled() && isset($_POST['billing_phone'])) {
                $phone = sanitize_text_field($_POST['billing_phone']);
                if (!empty($phone) && $this->has_recent_order_by_phone($phone)) {
                    $this->send_blocked_response($this->get_phone_cooldown_message());
                }
            }

            // Check IP cooldown
            if ($this->is_ip_cooldown_enabled()) {
                $ip = $this->get_client_ip();
                if ($this->has_recent_order_by_ip($ip)) {
                    $this->send_blocked_response($this->get_ip_cooldown_message());
                }
            }
        } catch (Exception $e) {
            // Don't block on error
        }
    }
    
    /**
     * Send blocked response for checkout
     */
    private function send_blocked_response($message): void {
        wp_send_json(array(
            'result' => 'failure',
            'messages' => '<div class="woocommerce-error">' . esc_html($message) . '</div>',
            'refresh' => false,
            'reload' => false,
        ));
        exit;
    }
    
    /**
     * Check cooldown before order is created
     * This is the LAST line of defense
     * 
     * @param \WC_Order $order The order object
     * @param array $data Checkout data
     */
    public function check_cooldown_before_order_create($order, $data): void {
        try {
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

            // Check phone cooldown
            if ($this->is_phone_cooldown_enabled()) {
                $phone = $order->get_billing_phone();
                if (!empty($phone) && $this->has_recent_order_by_phone($phone)) {
                    throw new Exception($this->get_phone_cooldown_message());
                }
            }

            // Check IP cooldown
            if ($this->is_ip_cooldown_enabled()) {
                $ip = $this->get_client_ip();
                if ($this->has_recent_order_by_ip($ip)) {
                    throw new Exception($this->get_ip_cooldown_message());
                }
            }
        } catch (Exception $e) {
            // This will stop the order creation
            throw $e;
        }
    }

    /**
     * AJAX handler for checking cooldown
     */
    public function ajax_check_cooldown() {
        check_ajax_referer('guardify-cooldown-nonce', 'nonce');

        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $ip = $this->get_client_ip();
        
        $response = array(
            'blocked' => false,
            'message' => '',
            'type' => '',
            'debug' => array(
                'ip' => $ip,
                'phone' => $phone,
                'ip_cooldown_enabled' => $this->is_ip_cooldown_enabled(),
                'phone_cooldown_enabled' => $this->is_phone_cooldown_enabled(),
            )
        );

        // Check phone cooldown
        if ($this->is_phone_cooldown_enabled() && !empty($phone)) {
            if ($this->has_recent_order_by_phone($phone)) {
                $response['blocked'] = true;
                $response['message'] = $this->get_phone_cooldown_message();
                $response['type'] = 'phone';
                wp_send_json_error($response);
                return;
            }
        }

        // Check IP cooldown
        if ($this->is_ip_cooldown_enabled()) {
            if ($this->has_recent_order_by_ip($ip)) {
                $response['blocked'] = true;
                $response['message'] = $this->get_ip_cooldown_message();
                $response['type'] = 'ip';
                $response['debug']['recent_order_found'] = true;
                wp_send_json_error($response);
                return;
            }
        }

        wp_send_json_success($response);
    }

    /**
     * Check cooldown for WooCommerce Block Checkout
     * 
     * @param \WC_Order $order The order object
     * @param \WP_REST_Request $request The REST request
     */
    public function check_cooldown_block_checkout($order, $request): void {
        try {
            // Skip for admins
            if (current_user_can('manage_options')) {
                return;
            }

            // Skip for logged in users with previous successful orders (trusted customers)
            if (is_user_logged_in()) {
                $user_id = get_current_user_id();
                $order_count = wc_get_customer_order_count($user_id);
                if ($order_count > 2) {
                    return; // Trusted customer with 3+ orders, skip cooldown
                }
            }

            // Check phone cooldown
            if ($this->is_phone_cooldown_enabled()) {
                $phone = $order->get_billing_phone();
                if (!empty($phone) && $this->has_recent_order_by_phone($phone)) {
                    throw new \Exception($this->get_phone_cooldown_message());
                }
            }

            // Check IP cooldown
            if ($this->is_ip_cooldown_enabled()) {
                $ip = $this->get_client_ip();
                if ($this->has_recent_order_by_ip($ip)) {
                    throw new \Exception($this->get_ip_cooldown_message());
                }
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }
    
    /**
     * Check cooldown when a new order is created (for Cartflows and similar plugins)
     * This runs when any new order is created, including draft/incomplete orders
     * 
     * @param int $order_id The order ID
     * @param \WC_Order $order The order object (optional in some cases)
     */
    public function check_cooldown_on_new_order($order_id, $order = null): void {
        try {
            // Skip for admins
            if (current_user_can('manage_options')) {
                return;
            }
            
            // Get order object if not passed
            if (!$order) {
                $order = wc_get_order($order_id);
            }
            
            if (!$order) {
                return;
            }
            
            // Skip for logged in users with previous successful orders (trusted customers)
            if (is_user_logged_in()) {
                $user_id = get_current_user_id();
                $order_count = wc_get_customer_order_count($user_id);
                if ($order_count > 2) {
                    return; // Trusted customer with 3+ orders, skip cooldown
                }
            }
            
            // Store IP address first
            $ip = $this->get_client_ip();
            $existing_ip = $order->get_customer_ip_address();
            if (empty($existing_ip) || $existing_ip === '0.0.0.0') {
                $order->set_customer_ip_address($ip);
                $order->save();
            }

            // Check phone cooldown - exclude current order
            if ($this->is_phone_cooldown_enabled()) {
                $phone = $order->get_billing_phone();
                if (!empty($phone) && $this->has_recent_order_by_phone_excluding($phone, $order_id)) {
                    // Set order status to failed with note
                    $order->update_status('failed', __('Guardify: ' . $this->get_phone_cooldown_message(), 'guardify'));
                    return;
                }
            }

            // Check IP cooldown - exclude current order
            if ($this->is_ip_cooldown_enabled()) {
                if ($this->has_recent_order_by_ip_excluding($ip, $order_id)) {
                    // Set order status to failed with note
                    $order->update_status('failed', __('Guardify: ' . $this->get_ip_cooldown_message(), 'guardify'));
                    return;
                }
            }
        } catch (\Exception $e) {
            // Don't block on error
        }
    }
    
    /**
     * Check for recent orders by phone number, excluding a specific order
     */
    private function has_recent_order_by_phone_excluding($phone, $exclude_order_id): bool {
        if (!$this->is_phone_cooldown_enabled()) {
            return false;
        }

        $time_limit = $this->get_phone_cooldown_time();
        $phone = preg_replace('/[\s\-\(\)]+/', '', $phone);
        
        if (empty($phone)) {
            return false;
        }

        $cutoff_time = time() - ($time_limit * 3600);
        $cutoff_date_gmt = gmdate('Y-m-d H:i:s', $cutoff_time);
        $cutoff_date_local = date('Y-m-d H:i:s', $cutoff_time);

        global $wpdb;
        
        try {
            if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
                \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
                $orders_table = $wpdb->prefix . 'wc_orders';
                
                $result = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$orders_table}
                    WHERE billing_phone = %s
                    AND id != %d
                    AND date_created_gmt > %s
                    AND status NOT IN ('trash', 'wc-cancelled', 'wc-failed', 'cancelled', 'failed')",
                    $phone,
                    $exclude_order_id,
                    $cutoff_date_gmt
                ));
            } else {
                $result = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                    JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE pm.meta_key = '_billing_phone'
                    AND pm.meta_value = %s
                    AND p.ID != %d
                    AND p.post_type IN ('shop_order', 'shop_order_placehold')
                    AND p.post_date > %s
                    AND p.post_status NOT IN ('trash', 'wc-cancelled', 'wc-failed')",
                    $phone,
                    $exclude_order_id,
                    $cutoff_date_local
                ));
            }
            
            return intval($result) > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Check for recent orders by IP, excluding a specific order
     */
    private function has_recent_order_by_ip_excluding($ip, $exclude_order_id): bool {
        if (!$this->is_ip_cooldown_enabled()) {
            return false;
        }
        
        if (empty($ip) || $ip === '0.0.0.0' || $ip === '127.0.0.1' || $ip === '::1') {
            return false;
        }

        $time_limit = $this->get_ip_cooldown_time();
        $cutoff_time = time() - ($time_limit * 3600);
        $cutoff_date_gmt = gmdate('Y-m-d H:i:s', $cutoff_time);
        $cutoff_date_local = date('Y-m-d H:i:s', $cutoff_time);

        global $wpdb;
        
        try {
            if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
                \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
                $orders_table = $wpdb->prefix . 'wc_orders';
                
                // First try direct column (newer HPOS)
                $result = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$orders_table}
                    WHERE ip_address = %s
                    AND id != %d
                    AND date_created_gmt > %s
                    AND status NOT IN ('trash', 'wc-cancelled', 'wc-failed', 'cancelled', 'failed')",
                    $ip,
                    $exclude_order_id,
                    $cutoff_date_gmt
                ));
                
                // If no result, try meta table
                if (intval($result) === 0) {
                    $table_name = $wpdb->prefix . 'wc_orders_meta';
                    $result = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table_name} om
                        JOIN {$orders_table} o ON om.order_id = o.id
                        WHERE om.meta_key = '_customer_ip_address'
                        AND om.meta_value = %s
                        AND o.id != %d
                        AND o.date_created_gmt > %s
                        AND o.status NOT IN ('trash', 'wc-cancelled', 'wc-failed', 'cancelled', 'failed')",
                        $ip,
                        $exclude_order_id,
                        $cutoff_date_gmt
                    ));
                }
            } else {
                $result = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                    JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE pm.meta_key = '_customer_ip_address'
                    AND pm.meta_value = %s
                    AND p.ID != %d
                    AND p.post_type IN ('shop_order', 'shop_order_placehold')
                    AND p.post_date > %s
                    AND p.post_status NOT IN ('trash', 'wc-cancelled', 'wc-failed')",
                    $ip,
                    $exclude_order_id,
                    $cutoff_date_local
                ));
            }

            return intval($result) > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}
