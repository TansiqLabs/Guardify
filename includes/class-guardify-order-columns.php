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
     * Cache for customer statistics
     */
    private array $stats_cache = array();

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
            
            // Add Score and Duplicate after order_number
            if ($column_name === 'order_number') {
                $new_columns['guardify_score'] = __('Score', 'guardify');
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
            case 'guardify_score':
                $this->render_score_column($order, $phone);
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
     * Render Score column
     * Shows: Total, Delivered, Returned, Success%
     * Data sourced from WooCommerce statuses + all integrated couriers (Steadfast, etc.)
     */
    private function render_score_column($order, string $phone): void {
        if (empty($phone)) {
            echo '<span class="guardify-score-na">—</span>';
            return;
        }
        
        $stats = $this->get_customer_stats($phone);
        
        // Success rate: delivered / total (excluding pending/processing which are still in transit)
        $resolved_orders = $stats['delivered'] + $stats['returned'] + $stats['cancelled'];
        $success_rate = $resolved_orders > 0 ? round(($stats['delivered'] / $resolved_orders) * 100, 1) : 0;
        
        // If no resolved orders but there are total orders, show 0% (all still pending)
        if ($resolved_orders === 0 && $stats['total'] > 0) {
            $success_rate = 0;
        }
        
        // Determine color based on success rate
        $success_class = 'success-low';
        if ($success_rate >= 80) {
            $success_class = 'success-high';
        } elseif ($success_rate >= 50) {
            $success_class = 'success-medium';
        }
        
        // Check if courier data is present
        $has_courier_data = ($stats['courier_delivered'] > 0 || $stats['courier_returned'] > 0);
        $courier_tooltip = $has_courier_data ? __('Includes courier delivery data (Steadfast)', 'guardify') : __('Based on WooCommerce order statuses only', 'guardify');
        
        ?>
        <div class="guardify-score-wrap" title="<?php echo esc_attr($courier_tooltip); ?>">
            <span class="guardify-score-item guardify-score-total" title="<?php esc_attr_e('Total Orders', 'guardify'); ?>">
                <span class="score-value"><?php echo esc_html($stats['total']); ?></span>
                <span class="score-label"><?php _e('TOTAL', 'guardify'); ?></span>
            </span>
            <span class="guardify-score-item guardify-score-delivered" title="<?php echo esc_attr(sprintf(__('Delivered Orders (%s)', 'guardify'), $has_courier_data ? 'Courier' : 'WC')); ?>">
                <span class="score-value"><?php echo esc_html($stats['delivered']); ?></span>
                <span class="score-label"><?php _e('DELIVERED', 'guardify'); ?></span>
            </span>
            <span class="guardify-score-item guardify-score-returned" title="<?php echo esc_attr(sprintf(__('Returned/Cancelled (%d returned + %d cancelled)', 'guardify'), $stats['returned'], $stats['cancelled'])); ?>">
                <span class="score-value"><?php echo esc_html($stats['returned'] + $stats['cancelled']); ?></span>
                <span class="score-label"><?php _e('RETURNED', 'guardify'); ?></span>
            </span>
            <span class="guardify-score-item guardify-score-success <?php echo esc_attr($success_class); ?>" title="<?php echo esc_attr(sprintf(__('Success Rate: %s%% (%d delivered / %d resolved)', 'guardify'), $success_rate, $stats['delivered'], $resolved_orders)); ?>">
                <span class="score-value"><?php echo esc_html($success_rate); ?>%</span>
                <span class="score-label"><?php echo $has_courier_data ? '📦 ' : ''; ?><?php _e('SUCCESS', 'guardify'); ?></span>
            </span>
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
     * Get customer statistics by phone
     * Combines WooCommerce order statuses AND courier delivery statuses (Steadfast, etc.)
     * Score aggregates data from all integrated couriers
     */
    private function get_customer_stats(string $phone): array {
        if (empty($phone)) {
            return array('total' => 0, 'delivered' => 0, 'returned' => 0, 'cancelled' => 0, 'courier_delivered' => 0, 'courier_returned' => 0);
        }
        
        // Normalize phone for cache key
        $cache_key = preg_replace('/\D/', '', $phone);
        
        // Check cache
        if (isset($this->stats_cache[$cache_key])) {
            return $this->stats_cache[$cache_key];
        }
        
        // Build phone variants for fuzzy matching (handles 01..., +880..., 880..., etc.)
        list($phone_clause, $phone_params) = $this->phone_in_clause($phone);
        
        global $wpdb;
        
        $stats = array(
            'total' => 0,
            'delivered' => 0,
            'returned' => 0,
            'cancelled' => 0,
            'courier_delivered' => 0,
            'courier_returned' => 0,
        );
        
        try {
            // Check for HPOS
            if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
                \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
                $orders_table = $wpdb->prefix . 'wc_orders';
                $addresses_table = $wpdb->prefix . 'wc_order_addresses';
                $meta_table = $wpdb->prefix . 'wc_orders_meta';
                
                // HPOS: billing phone lives in wc_order_addresses (address_type='billing')
                // Get total orders
                $stats['total'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$orders_table} o
                    INNER JOIN {$addresses_table} a ON o.id = a.order_id AND a.address_type = 'billing'
                    WHERE a.phone {$phone_clause}
                    AND o.status NOT IN ('trash')",
                    ...$phone_params
                ));
                
                // Get delivered (WC completed) orders
                $stats['delivered'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$orders_table} o
                    INNER JOIN {$addresses_table} a ON o.id = a.order_id AND a.address_type = 'billing'
                    WHERE a.phone {$phone_clause}
                    AND o.status IN ('wc-completed', 'completed')",
                    ...$phone_params
                ));
                
                // Get returned/refunded orders
                $stats['returned'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$orders_table} o
                    INNER JOIN {$addresses_table} a ON o.id = a.order_id AND a.address_type = 'billing'
                    WHERE a.phone {$phone_clause}
                    AND o.status IN ('wc-refunded', 'refunded', 'wc-failed', 'failed')",
                    ...$phone_params
                ));
                
                // Get cancelled orders
                $stats['cancelled'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$orders_table} o
                    INNER JOIN {$addresses_table} a ON o.id = a.order_id AND a.address_type = 'billing'
                    WHERE a.phone {$phone_clause}
                    AND o.status IN ('wc-cancelled', 'cancelled')",
                    ...$phone_params
                ));
                
                // ===== COURIER DELIVERY STATUS (Steadfast + future couriers) =====
                // Count orders with courier "delivered" status
                $stats['courier_delivered'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT o.id) FROM {$orders_table} o
                    INNER JOIN {$addresses_table} a ON o.id = a.order_id AND a.address_type = 'billing'
                    INNER JOIN {$meta_table} m ON o.id = m.order_id
                    WHERE a.phone {$phone_clause}
                    AND o.status NOT IN ('trash')
                    AND m.meta_key = '_guardify_steadfast_delivery_status'
                    AND m.meta_value IN ('delivered', 'delivered_approval_pending', 'partial_delivered', 'partial_delivered_approval_pending')",
                    ...$phone_params
                ));
                
                // Count orders with courier "cancelled/returned" status
                $stats['courier_returned'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT o.id) FROM {$orders_table} o
                    INNER JOIN {$addresses_table} a ON o.id = a.order_id AND a.address_type = 'billing'
                    INNER JOIN {$meta_table} m ON o.id = m.order_id
                    WHERE a.phone {$phone_clause}
                    AND o.status NOT IN ('trash')
                    AND m.meta_key = '_guardify_steadfast_delivery_status'
                    AND m.meta_value IN ('cancelled', 'cancelled_approval_pending')",
                    ...$phone_params
                ));
                
            } else {
                // Legacy queries
                $stats['total'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                    JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE pm.meta_key = '_billing_phone'
                    AND pm.meta_value {$phone_clause}
                    AND p.post_type = 'shop_order'
                    AND p.post_status NOT IN ('trash')",
                    ...$phone_params
                ));
                
                $stats['delivered'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                    JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE pm.meta_key = '_billing_phone'
                    AND pm.meta_value {$phone_clause}
                    AND p.post_type = 'shop_order'
                    AND p.post_status IN ('wc-completed')",
                    ...$phone_params
                ));
                
                $stats['returned'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                    JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE pm.meta_key = '_billing_phone'
                    AND pm.meta_value {$phone_clause}
                    AND p.post_type = 'shop_order'
                    AND p.post_status IN ('wc-refunded', 'wc-failed')",
                    ...$phone_params
                ));
                
                $stats['cancelled'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                    JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE pm.meta_key = '_billing_phone'
                    AND pm.meta_value {$phone_clause}
                    AND p.post_type = 'shop_order'
                    AND p.post_status IN ('wc-cancelled')",
                    ...$phone_params
                ));
                
                // Legacy courier delivery status queries
                $stats['courier_delivered'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
                    JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    JOIN {$wpdb->postmeta} pm2 ON pm.post_id = pm2.post_id
                    WHERE pm.meta_key = '_billing_phone'
                    AND pm.meta_value {$phone_clause}
                    AND p.post_type = 'shop_order'
                    AND p.post_status NOT IN ('trash')
                    AND pm2.meta_key = '_guardify_steadfast_delivery_status'
                    AND pm2.meta_value IN ('delivered', 'delivered_approval_pending', 'partial_delivered', 'partial_delivered_approval_pending')",
                    ...$phone_params
                ));
                
                $stats['courier_returned'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
                    JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    JOIN {$wpdb->postmeta} pm2 ON pm.post_id = pm2.post_id
                    WHERE pm.meta_key = '_billing_phone'
                    AND pm.meta_value {$phone_clause}
                    AND p.post_type = 'shop_order'
                    AND p.post_status NOT IN ('trash')
                    AND pm2.meta_key = '_guardify_steadfast_delivery_status'
                    AND pm2.meta_value IN ('cancelled', 'cancelled_approval_pending')",
                    ...$phone_params
                ));
            }
            
            // Merge courier stats: use courier data when available (higher accuracy)
            // Courier delivered count takes priority if > 0
            if ($stats['courier_delivered'] > 0) {
                $stats['delivered'] = max($stats['delivered'], $stats['courier_delivered']);
            }
            if ($stats['courier_returned'] > 0) {
                $stats['returned'] = max($stats['returned'], $stats['courier_returned']);
            }
            
        } catch (Exception $e) {
            // Return empty stats on error
        }
        
        // Cache results
        $this->stats_cache[$cache_key] = $stats;
        
        return $stats;
    }

    /**
     * Check for duplicate order
     * Returns percentage of similarity with recent orders
     */
    private function check_duplicate_order($order): array {
        $order_id = $order->get_id();
        $phone = $order->get_billing_phone();
        $name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
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
            // Check for orders with same phone in last 24 hours (excluding current)
            $cutoff_time = gmdate('Y-m-d H:i:s', time() - (24 * 3600));
            
            if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
                \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
                $orders_table = $wpdb->prefix . 'wc_orders';
                $addresses_table = $wpdb->prefix . 'wc_order_addresses';
                
                // Same phone in 24h (HPOS: phone is in wc_order_addresses)
                $same_phone = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$orders_table} o
                    INNER JOIN {$addresses_table} a ON o.id = a.order_id AND a.address_type = 'billing'
                    WHERE a.phone {$phone_clause}
                    AND o.id != %d
                    AND o.date_created_gmt > %s
                    AND o.status NOT IN ('trash', 'wc-cancelled', 'cancelled')",
                    ...array_merge($phone_params, array($order_id, $cutoff_time))
                ));
                
                if ($same_phone > 0) {
                    $duplicate_score += 40;
                    $reasons[] = sprintf(__('%d orders with same phone', 'guardify'), $same_phone);
                }
                
                // Same IP in 24h (ip_address is in wc_orders directly)
                if (!empty($ip) && $ip !== '0.0.0.0') {
                    $same_ip = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$orders_table}
                        WHERE ip_address = %s
                        AND id != %d
                        AND date_created_gmt > %s
                        AND status NOT IN ('trash', 'wc-cancelled', 'cancelled')",
                        $ip,
                        $order_id,
                        $cutoff_time
                    ));
                    
                    if ($same_ip > 0) {
                        $duplicate_score += 30;
                        $reasons[] = sprintf(__('%d orders with same IP', 'guardify'), $same_ip);
                    }
                }
                
                // Same address in 24h (HPOS: address_1 is in wc_order_addresses)
                if (!empty($address)) {
                    $same_address = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$orders_table} o
                        INNER JOIN {$addresses_table} a ON o.id = a.order_id AND a.address_type = 'billing'
                        WHERE a.address_1 = %s
                        AND o.id != %d
                        AND o.date_created_gmt > %s
                        AND o.status NOT IN ('trash', 'wc-cancelled', 'cancelled')",
                        $address,
                        $order_id,
                        $cutoff_time
                    ));
                    
                    if ($same_address > 0) {
                        $duplicate_score += 30;
                        $reasons[] = sprintf(__('%d orders with same address', 'guardify'), $same_address);
                    }
                }
                
            } else {
                // Legacy queries - simplified
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
     * Check if phone is blocked
     */
    private function is_phone_blocked(string $phone): bool {
        if (empty($phone)) {
            return false;
        }
        
        $variants = $this->get_phone_variants($phone);
        $blocked_phones = (array) get_option('guardify_blocked_phones', array());
        
        foreach ($variants as $variant) {
            if (in_array($variant, $blocked_phones, true)) {
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
        
        $blocked_ips = get_option('guardify_blocked_ips', array());
        
        return in_array($ip, (array) $blocked_ips, true);
    }

    /**
     * Check if device is blocked
     */
    private function is_device_blocked(string $device_id): bool {
        if (empty($device_id)) {
            return false;
        }
        
        $blocked_devices = get_option('guardify_blocked_devices', array());
        
        return in_array($device_id, (array) $blocked_devices, true);
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
        
        wp_localize_script('guardify-order-columns', 'guardifyOrderColumns', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('guardify-order-columns-nonce'),
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
            )
        ));
        
        wp_enqueue_style(
            'guardify-order-columns',
            GUARDIFY_PLUGIN_URL . 'assets/css/order-columns.css',
            array(),
            GUARDIFY_VERSION
        );
    }
}
