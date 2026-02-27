<?php
/**
 * Guardify Blocklist Management Class
 * Manage blocked phones, IPs, devices and track their orders
 * 
 * @package Guardify
 * @since 2.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Guardify_Blocklist {
    private static ?self $instance = null;

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // ─── Frontend: Enforce blocklist at checkout ───
        add_action('woocommerce_after_checkout_validation', array($this, 'enforce_blocklist_at_checkout'), 2, 2);
        add_action('woocommerce_checkout_process', array($this, 'enforce_blocklist_process'), 2);
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'enforce_blocklist_block_checkout'), 2, 2);
        
        // CartFlows support
        add_action('cartflows_checkout_before_process_checkout', array($this, 'enforce_blocklist_process'), 2);
        add_action('wcf_checkout_before_process_checkout', array($this, 'enforce_blocklist_process'), 2);

        // ─── Admin: UI and management ───
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_submenu'), 20);
            add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        }
        
        // AJAX handlers (must be registered outside is_admin for proper handling)
        add_action('wp_ajax_guardify_remove_from_blocklist', array($this, 'ajax_remove_from_blocklist'));
        add_action('wp_ajax_guardify_add_to_blocklist', array($this, 'ajax_add_to_blocklist'));
        add_action('wp_ajax_guardify_get_blocked_orders', array($this, 'ajax_get_blocked_orders'));
    }

    // =========================================================================
    // CHECKOUT ENFORCEMENT — Block orders from blocklisted phones/IPs/devices
    // =========================================================================

    /**
     * Get all phone variants for a given BD phone number
     * Ensures blocklist matching works regardless of phone format stored
     */
    private function get_phone_variants(string $phone): array {
        $phone = preg_replace('/[\s\-\(\)\+]+/', '', $phone);
        $variants = array($phone);
        
        // Extract the core 10-digit number (01XXXXXXXXX)
        $core = '';
        if (preg_match('/^(?:\+?880)?(1[3-9]\d{8})$/', $phone, $m)) {
            $core = '0' . $m[1];
        } elseif (preg_match('/^(01[3-9]\d{8})$/', $phone)) {
            $core = $phone;
        }
        
        if (!empty($core)) {
            $digits = substr($core, 1); // 1XXXXXXXXX
            $variants = array_unique(array(
                $core,           // 01XXXXXXXXX
                $digits,         // 1XXXXXXXXX
                '880' . $digits, // 8801XXXXXXXXX
                '+880' . $digits,// +8801XXXXXXXXX
            ));
        }
        
        return $variants;
    }

    /**
     * Check if a phone number is in the blocklist (fuzzy match all formats)
     */
    public function is_phone_blocked(string $phone): bool {
        $blocked_phones = get_option('guardify_blocked_phones', array());
        if (!is_array($blocked_phones) || empty($blocked_phones)) {
            return false;
        }
        
        $variants = $this->get_phone_variants($phone);
        foreach ($blocked_phones as $blocked) {
            $blocked_variants = $this->get_phone_variants($blocked);
            if (!empty(array_intersect($variants, $blocked_variants))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if an IP address is in the blocklist
     */
    public function is_ip_blocked(string $ip): bool {
        $blocked_ips = get_option('guardify_blocked_ips', array());
        if (!is_array($blocked_ips) || empty($blocked_ips)) {
            return false;
        }
        return in_array($ip, $blocked_ips, true);
    }

    /**
     * Get the client IP address (reuse logic from cooldown class)
     */
    private function get_client_ip(): string {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
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
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }

    /**
     * Enforce blocklist during woocommerce_checkout_process
     */
    public function enforce_blocklist_process(): void {
        try {
            if (current_user_can('manage_options')) {
                return;
            }

            // Check phone blocklist
            if (isset($_POST['billing_phone'])) {
                $phone = sanitize_text_field($_POST['billing_phone']);
                if (!empty($phone) && $this->is_phone_blocked($phone)) {
                    $this->log_blocked('phone', $phone);
                    wc_add_notice(
                        __('দুঃখিত, এই ফোন নাম্বার থেকে অর্ডার গ্রহণ করা সম্ভব হচ্ছে না। সাহায্যের জন্য যোগাযোগ করুন।', 'guardify'),
                        'error'
                    );
                    return;
                }
            }

            // Check IP blocklist
            $ip = $this->get_client_ip();
            if ($this->is_ip_blocked($ip)) {
                $this->log_blocked('ip', $ip);
                wc_add_notice(
                    __('দুঃখিত, আপনার নেটওয়ার্ক থেকে অর্ডার গ্রহণ করা সম্ভব হচ্ছে না। সাহায্যের জন্য যোগাযোগ করুন।', 'guardify'),
                    'error'
                );
            }
        } catch (\Exception $e) {
            // Don't block on error
        }
    }

    /**
     * Enforce blocklist during woocommerce_after_checkout_validation
     */
    public function enforce_blocklist_at_checkout(array $data, \WP_Error $errors): void {
        try {
            if (current_user_can('manage_options')) {
                return;
            }

            // Check phone blocklist
            $phone = $data['billing_phone'] ?? '';
            if (empty($phone) && isset($_POST['billing_phone'])) {
                $phone = sanitize_text_field($_POST['billing_phone']);
            }
            if (!empty($phone) && $this->is_phone_blocked($phone)) {
                $this->log_blocked('phone', $phone);
                $errors->add('guardify_blocked_phone',
                    __('দুঃখিত, এই ফোন নাম্বার থেকে অর্ডার গ্রহণ করা সম্ভব হচ্ছে না। সাহায্যের জন্য যোগাযোগ করুন।', 'guardify')
                );
                return;
            }

            // Check IP blocklist
            $ip = $this->get_client_ip();
            if ($this->is_ip_blocked($ip)) {
                $this->log_blocked('ip', $ip);
                $errors->add('guardify_blocked_ip',
                    __('দুঃখিত, আপনার নেটওয়ার্ক থেকে অর্ডার গ্রহণ করা সম্ভব হচ্ছে না। সাহায্যের জন্য যোগাযোগ করুন।', 'guardify')
                );
            }
        } catch (\Exception $e) {
            // Don't block on error
        }
    }

    /**
     * Enforce blocklist for WooCommerce Block Checkout
     */
    public function enforce_blocklist_block_checkout($order, $request): void {
        try {
            if (current_user_can('manage_options')) {
                return;
            }

            $phone = $order->get_billing_phone();
            if (!empty($phone) && $this->is_phone_blocked($phone)) {
                $this->log_blocked('phone', $phone);
                throw new \Exception(
                    __('দুঃখিত, এই ফোন নাম্বার থেকে অর্ডার গ্রহণ করা সম্ভব হচ্ছে না। সাহায্যের জন্য যোগাযোগ করুন।', 'guardify')
                );
            }

            $ip = $this->get_client_ip();
            if ($this->is_ip_blocked($ip)) {
                $this->log_blocked('ip', $ip);
                throw new \Exception(
                    __('দুঃখিত, আপনার নেটওয়ার্ক থেকে অর্ডার গ্রহণ করা সম্ভব হচ্ছে না। সাহায্যের জন্য যোগাযোগ করুন।', 'guardify')
                );
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Log blocked attempt for analytics and notify
     */
    private function log_blocked(string $type, string $value): void {
        do_action('guardify_order_blocked', 'blocklist_' . $type, array(
            'blocked_type' => $type,
            'blocked_value' => $value,
            'ip' => $this->get_client_ip(),
        ), null);
    }

    /**
     * Add submenu page
     */
    public function add_submenu(): void {
        add_submenu_page(
            'guardify-settings',
            __('Blocklist Manager', 'guardify'),
            __('Blocklist', 'guardify'),
            'manage_woocommerce',
            'guardify-blocklist',
            array($this, 'render_page')
        );
    }

    /**
     * Enqueue scripts
     */
    public function enqueue_scripts(string $hook): void {
        if ($hook !== 'guardify_page_guardify-blocklist') {
            return;
        }

        wp_enqueue_style(
            'guardify-blocklist',
            GUARDIFY_PLUGIN_URL . 'assets/css/blocklist.css',
            array(),
            GUARDIFY_VERSION
        );

        wp_enqueue_script(
            'guardify-blocklist',
            GUARDIFY_PLUGIN_URL . 'assets/js/blocklist.js',
            array('jquery'),
            GUARDIFY_VERSION,
            true
        );

        // Use wp_add_inline_script instead of wp_localize_script
        // This is immune to LiteSpeed Cache JS combination/reordering
        wp_add_inline_script('guardify-blocklist',
            'var guardifyBlocklist = ' . wp_json_encode(array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('guardify-blocklist-nonce'),
                'strings' => array(
                    'confirm_remove' => __('Are you sure you want to remove this from blocklist?', 'guardify'),
                    'confirm_add' => __('Are you sure you want to add this to blocklist?', 'guardify'),
                    'removing' => __('Removing...', 'guardify'),
                    'adding' => __('Adding...', 'guardify'),
                    'error' => __('Error occurred', 'guardify'),
                    'no_orders' => __('No orders found', 'guardify'),
                ),
            )) . ';',
            'before'
        );
    }

    /**
     * Render the blocklist page
     */
    public function render_page(): void {
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'phones';
        
        $blocked_phones = get_option('guardify_blocked_phones', array());
        $blocked_ips = get_option('guardify_blocked_ips', array());
        $blocked_devices = get_option('guardify_blocked_devices', array());
        
        if (!is_array($blocked_phones)) $blocked_phones = array();
        if (!is_array($blocked_ips)) $blocked_ips = array();
        if (!is_array($blocked_devices)) $blocked_devices = array();
        
        ?>
        <div class="wrap guardify-blocklist-wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-shield-alt" style="font-size: 28px; margin-right: 10px;"></span>
                <?php _e('Blocklist Manager', 'guardify'); ?>
            </h1>
            
            <p class="description" style="margin-top: 10px;">
                <?php _e('Manage blocked phones, IPs, and devices. View orders from blocked sources.', 'guardify'); ?>
            </p>

            <!-- Stats Cards -->
            <div class="guardify-blocklist-stats">
                <div class="stat-card stat-phones">
                    <div class="stat-icon"><span class="dashicons dashicons-phone"></span></div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo count($blocked_phones); ?></span>
                        <span class="stat-label"><?php _e('Blocked Phones', 'guardify'); ?></span>
                    </div>
                </div>
                <div class="stat-card stat-ips">
                    <div class="stat-icon"><span class="dashicons dashicons-admin-site-alt3"></span></div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo count($blocked_ips); ?></span>
                        <span class="stat-label"><?php _e('Blocked IPs', 'guardify'); ?></span>
                    </div>
                </div>
                <div class="stat-card stat-devices">
                    <div class="stat-icon"><span class="dashicons dashicons-laptop"></span></div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo count($blocked_devices); ?></span>
                        <span class="stat-label"><?php _e('Blocked Devices', 'guardify'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Tab Navigation -->
            <nav class="nav-tab-wrapper guardify-tabs">
                <a href="?page=guardify-blocklist&tab=phones" class="nav-tab <?php echo $active_tab === 'phones' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-phone"></span> <?php _e('Phones', 'guardify'); ?>
                    <span class="count">(<?php echo count($blocked_phones); ?>)</span>
                </a>
                <a href="?page=guardify-blocklist&tab=ips" class="nav-tab <?php echo $active_tab === 'ips' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-site-alt3"></span> <?php _e('IPs', 'guardify'); ?>
                    <span class="count">(<?php echo count($blocked_ips); ?>)</span>
                </a>
                <a href="?page=guardify-blocklist&tab=devices" class="nav-tab <?php echo $active_tab === 'devices' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-laptop"></span> <?php _e('Devices', 'guardify'); ?>
                    <span class="count">(<?php echo count($blocked_devices); ?>)</span>
                </a>
            </nav>

            <div class="guardify-tab-content">
                <!-- Add New Form -->
                <div class="guardify-add-form">
                    <h3><?php 
                        if ($active_tab === 'phones') _e('Add Phone to Blocklist', 'guardify');
                        elseif ($active_tab === 'ips') _e('Add IP to Blocklist', 'guardify');
                        else _e('Add Device to Blocklist', 'guardify');
                    ?></h3>
                    <div class="add-form-row">
                        <input type="text" id="guardify-add-value" 
                               placeholder="<?php 
                                   if ($active_tab === 'phones') esc_attr_e('Enter phone number (e.g., 01712345678)', 'guardify');
                                   elseif ($active_tab === 'ips') esc_attr_e('Enter IP address (e.g., 192.168.1.1)', 'guardify');
                                   else esc_attr_e('Enter device ID', 'guardify');
                               ?>" 
                               class="regular-text">
                        <button type="button" class="button button-primary" id="guardify-add-btn" data-type="<?php echo esc_attr($active_tab); ?>">
                            <span class="dashicons dashicons-plus-alt"></span> <?php _e('Add to Blocklist', 'guardify'); ?>
                        </button>
                    </div>
                </div>

                <!-- Blocklist Table -->
                <?php $this->render_blocklist_table($active_tab); ?>
            </div>
        </div>

        <!-- Orders Modal -->
        <div id="guardify-orders-modal" class="guardify-modal" style="display: none;">
            <div class="guardify-modal-content">
                <div class="guardify-modal-header">
                    <h2 id="guardify-modal-title"><?php _e('Orders', 'guardify'); ?></h2>
                    <button type="button" class="guardify-modal-close">&times;</button>
                </div>
                <div class="guardify-modal-body" id="guardify-modal-body">
                    <div class="guardify-loading">
                        <span class="spinner is-active"></span>
                        <?php _e('Loading orders...', 'guardify'); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render blocklist table
     */
    private function render_blocklist_table(string $type): void {
        $option_key = 'guardify_blocked_' . $type;
        $items = get_option($option_key, array());
        
        if (!is_array($items)) {
            $items = array();
        }
        
        ?>
        <table class="wp-list-table widefat fixed striped guardify-blocklist-table">
            <thead>
                <tr>
                    <th class="column-value"><?php 
                        if ($type === 'phones') _e('Phone Number', 'guardify');
                        elseif ($type === 'ips') _e('IP Address', 'guardify');
                        else _e('Device ID', 'guardify');
                    ?></th>
                    <th class="column-orders"><?php _e('Orders', 'guardify'); ?></th>
                    <th class="column-actions"><?php _e('Actions', 'guardify'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="3" class="no-items">
                            <span class="dashicons dashicons-yes-alt" style="color: #22c55e;"></span>
                            <?php _e('No blocked items. Your blocklist is empty.', 'guardify'); ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <?php 
                        $order_count = $this->get_order_count_by_type($type, $item);
                        ?>
                        <tr data-value="<?php echo esc_attr($item); ?>">
                            <td class="column-value">
                                <strong><?php echo esc_html($item); ?></strong>
                                <?php if ($type === 'devices'): ?>
                                    <br><small class="description"><?php _e('(Device fingerprint hash)', 'guardify'); ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="column-orders">
                                <?php if ($order_count > 0): ?>
                                    <a href="#" class="guardify-view-orders" 
                                       data-type="<?php echo esc_attr($type); ?>" 
                                       data-value="<?php echo esc_attr($item); ?>">
                                        <span class="order-count"><?php echo $order_count; ?></span>
                                        <?php _e('orders', 'guardify'); ?>
                                        <span class="dashicons dashicons-external"></span>
                                    </a>
                                <?php else: ?>
                                    <span class="no-orders"><?php _e('No orders', 'guardify'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="column-actions">
                                <button type="button" class="button button-small guardify-remove-btn" 
                                        data-type="<?php echo esc_attr($type); ?>" 
                                        data-value="<?php echo esc_attr($item); ?>">
                                    <span class="dashicons dashicons-trash"></span>
                                    <?php _e('Remove', 'guardify'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Get order count by blocked type
     */
    private function get_order_count_by_type(string $type, string $value): int {
        global $wpdb;
        
        if (empty($value)) {
            return 0;
        }
        
        try {
            // Check for HPOS
            if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
                \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
                
                $orders_table = $wpdb->prefix . 'wc_orders';
                $meta_table = $wpdb->prefix . 'wc_orders_meta';
                
                if ($type === 'phones') {
                    return (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$orders_table} WHERE billing_phone = %s",
                        $value
                    ));
                } elseif ($type === 'ips') {
                    return (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$orders_table} WHERE ip_address = %s",
                        $value
                    ));
                } else {
                    return (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$meta_table} WHERE meta_key = '_guardify_device_id' AND meta_value = %s",
                        $value
                    ));
                }
                
            } else {
                // Legacy
                if ($type === 'phones') {
                    return (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                        JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                        WHERE pm.meta_key = '_billing_phone' AND pm.meta_value = %s
                        AND p.post_type = 'shop_order'",
                        $value
                    ));
                } elseif ($type === 'ips') {
                    return (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                        JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                        WHERE pm.meta_key = '_customer_ip_address' AND pm.meta_value = %s
                        AND p.post_type = 'shop_order'",
                        $value
                    ));
                } else {
                    return (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                        JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                        WHERE pm.meta_key = '_guardify_device_id' AND pm.meta_value = %s
                        AND p.post_type = 'shop_order'",
                        $value
                    ));
                }
            }
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * AJAX: Remove from blocklist
     */
    public function ajax_remove_from_blocklist(): void {
        check_ajax_referer('guardify-blocklist-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
        }
        
        $type = isset($_POST['type']) ? sanitize_key($_POST['type']) : '';
        $value = isset($_POST['value']) ? sanitize_text_field($_POST['value']) : '';
        
        if (!in_array($type, array('phones', 'ips', 'devices'), true) || empty($value)) {
            wp_send_json_error(array('message' => __('Invalid request', 'guardify')));
        }
        
        $option_key = 'guardify_blocked_' . $type;
        $items = get_option($option_key, array());
        
        if (!is_array($items)) {
            $items = array();
        }
        
        $items = array_values(array_diff($items, array($value)));
        update_option($option_key, $items);
        
        wp_send_json_success(array(
            'message' => __('Removed from blocklist', 'guardify'),
            'count' => count($items)
        ));
    }

    /**
     * AJAX: Add to blocklist
     */
    public function ajax_add_to_blocklist(): void {
        check_ajax_referer('guardify-blocklist-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
        }
        
        $type = isset($_POST['type']) ? sanitize_key($_POST['type']) : '';
        $value = isset($_POST['value']) ? sanitize_text_field($_POST['value']) : '';
        
        if (!in_array($type, array('phones', 'ips', 'devices'), true) || empty($value)) {
            wp_send_json_error(array('message' => __('Invalid request', 'guardify')));
        }
        
        // Validate based on type
        if ($type === 'phones') {
            $value = preg_replace('/[\s\-\(\)]+/', '', $value);
            if (!preg_match('/^01[3-9]\d{8}$/', $value)) {
                wp_send_json_error(array('message' => __('Invalid Bangladeshi phone number format', 'guardify')));
            }
        } elseif ($type === 'ips') {
            if (!filter_var($value, FILTER_VALIDATE_IP)) {
                wp_send_json_error(array('message' => __('Invalid IP address format', 'guardify')));
            }
        }
        
        $option_key = 'guardify_blocked_' . $type;
        $items = get_option($option_key, array());
        
        if (!is_array($items)) {
            $items = array();
        }
        
        if (in_array($value, $items, true)) {
            wp_send_json_error(array('message' => __('Already in blocklist', 'guardify')));
        }
        
        $items[] = $value;
        update_option($option_key, $items);
        
        wp_send_json_success(array(
            'message' => __('Added to blocklist', 'guardify'),
            'count' => count($items),
            'value' => $value
        ));
    }

    /**
     * AJAX: Get orders for blocked item
     */
    public function ajax_get_blocked_orders(): void {
        check_ajax_referer('guardify-blocklist-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
        }
        
        $type = isset($_POST['type']) ? sanitize_key($_POST['type']) : '';
        $value = isset($_POST['value']) ? sanitize_text_field($_POST['value']) : '';
        
        if (!in_array($type, array('phones', 'ips', 'devices'), true) || empty($value)) {
            wp_send_json_error(array('message' => __('Invalid request', 'guardify')));
        }
        
        $orders = $this->get_orders_by_blocked_item($type, $value);
        
        if (empty($orders)) {
            wp_send_json_success(array(
                'html' => '<p class="no-orders-found">' . __('No orders found for this blocked item.', 'guardify') . '</p>'
            ));
            return;
        }
        
        ob_start();
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Order', 'guardify'); ?></th>
                    <th><?php _e('Customer', 'guardify'); ?></th>
                    <th><?php _e('Total', 'guardify'); ?></th>
                    <th><?php _e('Status', 'guardify'); ?></th>
                    <th><?php _e('Date', 'guardify'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url($order->get_edit_order_url()); ?>" target="_blank">
                                #<?php echo $order->get_order_number(); ?>
                            </a>
                        </td>
                        <td>
                            <?php echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); ?>
                            <br>
                            <small><?php echo esc_html($order->get_billing_phone()); ?></small>
                        </td>
                        <td><?php echo $order->get_formatted_order_total(); ?></td>
                        <td>
                            <span class="order-status status-<?php echo esc_attr($order->get_status()); ?>">
                                <?php echo esc_html(wc_get_order_status_name($order->get_status())); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($order->get_date_created()->date_i18n('M j, Y g:i A')); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        $html = ob_get_clean();
        
        wp_send_json_success(array('html' => $html));
    }

    /**
     * Get orders by blocked item
     */
    private function get_orders_by_blocked_item(string $type, string $value): array {
        $args = array(
            'limit' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
        );
        
        if ($type === 'phones') {
            $args['billing_phone'] = $value;
        } elseif ($type === 'ips') {
            $args['customer_ip_address'] = $value;
        } else {
            $args['meta_key'] = '_guardify_device_id';
            $args['meta_value'] = $value;
        }
        
        return wc_get_orders($args);
    }
}
