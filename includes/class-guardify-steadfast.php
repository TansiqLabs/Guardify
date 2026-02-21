<?php
/**
 * Guardify SteadFast Courier Integration Class
 * SteadFast Courier API integration - Send orders directly to SteadFast
 * 
 * @package Guardify
 * @since 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Guardify_SteadFast {
    private static ?self $instance = null;
    
    /**
     * TansiqLabs Courier Proxy URL
     * All courier API calls go through TansiqLabs — credentials never leave the server.
     */
    private const COURIER_API_URL = 'https://tansiqlabs.com/api/guardify/courier/';

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Only initialize if enabled
        if ($this->is_enabled()) {
            // Bulk action hooks - WooCommerce 7.0.0 (Legacy)
            add_filter('bulk_actions-edit-shop_order', array($this, 'register_bulk_action'));
            add_action('handle_bulk_actions-edit-shop_order', array($this, 'handle_bulk_action'), 20, 3);
            
            // Bulk action hooks - WooCommerce HPOS
            add_filter('bulk_actions-woocommerce_page_wc-orders', array($this, 'register_bulk_action'), 999);
            add_action('handle_bulk_actions-woocommerce_page_wc-orders', array($this, 'handle_bulk_action'), 20, 3);
            
            // Custom columns - Legacy
            add_filter('manage_edit-shop_order_columns', array($this, 'add_custom_columns'));
            add_action('manage_shop_order_posts_custom_column', array($this, 'render_custom_column_legacy'));
            
            // Custom columns - HPOS
            add_filter('woocommerce_shop_order_list_table_columns', array($this, 'add_custom_columns'));
            add_action('woocommerce_shop_order_list_table_custom_column', array($this, 'render_custom_column'), 10, 2);
            
            // AJAX handlers
            add_action('wp_ajax_guardify_steadfast_send', array($this, 'ajax_send_to_steadfast'));
            add_action('wp_ajax_guardify_steadfast_amount', array($this, 'ajax_update_amount'));
            add_action('wp_ajax_guardify_steadfast_balance', array($this, 'ajax_check_balance'));
            add_action('wp_ajax_guardify_steadfast_status', array($this, 'ajax_check_delivery_status'));
            add_action('wp_ajax_guardify_steadfast_bulk_status', array($this, 'ajax_bulk_status_refresh'));
            add_action('wp_ajax_guardify_steadfast_return_request', array($this, 'ajax_create_return_request'));
            
            // Invoice page
            add_action('admin_menu', array($this, 'add_invoice_page'));
            add_action('init', array($this, 'render_invoice_template'));
            
            // Row class
            add_filter('post_class', array($this, 'add_row_class'), 10, 3);
            add_filter('woocommerce_shop_order_list_table_order_css_classes', array($this, 'add_row_class_hpos'));
            
            // Admin notices for bulk action
            add_action('admin_notices', array($this, 'bulk_action_admin_notice'));
            
            // Add bulk refresh button above orders table
            add_action('manage_posts_extra_tablenav', array($this, 'render_bulk_refresh_button'));
            add_action('woocommerce_order_list_table_extra_tablenav', array($this, 'render_bulk_refresh_button'));
        }
        
        // Always load scripts on admin
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
    }

    /**
     * Check if SteadFast integration is enabled
     */
    public function is_enabled(): bool {
        return get_option('guardify_steadfast_enabled', '0') === '1';
    }

    /**
     * Get site API key (used to authenticate with TansiqLabs courier proxy)
     */
    private function get_site_api_key(): string {
        return get_option('guardify_site_api_key', '');
    }

    /**
     * Check if notes should be sent
     */
    private function should_send_notes(): bool {
        return get_option('guardify_steadfast_send_notes', '0') === '1';
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook): void {
        // Only on WooCommerce order pages and Guardify settings
        $screen = get_current_screen();
        if (!$screen) return;

        $is_allowed = in_array($screen->id, [
            'edit-shop_order',
            'shop_order',
            'woocommerce_page_wc-orders',
        ], true) || strpos($screen->id, 'guardify') !== false;

        if (!$is_allowed) {
            return;
        }

        wp_enqueue_script(
            'guardify-steadfast',
            GUARDIFY_PLUGIN_URL . 'assets/js/steadfast.js',
            array('jquery'),
            GUARDIFY_VERSION,
            true
        );

        wp_enqueue_style(
            'guardify-steadfast',
            GUARDIFY_PLUGIN_URL . 'assets/css/steadfast.css',
            array(),
            GUARDIFY_VERSION
        );

        // Use wp_add_inline_script instead of wp_localize_script
        // This is immune to LiteSpeed Cache JS combination/reordering
        wp_add_inline_script('guardify-steadfast',
            'var guardifySteadfast = ' . wp_json_encode(array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('guardify-steadfast-nonce'),
                'enabled' => $this->is_enabled(),
                'i18n' => array(
                    'sending' => __('Sending...', 'guardify'),
                    'sent' => __('✓ Sent', 'guardify'),
                    'failed' => __('Failed!', 'guardify'),
                    'unauthorized' => __('Unauthorized', 'guardify'),
                    'checking' => __('Checking...', 'guardify'),
                    'success' => __('Success', 'guardify'),
                    'copied' => __('Copied!', 'guardify'),
                ),
            )) . ';',
            'before'
        );
    }

    /**
     * Enqueue frontend scripts (for invoice)
     */
    public function enqueue_frontend_scripts(): void {
        if (isset($_GET['page']) && $_GET['page'] === 'guardify-invoice' && 
            isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'guardify_print_invoice')) {
            wp_enqueue_style(
                'guardify-invoice',
                GUARDIFY_PLUGIN_URL . 'assets/css/invoice.css',
                array(),
                GUARDIFY_VERSION
            );
        }
    }

    /**
     * Register bulk action
     */
    public function register_bulk_action(array $bulk_actions): array {
        if ($this->is_enabled()) {
            $bulk_actions['guardify_send_steadfast'] = __('🚚 Send to SteadFast', 'guardify');
        }
        return $bulk_actions;
    }

    /**
     * Handle bulk action
     */
    public function handle_bulk_action(string $redirect, string $action, array $order_ids): string {
        if ($action !== 'guardify_send_steadfast') {
            return $redirect;
        }

        $sent_count = 0;
        foreach ($order_ids as $order_id) {
            $result = $this->send_to_steadfast($order_id);
            if ($result === 'success') {
                $this->update_order_meta($order_id, '_guardify_steadfast_sent', 'yes');
                $sent_count++;
            }
        }

        return add_query_arg(array(
            'guardify_steadfast_sent' => $sent_count,
            'guardify_steadfast_total' => count($order_ids)
        ), $redirect);
    }

    /**
     * Show admin notice after bulk action
     */
    public function bulk_action_admin_notice(): void {
        if (!isset($_GET['guardify_steadfast_sent']) || !isset($_GET['guardify_steadfast_total'])) {
            return;
        }

        $sent = intval($_GET['guardify_steadfast_sent']);
        $total = intval($_GET['guardify_steadfast_total']);
        
        if ($sent === $total) {
            $class = 'notice-success';
            $icon = '✅';
            $message = sprintf(
                /* translators: %d: number of orders */
                __('%s All %d orders sent to SteadFast successfully!', 'guardify'),
                $icon,
                $sent
            );
        } elseif ($sent > 0) {
            $class = 'notice-warning';
            $icon = '⚠️';
            $message = sprintf(
                /* translators: 1: sent count, 2: total count */
                __('%s %d of %d orders sent. Some orders failed.', 'guardify'),
                $icon,
                $sent,
                $total
            );
        } else {
            $class = 'notice-error';
            $icon = '❌';
            $message = sprintf(
                /* translators: %d: number of orders */
                __('%s Failed to send %d orders. Check API settings.', 'guardify'),
                $icon,
                $total
            );
        }

        printf(
            '<div class="notice %s is-dismissible guardify-sf-bulk-notice"><p><strong>Guardify SteadFast:</strong> %s</p></div>',
            esc_attr($class),
            esc_html($message)
        );
    }

    /**
     * Add custom columns to order list
     */
    public function add_custom_columns(array $columns): array {
        if (!$this->is_enabled()) {
            return $columns;
        }

        $new_columns = array();
        foreach ($columns as $column_name => $column_info) {
            $new_columns[$column_name] = $column_info;
            
            if ($column_name === 'order_status') {
                $new_columns['guardify_sf_amount'] = '<span class="dashicons dashicons-money-alt" title="' . esc_attr__('COD Amount', 'guardify') . '"></span> ' . __('Amount', 'guardify');
                $new_columns['guardify_sf_send'] = '<span class="dashicons dashicons-upload" title="' . esc_attr__('Send to SteadFast', 'guardify') . '"></span> ' . __('Send', 'guardify');
                $new_columns['guardify_sf_print'] = '<span class="dashicons dashicons-printer" title="' . esc_attr__('Print Invoice', 'guardify') . '"></span> ' . __('Print', 'guardify');
                $new_columns['guardify_sf_consignment'] = '<span class="dashicons dashicons-tag" title="' . esc_attr__('Consignment ID', 'guardify') . '"></span> ' . __('Consignment', 'guardify');
                $new_columns['guardify_sf_status'] = '<span class="dashicons dashicons-location" title="' . esc_attr__('Delivery Status', 'guardify') . '"></span> ' . __('Delivery', 'guardify');
            }
        }
        
        return $new_columns;
    }

    /**
     * Render custom column content (HPOS)
     */
    public function render_custom_column(string $column, $order): void {
        if (!$this->is_enabled()) {
            return;
        }

        $order_id = is_object($order) ? $order->get_id() : $order;
        $this->render_column_content($column, $order_id);
    }

    /**
     * Render custom column content (Legacy)
     */
    public function render_custom_column_legacy(string $column): void {
        if (!$this->is_enabled()) {
            return;
        }

        global $post;
        $order_id = $post->ID;
        $this->render_column_content($column, $order_id);
    }

    /**
     * Render column content
     */
    private function render_column_content(string $column, int $order_id): void {
        $is_sent = $this->get_order_meta($order_id, '_guardify_steadfast_sent') === 'yes';
        $consignment_id = $this->get_order_meta($order_id, '_guardify_steadfast_consignment_id');
        
        switch ($column) {
            case 'guardify_sf_amount':
                $amount = $this->get_order_meta($order_id, '_guardify_steadfast_amount');
                $order = wc_get_order($order_id);
                if (empty($amount) && $order) {
                    $amount = $order->get_total();
                }
                $disabled = $is_sent ? 'disabled' : '';
                $class = $is_sent ? 'guardify-sf-amount-disabled' : '';
                ?>
                <input type="text" 
                       class="guardify-sf-amount <?php echo esc_attr($class); ?>" 
                       data-order-id="<?php echo esc_attr($order_id); ?>"
                       value="<?php echo esc_attr($amount); ?>" 
                       placeholder="৳ 0"
                       title="<?php esc_attr_e('Enter COD amount', 'guardify'); ?>"
                       <?php echo $disabled; ?>>
                <?php
                break;
                
            case 'guardify_sf_send':
                $btn_class = $is_sent ? 'guardify-sf-sent' : 'guardify-sf-send';
                $btn_text = $is_sent ? __('✓ Sent', 'guardify') : __('Send', 'guardify');
                $btn_title = $is_sent ? __('Order already sent to SteadFast', 'guardify') : __('Click to send order to SteadFast', 'guardify');
                $disabled = $is_sent ? 'disabled' : '';
                ?>
                <button class="<?php echo esc_attr($btn_class); ?>" 
                        data-order-id="<?php echo esc_attr($order_id); ?>"
                        title="<?php echo esc_attr($btn_title); ?>"
                        <?php echo $disabled; ?>>
                    <?php echo esc_html($btn_text); ?>
                </button>
                <?php
                break;
                
            case 'guardify_sf_print':
                if (!empty($consignment_id)) {
                    $invoice_url = wp_nonce_url(
                        admin_url('index.php?page=guardify-invoice&order_id=' . $order_id . '&consignment_id=' . $consignment_id),
                        'guardify_print_invoice'
                    );
                    ?>
                    <a href="<?php echo esc_url($invoice_url); ?>" 
                       class="guardify-sf-print" 
                       title="<?php esc_attr_e('Print Invoice', 'guardify'); ?>"
                       target="_blank">
                        <span class="dashicons dashicons-printer"></span>
                    </a>
                    <?php
                } else {
                    echo '<span class="guardify-sf-no-print" title="' . esc_attr__('Send order first', 'guardify') . '">—</span>';
                }
                break;
                
            case 'guardify_sf_consignment':
                if (!empty($consignment_id)) {
                    ?>
                    <div class="guardify-sf-consignment-id" title="<?php esc_attr_e('Click to copy consignment ID', 'guardify'); ?>" onclick="navigator.clipboard.writeText('<?php echo esc_js($consignment_id); ?>'); this.classList.add('copied'); setTimeout(() => this.classList.remove('copied'), 1000);">
                        <?php echo esc_html($consignment_id); ?>
                        <span class="copy-feedback">✓</span>
                    </div>
                    <?php
                } else {
                    echo '<span class="guardify-sf-no-consignment">—</span>';
                }
                break;
                
            case 'guardify_sf_status':
                if (!empty($consignment_id)) {
                    $status = $this->get_order_meta($order_id, '_guardify_steadfast_delivery_status');
                    $status_class = $this->get_status_class($status);
                    $status_text = $this->get_status_text($status);
                    $return_request_id = $this->get_order_meta($order_id, '_guardify_steadfast_return_request_id');
                    ?>
                    <div class="guardify-sf-status-wrap">
                        <?php if (empty($status)): ?>
                            <button class="guardify-sf-check-status" 
                                    data-order-id="<?php echo esc_attr($order_id); ?>"
                                    data-consignment-id="<?php echo esc_attr($consignment_id); ?>"
                                    title="<?php esc_attr_e('Check delivery status', 'guardify'); ?>">
                                <span class="dashicons dashicons-search"></span> <?php _e('Check', 'guardify'); ?>
                            </button>
                        <?php else: ?>
                            <span class="guardify-sf-refresh-status dashicons dashicons-update"
                                  data-order-id="<?php echo esc_attr($order_id); ?>"
                                  data-consignment-id="<?php echo esc_attr($consignment_id); ?>"
                                  title="<?php esc_attr_e('Refresh status', 'guardify'); ?>"></span>
                        <?php endif; ?>
                        <span class="guardify-sf-status <?php echo esc_attr($status_class); ?>" 
                              data-order-id="<?php echo esc_attr($order_id); ?>"
                              title="<?php echo esc_attr($status_text); ?>">
                            <?php echo esc_html($status_text); ?>
                        </span>
                        <?php if (!empty($status) && !in_array($status, array('delivered', 'cancelled')) && empty($return_request_id)): ?>
                            <button class="guardify-sf-return-request"
                                    data-order-id="<?php echo esc_attr($order_id); ?>"
                                    title="<?php esc_attr_e('Create return request', 'guardify'); ?>">
                                <?php _e('Return', 'guardify'); ?>
                            </button>
                        <?php elseif (!empty($return_request_id)): ?>
                            <span class="guardify-sf-return-requested" style="font-size:10px;padding:4px 8px;">
                                <?php _e('Return #', 'guardify'); ?><?php echo esc_html($return_request_id); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php
                } else {
                    echo '<span class="guardify-sf-no-status">—</span>';
                }
                break;
        }
    }

    /**
     * Get status CSS class
     */
    private function get_status_class(string $status): string {
        $classes = array(
            'pending' => 'guardify-sf-status-pending',
            'in_review' => 'guardify-sf-status-review',
            'delivered' => 'guardify-sf-status-delivered',
            'partial_delivered' => 'guardify-sf-status-partial',
            'cancelled' => 'guardify-sf-status-cancelled',
            'hold' => 'guardify-sf-status-hold',
            'unknown' => 'guardify-sf-status-unknown',
            'delivered_approval_pending' => 'guardify-sf-status-approval',
            'partial_delivered_approval_pending' => 'guardify-sf-status-approval',
            'cancelled_approval_pending' => 'guardify-sf-status-approval',
            'unknown_approval_pending' => 'guardify-sf-status-approval',
        );
        
        return $classes[$status] ?? '';
    }

    /**
     * Get status display text
     */
    private function get_status_text(string $status): string {
        $texts = array(
            'pending' => __('Pending', 'guardify'),
            'in_review' => __('In Review', 'guardify'),
            'delivered' => __('Delivered ✓', 'guardify'),
            'partial_delivered' => __('Partial Delivered', 'guardify'),
            'cancelled' => __('Cancelled', 'guardify'),
            'hold' => __('On Hold', 'guardify'),
            'unknown' => __('Unknown', 'guardify'),
            'delivered_approval_pending' => __('Delivered (Pending)', 'guardify'),
            'partial_delivered_approval_pending' => __('Partial (Pending)', 'guardify'),
            'cancelled_approval_pending' => __('Cancelled (Pending)', 'guardify'),
            'unknown_approval_pending' => __('Unknown (Pending)', 'guardify'),
        );
        
        return $texts[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * AJAX: Send order to SteadFast
     */
    public function ajax_send_to_steadfast(): void {
        check_ajax_referer('guardify-steadfast-nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
            return;
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        
        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid order ID', 'guardify')));
            return;
        }

        $result = $this->send_to_steadfast($order_id);
        
        if ($result === 'success') {
            $this->update_order_meta($order_id, '_guardify_steadfast_sent', 'yes');
            wp_send_json_success(array('message' => __('Order sent successfully', 'guardify')));
        } elseif ($result === 'unauthorized') {
            wp_send_json_error(array('message' => __('Unauthorized - Check API credentials', 'guardify')));
        } else {
            wp_send_json_error(array('message' => $result));
        }
    }

    /**
     * AJAX: Update COD amount
     */
    public function ajax_update_amount(): void {
        check_ajax_referer('guardify-steadfast-nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
            return;
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $amount = isset($_POST['amount']) ? sanitize_text_field($_POST['amount']) : '';
        
        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid order ID', 'guardify')));
            return;
        }

        $this->update_order_meta($order_id, '_guardify_steadfast_amount', $amount);
        wp_send_json_success(array('message' => __('Amount updated', 'guardify')));
    }

    /**
     * AJAX: Check SteadFast balance
     */
    public function ajax_check_balance(): void {
        check_ajax_referer('guardify-steadfast-nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
            return;
        }

        if (!$this->is_enabled()) {
            wp_send_json_error(array('message' => __('SteadFast integration is disabled', 'guardify')));
            return;
        }

        $response = $this->courier_request('balance');
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($data['status']) && $data['status'] == 200) {
            wp_send_json_success(array('balance' => $data['current_balance']));
        } else {
            wp_send_json_error(array('message' => __('Failed to get balance', 'guardify')));
        }
    }

    /**
     * AJAX: Check delivery status
     */
    public function ajax_check_delivery_status(): void {
        check_ajax_referer('guardify-steadfast-nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
            return;
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $consignment_id = isset($_POST['consignment_id']) ? sanitize_text_field($_POST['consignment_id']) : '';
        
        if (!$order_id || !$consignment_id) {
            wp_send_json_error(array('message' => __('Invalid parameters', 'guardify')));
            return;
        }

        $response = $this->courier_request('status', array('consignment_id' => $consignment_id));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($data['status']) && $data['status'] == 200) {
            $delivery_status = $data['delivery_status'];
            $this->update_order_meta($order_id, '_guardify_steadfast_delivery_status', $delivery_status);
            wp_send_json_success(array('status' => $delivery_status));
        } else {
            wp_send_json_error(array('message' => __('Failed to get status', 'guardify')));
        }
    }

    /**
     * AJAX: Bulk refresh delivery statuses for all sent orders
     */
    public function ajax_bulk_status_refresh(): void {
        check_ajax_referer('guardify-steadfast-nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
            return;
        }

        if (!$this->is_enabled()) {
            wp_send_json_error(array('message' => __('SteadFast integration is disabled', 'guardify')));
            return;
        }

        // Find all orders that have been sent to Steadfast but not yet delivered/cancelled
        global $wpdb;
        $updated = 0;
        $errors = 0;

        if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $meta_table = $wpdb->prefix . 'wc_orders_meta';
            
            // Get orders with consignment IDs that aren't in final statuses
            $orders = $wpdb->get_results(
                "SELECT m1.order_id, m1.meta_value as consignment_id, COALESCE(m2.meta_value, '') as current_status
                FROM {$meta_table} m1
                LEFT JOIN {$meta_table} m2 ON m1.order_id = m2.order_id AND m2.meta_key = '_guardify_steadfast_delivery_status'
                WHERE m1.meta_key = '_guardify_steadfast_consignment_id'
                AND m1.meta_value != ''
                AND (m2.meta_value IS NULL OR m2.meta_value NOT IN ('delivered', 'cancelled', 'partial_delivered'))
                LIMIT 50"
            );
        } else {
            $orders = $wpdb->get_results(
                "SELECT pm1.post_id as order_id, pm1.meta_value as consignment_id, COALESCE(pm2.meta_value, '') as current_status
                FROM {$wpdb->postmeta} pm1
                LEFT JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_guardify_steadfast_delivery_status'
                WHERE pm1.meta_key = '_guardify_steadfast_consignment_id'
                AND pm1.meta_value != ''
                AND (pm2.meta_value IS NULL OR pm2.meta_value NOT IN ('delivered', 'cancelled', 'partial_delivered'))
                LIMIT 50"
            );
        }

        foreach ($orders as $order_row) {
            $response = $this->courier_request('status', array('consignment_id' => $order_row->consignment_id));
            
            if (!is_wp_error($response)) {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                
                if (isset($data['status']) && $data['status'] == 200 && !empty($data['delivery_status'])) {
                    $delivery_status = $data['delivery_status'];
                    $this->update_order_meta($order_row->order_id, '_guardify_steadfast_delivery_status', $delivery_status);
                    
                    // Auto-update WooCommerce order status based on courier status
                    $this->sync_wc_order_status($order_row->order_id, $delivery_status);
                    $updated++;
                } else {
                    $errors++;
                }
            } else {
                $errors++;
            }
            
            // Slight delay to avoid API rate limiting
            usleep(100000); // 100ms
        }

        wp_send_json_success(array(
            'message' => sprintf(
                __('Updated %d orders. %d errors. %d total checked.', 'guardify'),
                $updated,
                $errors,
                count($orders)
            ),
            'updated' => $updated,
            'errors' => $errors,
            'total' => count($orders)
        ));
    }

    /**
     * AJAX: Create return request via Steadfast API
     */
    public function ajax_create_return_request(): void {
        check_ajax_referer('guardify-steadfast-nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
            return;
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $reason = isset($_POST['reason']) ? sanitize_text_field($_POST['reason']) : '';
        
        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid order ID', 'guardify')));
            return;
        }

        $consignment_id = $this->get_order_meta($order_id, '_guardify_steadfast_consignment_id');
        if (empty($consignment_id)) {
            wp_send_json_error(array('message' => __('No consignment ID found for this order', 'guardify')));
            return;
        }

        $body = array(
            'consignment_id' => $consignment_id,
        );
        if (!empty($reason)) {
            $body['reason'] = $reason;
        }

        $response = $this->courier_request('return', $body);
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($data['id'])) {
            $this->update_order_meta($order_id, '_guardify_steadfast_return_request_id', (string)$data['id']);
            $this->update_order_meta($order_id, '_guardify_steadfast_return_status', $data['status'] ?? 'pending');
            wp_send_json_success(array(
                'message' => __('Return request created successfully', 'guardify'),
                'return_id' => $data['id'],
                'status' => $data['status'] ?? 'pending'
            ));
        } else {
            $error_msg = isset($data['message']) ? $data['message'] : __('Failed to create return request', 'guardify');
            wp_send_json_error(array('message' => $error_msg));
        }
    }

    /**
     * Sync WooCommerce order status based on courier delivery status
     */
    private function sync_wc_order_status(int $order_id, string $delivery_status): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $current_status = $order->get_status();
        
        // Don't change manually set statuses
        if (in_array($current_status, array('completed', 'refunded'))) {
            return;
        }

        switch ($delivery_status) {
            case 'delivered':
            case 'delivered_approval_pending':
                if ($current_status !== 'completed') {
                    $order->update_status('completed', __('Guardify: Marked as delivered by Steadfast courier.', 'guardify'));
                }
                break;
                
            case 'cancelled':
            case 'cancelled_approval_pending':
                if (!in_array($current_status, array('cancelled', 'failed'))) {
                    $order->update_status('cancelled', __('Guardify: Cancelled by Steadfast courier.', 'guardify'));
                }
                break;
                
            case 'partial_delivered':
            case 'partial_delivered_approval_pending':
                // Add note but don't change status automatically
                $order->add_order_note(__('Guardify: Partially delivered by Steadfast courier.', 'guardify'));
                break;
                
            case 'hold':
                $order->add_order_note(__('Guardify: Order on hold at Steadfast courier.', 'guardify'));
                break;
        }
    }

    /**
     * Send order to SteadFast API
     */
    private function send_to_steadfast(int $order_id): string {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return __('Order not found', 'guardify');
        }

        // Get custom amount or use order total
        $custom_amount = $this->get_order_meta($order_id, '_guardify_steadfast_amount');
        $cod_amount = !empty($custom_amount) || $custom_amount === '0' ? (int)$custom_amount : (int)$order->get_total();

        // Build recipient info
        $first_name = $order->get_billing_first_name();
        $last_name = $order->get_billing_last_name();
        $phone = $order->get_billing_phone();
        
        // Normalize phone number (ensure 11 digits starting with 0)
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) > 10) {
            $phone = '0' . substr($phone, -10);
        }

        // Build address
        $address_parts = array_filter(array(
            $order->get_billing_address_1(),
            $order->get_billing_city(),
            $order->get_billing_postcode()
        ));
        $address = implode(', ', $address_parts);

        // Build request body
        $body = array(
            'invoice' => gmdate('ymd') . '-' . $order_id,
            'recipient_name' => trim($first_name . ' ' . $last_name),
            'recipient_phone' => $phone,
            'recipient_address' => $address,
            'cod_amount' => $cod_amount,
            'note' => $this->should_send_notes() ? $order->get_customer_note() : '',
        );

        $response = $this->courier_request('send', $body);
        
        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        // Handle validation errors
        if (isset($data['status']) && $data['status'] == 400 && isset($data['errors'])) {
            foreach ($data['errors'] as $field => $messages) {
                return implode(', ', $messages);
            }
        }

        // Success
        if (isset($data['status']) && $data['status'] == 200) {
            $consignment_id = $data['consignment']['consignment_id'] ?? '';
            if ($consignment_id) {
                $this->update_order_meta($order_id, '_guardify_steadfast_consignment_id', $consignment_id);
            }
            return 'success';
        }

        return 'unauthorized';
    }

    /**
     * Make API request via TansiqLabs courier proxy.
     * Credentials are stored on TansiqLabs — plugin sends only its site API key.
     */
    private function courier_request(string $action, array $body = array()) {
        $site_api_key = $this->get_site_api_key();
        if (empty($site_api_key)) {
            return new \WP_Error('no_api_key', __('Site API key not configured. Activate your license first.', 'guardify'));
        }

        $body['api_key'] = $site_api_key;

        return wp_remote_post(
            self::COURIER_API_URL . $action,
            array(
                'headers' => array('Content-Type' => 'application/json'),
                'body'    => wp_json_encode($body),
                'timeout' => 45,
            )
        );
    }

    /**
     * Add invoice page to admin menu
     */
    public function add_invoice_page(): void {
        add_dashboard_page(
            __('Guardify Invoice', 'guardify'),
            __('Guardify Invoice', 'guardify'),
            'manage_options',
            'guardify-invoice',
            array($this, 'invoice_page_callback')
        );
    }

    /**
     * Invoice page callback (hidden page)
     */
    public function invoice_page_callback(): void {
        echo esc_html__('Guardify Invoice', 'guardify');
    }

    /**
     * Render invoice template
     */
    public function render_invoice_template(): void {
        if (!isset($_GET['page']) || $_GET['page'] !== 'guardify-invoice') {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'guardify_print_invoice')) {
            return;
        }

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $consignment_id = isset($_GET['consignment_id']) ? sanitize_text_field($_GET['consignment_id']) : '';

        if (!$order_id) {
            wp_redirect(home_url());
            exit;
        }

        // Include invoice template
        include GUARDIFY_PLUGIN_DIR . 'templates/invoice.php';
        exit;
    }

    /**
     * Render bulk refresh button above orders table
     */
    public function render_bulk_refresh_button($which = 'top'): void {
        if ($which !== 'top') {
            return;
        }
        
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, array('edit-shop_order', 'woocommerce_page_wc-orders'))) {
            return;
        }
        
        ?>
        <button type="button" class="guardify-sf-bulk-refresh" title="<?php esc_attr_e('Refresh delivery statuses for all pending Steadfast orders', 'guardify'); ?>">
            <span class="dashicons dashicons-update"></span>
            <?php _e('Refresh Statuses', 'guardify'); ?>
        </button>
        <?php
    }

    /**
     * Add row class (Legacy)
     */
    public function add_row_class(array $classes, $class, $post_id): array {
        if (is_admin()) {
            $screen = get_current_screen();
            if ($screen && $screen->base === 'edit' && $screen->post_type === 'shop_order') {
                $classes[] = 'no-link';
            }
        }
        return $classes;
    }

    /**
     * Add row class (HPOS)
     */
    public function add_row_class_hpos(array $classes): array {
        $classes[] = 'no-link';
        return $classes;
    }

    /**
     * Get order meta (HPOS compatible)
     */
    private function get_order_meta(int $order_id, string $key): string {
        $order = wc_get_order($order_id);
        if (!$order) {
            return '';
        }
        return $order->get_meta($key) ?: '';
    }

    /**
     * Update order meta (HPOS compatible)
     */
    private function update_order_meta(int $order_id, string $key, string $value): void {
        $order = wc_get_order($order_id);
        if ($order) {
            $order->update_meta_data($key, $value);
            $order->save();
        }
    }

    /**
     * Get order customer details for invoice
     */
    public function get_order_details(int $order_id): array {
        $order = wc_get_order($order_id);
        if (!$order) {
            return array();
        }

        $custom_amount = $this->get_order_meta($order_id, '_guardify_steadfast_amount');
        $cod_amount = !empty($custom_amount) || $custom_amount === '0' ? (int)$custom_amount : (int)$order->get_total();

        return array(
            'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customer_email' => $order->get_billing_email(),
            'customer_phone' => $order->get_billing_phone(),
            'customer_address' => array_filter(array(
                $order->get_billing_address_1(),
                $order->get_billing_city(),
                $order->get_billing_postcode(),
                $order->get_billing_country()
            )),
            'cod_amount' => $cod_amount,
            'payment_method' => $order->get_payment_method_title(),
            'subtotal' => $order->get_subtotal(),
            'shipping_total' => $order->get_shipping_total(),
            'total' => $order->get_total(),
        );
    }

    /**
     * Get order product details for invoice
     */
    public function get_product_details(int $order_id): array {
        $order = wc_get_order($order_id);
        if (!$order) {
            return array();
        }

        $products = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $description = $product ? wp_trim_words(strip_tags($product->get_description()), 7, '...') : '';
            
            $products[] = array(
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'price' => $product ? $product->get_price() : 0,
                'subtotal' => $item->get_subtotal(),
                'description' => $description,
                'sku' => $product ? $product->get_sku() : '',
            );
        }

        return $products;
    }
}
