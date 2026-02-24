<?php
/**
 * Guardify Pathao Courier Integration Class
 * Pathao Courier API integration - Send orders directly to Pathao
 * 
 * @package Guardify
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Guardify_Pathao {
    private static ?self $instance = null;
    
    /**
     * TansiqLabs Pathao Courier Proxy URL
     * All courier API calls go through TansiqLabs — credentials never leave the server.
     */
    private const COURIER_API_URL = 'https://tansiqlabs.com/api/guardify/courier/pathao/';

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
            add_action('wp_ajax_guardify_pathao_send', array($this, 'ajax_send_to_pathao'));
            add_action('wp_ajax_guardify_pathao_amount', array($this, 'ajax_update_amount'));
            add_action('wp_ajax_guardify_pathao_stores', array($this, 'ajax_get_stores'));
            add_action('wp_ajax_guardify_pathao_locations', array($this, 'ajax_get_locations'));
            add_action('wp_ajax_guardify_pathao_status', array($this, 'ajax_check_delivery_status'));
            add_action('wp_ajax_guardify_pathao_bulk_status', array($this, 'ajax_bulk_status_refresh'));
            
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

            // Render send modal on order list pages
            add_action('admin_footer', array($this, 'render_send_modal'));
        }
        
        // Always load scripts on admin
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
    }

    /**
     * Check if Pathao integration is enabled
     */
    public function is_enabled(): bool {
        return get_option('guardify_pathao_enabled', '0') === '1';
    }

    /**
     * Get site API key (used to authenticate with TansiqLabs courier proxy)
     */
    private function get_site_api_key(): string {
        return get_option('guardify_site_api_key', '');
    }

    /**
     * Get default store ID
     */
    private function get_default_store(): string {
        return get_option('guardify_pathao_default_store', '');
    }

    /**
     * Check if notes should be sent
     */
    private function should_send_notes(): bool {
        return get_option('guardify_pathao_send_notes', '0') === '1';
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook): void {
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
            'guardify-pathao',
            GUARDIFY_PLUGIN_URL . 'assets/js/pathao.js',
            array('jquery'),
            GUARDIFY_VERSION,
            true
        );

        wp_enqueue_style(
            'guardify-pathao',
            GUARDIFY_PLUGIN_URL . 'assets/css/pathao.css',
            array(),
            GUARDIFY_VERSION
        );

        wp_add_inline_script('guardify-pathao',
            'var guardifyPathao = ' . wp_json_encode(array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('guardify-pathao-nonce'),
                'enabled' => $this->is_enabled(),
                'defaultStore' => $this->get_default_store(),
                'i18n' => array(
                    'sending' => __('Sending...', 'guardify'),
                    'sent' => __('✓ Sent', 'guardify'),
                    'failed' => __('Failed!', 'guardify'),
                    'unauthorized' => __('Unauthorized', 'guardify'),
                    'checking' => __('Checking...', 'guardify'),
                    'success' => __('Success', 'guardify'),
                    'copied' => __('Copied!', 'guardify'),
                    'selectCity' => __('Select City', 'guardify'),
                    'selectZone' => __('Select Zone', 'guardify'),
                    'selectArea' => __('Select Area', 'guardify'),
                    'selectStore' => __('Select Store', 'guardify'),
                    'loading' => __('Loading...', 'guardify'),
                    'sendOrder' => __('Send to Pathao', 'guardify'),
                    'cancel' => __('Cancel', 'guardify'),
                ),
            )) . ';',
            'before'
        );
    }

    /**
     * Enqueue frontend scripts (for invoice)
     */
    public function enqueue_frontend_scripts(): void {
        if (isset($_GET['page']) && $_GET['page'] === 'guardify-pathao-invoice' && 
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
            $bulk_actions['guardify_send_pathao'] = __('📦 Send to Pathao', 'guardify');
        }
        return $bulk_actions;
    }

    /**
     * Handle bulk action
     * Note: Bulk send uses default store and requires pre-set location meta per order.
     */
    public function handle_bulk_action(string $redirect, string $action, array $order_ids): string {
        if ($action !== 'guardify_send_pathao') {
            return $redirect;
        }

        $sent_count = 0;
        $skip_count = 0;
        foreach ($order_ids as $order_id) {
            // Check if order has required location meta
            $city_id = $this->get_order_meta($order_id, '_guardify_pathao_city_id');
            $zone_id = $this->get_order_meta($order_id, '_guardify_pathao_zone_id');
            $area_id = $this->get_order_meta($order_id, '_guardify_pathao_area_id');
            
            if (empty($city_id) || empty($zone_id) || empty($area_id)) {
                $skip_count++;
                continue;
            }

            $result = $this->send_to_pathao($order_id);
            if ($result === 'success') {
                $this->update_order_meta($order_id, '_guardify_pathao_sent', 'yes');
                $sent_count++;
            }
        }

        return add_query_arg(array(
            'guardify_pathao_sent' => $sent_count,
            'guardify_pathao_total' => count($order_ids),
            'guardify_pathao_skipped' => $skip_count,
        ), $redirect);
    }

    /**
     * Show admin notice after bulk action
     */
    public function bulk_action_admin_notice(): void {
        if (!isset($_GET['guardify_pathao_sent']) || !isset($_GET['guardify_pathao_total'])) {
            return;
        }

        $sent = intval($_GET['guardify_pathao_sent']);
        $total = intval($_GET['guardify_pathao_total']);
        $skipped = isset($_GET['guardify_pathao_skipped']) ? intval($_GET['guardify_pathao_skipped']) : 0;
        
        if ($sent === $total) {
            $class = 'notice-success';
            $message = sprintf(
                __('✅ All %d orders sent to Pathao successfully!', 'guardify'),
                $sent
            );
        } elseif ($sent > 0) {
            $class = 'notice-warning';
            $extra = $skipped > 0 ? sprintf(__(' %d skipped (no location set).', 'guardify'), $skipped) : '';
            $message = sprintf(
                __('⚠️ %d of %d orders sent.%s', 'guardify'),
                $sent,
                $total,
                $extra
            );
        } else {
            $class = 'notice-error';
            $extra = $skipped > 0 ? sprintf(__(' %d skipped (no location set). Send individually first.', 'guardify'), $skipped) : '';
            $message = sprintf(
                __('❌ Failed to send %d orders.%s', 'guardify'),
                $total,
                $extra
            );
        }

        printf(
            '<div class="notice %s is-dismissible guardify-pt-bulk-notice"><p><strong>Guardify Pathao:</strong> %s</p></div>',
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
                $new_columns['guardify_pt_amount'] = '<span class="dashicons dashicons-money-alt" title="' . esc_attr__('COD Amount', 'guardify') . '"></span> ' . __('Amount', 'guardify');
                $new_columns['guardify_pt_send'] = '<span class="dashicons dashicons-upload" title="' . esc_attr__('Send to Pathao', 'guardify') . '"></span> ' . __('Pathao', 'guardify');
                $new_columns['guardify_pt_print'] = '<span class="dashicons dashicons-printer" title="' . esc_attr__('Print Invoice', 'guardify') . '"></span> ' . __('Print', 'guardify');
                $new_columns['guardify_pt_consignment'] = '<span class="dashicons dashicons-tag" title="' . esc_attr__('Consignment ID', 'guardify') . '"></span> ' . __('Consignment', 'guardify');
                $new_columns['guardify_pt_status'] = '<span class="dashicons dashicons-location" title="' . esc_attr__('Delivery Status', 'guardify') . '"></span> ' . __('Delivery', 'guardify');
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
        $is_sent = $this->get_order_meta($order_id, '_guardify_pathao_sent') === 'yes';
        $consignment_id = $this->get_order_meta($order_id, '_guardify_pathao_consignment_id');
        
        switch ($column) {
            case 'guardify_pt_amount':
                $amount = $this->get_order_meta($order_id, '_guardify_pathao_amount');
                $order = wc_get_order($order_id);
                if (empty($amount) && $order) {
                    $amount = $order->get_total();
                }
                $disabled = $is_sent ? 'disabled' : '';
                $class = $is_sent ? 'guardify-pt-amount-disabled' : '';
                ?>
                <input type="text" 
                       class="guardify-pt-amount <?php echo esc_attr($class); ?>" 
                       data-order-id="<?php echo esc_attr($order_id); ?>"
                       value="<?php echo esc_attr($amount); ?>" 
                       placeholder="৳ 0"
                       title="<?php esc_attr_e('Enter COD amount', 'guardify'); ?>"
                       <?php echo $disabled; ?>>
                <?php
                break;
                
            case 'guardify_pt_send':
                $btn_class = $is_sent ? 'guardify-pt-sent' : 'guardify-pt-send';
                $btn_text = $is_sent ? __('✓ Sent', 'guardify') : __('Send', 'guardify');
                $btn_title = $is_sent ? __('Order already sent to Pathao', 'guardify') : __('Click to send order to Pathao', 'guardify');
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
                
            case 'guardify_pt_print':
                if (!empty($consignment_id)) {
                    $invoice_url = wp_nonce_url(
                        admin_url('index.php?page=guardify-pathao-invoice&order_id=' . $order_id . '&consignment_id=' . $consignment_id),
                        'guardify_print_invoice'
                    );
                    ?>
                    <a href="<?php echo esc_url($invoice_url); ?>" 
                       class="guardify-pt-print" 
                       title="<?php esc_attr_e('Print Invoice', 'guardify'); ?>"
                       target="_blank">
                        <span class="dashicons dashicons-printer"></span>
                    </a>
                    <?php
                } else {
                    echo '<span class="guardify-pt-no-print" title="' . esc_attr__('Send order first', 'guardify') . '">—</span>';
                }
                break;
                
            case 'guardify_pt_consignment':
                if (!empty($consignment_id)) {
                    ?>
                    <div class="guardify-pt-consignment-id" title="<?php esc_attr_e('Click to copy consignment ID', 'guardify'); ?>" onclick="navigator.clipboard.writeText('<?php echo esc_js($consignment_id); ?>'); this.classList.add('copied'); setTimeout(() => this.classList.remove('copied'), 1000);">
                        <?php echo esc_html($consignment_id); ?>
                        <span class="copy-feedback">✓</span>
                    </div>
                    <?php
                } else {
                    echo '<span class="guardify-pt-no-consignment">—</span>';
                }
                break;
                
            case 'guardify_pt_status':
                if (!empty($consignment_id)) {
                    $status = $this->get_order_meta($order_id, '_guardify_pathao_delivery_status');
                    $status_class = $this->get_status_class($status);
                    $status_text = $this->get_status_text($status);
                    ?>
                    <div class="guardify-pt-status-wrap">
                        <?php if (empty($status)): ?>
                            <button class="guardify-pt-check-status" 
                                    data-order-id="<?php echo esc_attr($order_id); ?>"
                                    data-consignment-id="<?php echo esc_attr($consignment_id); ?>"
                                    title="<?php esc_attr_e('Check delivery status', 'guardify'); ?>">
                                <span class="dashicons dashicons-search"></span> <?php _e('Check', 'guardify'); ?>
                            </button>
                        <?php else: ?>
                            <span class="guardify-pt-refresh-status dashicons dashicons-update"
                                  data-order-id="<?php echo esc_attr($order_id); ?>"
                                  data-consignment-id="<?php echo esc_attr($consignment_id); ?>"
                                  title="<?php esc_attr_e('Refresh status', 'guardify'); ?>"></span>
                        <?php endif; ?>
                        <span class="guardify-pt-status <?php echo esc_attr($status_class); ?>" 
                              data-order-id="<?php echo esc_attr($order_id); ?>"
                              title="<?php echo esc_attr($status_text); ?>">
                            <?php echo esc_html($status_text); ?>
                        </span>
                    </div>
                    <?php
                } else {
                    echo '<span class="guardify-pt-no-status">—</span>';
                }
                break;
        }
    }

    /**
     * Get status CSS class
     */
    private function get_status_class(string $status): string {
        $classes = array(
            'Pickup_Pending' => 'guardify-pt-status-pending',
            'Assigned_For_Pickup' => 'guardify-pt-status-pending',
            'Picked_Up' => 'guardify-pt-status-transit',
            'At_The_Sorting_Hub' => 'guardify-pt-status-transit',
            'In_Transit' => 'guardify-pt-status-transit',
            'Out_For_Delivery' => 'guardify-pt-status-transit',
            'Delivered' => 'guardify-pt-status-delivered',
            'Partial_Delivery' => 'guardify-pt-status-partial',
            'Return' => 'guardify-pt-status-return',
            'Delivery_Failed' => 'guardify-pt-status-failed',
            'On_Hold' => 'guardify-pt-status-hold',
            'Pickup_Cancelled' => 'guardify-pt-status-cancelled',
            'Order_Created' => 'guardify-pt-status-pending',
            'Exchange' => 'guardify-pt-status-exchange',
        );
        
        return $classes[$status] ?? '';
    }

    /**
     * Get status display text
     */
    private function get_status_text(string $status): string {
        $texts = array(
            'Pickup_Pending' => __('Pickup Pending', 'guardify'),
            'Assigned_For_Pickup' => __('Pickup Assigned', 'guardify'),
            'Picked_Up' => __('Picked Up', 'guardify'),
            'At_The_Sorting_Hub' => __('At Sorting Hub', 'guardify'),
            'In_Transit' => __('In Transit', 'guardify'),
            'Out_For_Delivery' => __('Out for Delivery', 'guardify'),
            'Delivered' => __('Delivered ✓', 'guardify'),
            'Partial_Delivery' => __('Partial Delivery', 'guardify'),
            'Return' => __('Returned', 'guardify'),
            'Delivery_Failed' => __('Delivery Failed', 'guardify'),
            'On_Hold' => __('On Hold', 'guardify'),
            'Pickup_Cancelled' => __('Cancelled', 'guardify'),
            'Order_Created' => __('Order Created', 'guardify'),
            'Exchange' => __('Exchange', 'guardify'),
        );
        
        return $texts[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * Render the send-to-Pathao modal in admin footer
     */
    public function render_send_modal(): void {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, array('edit-shop_order', 'woocommerce_page_wc-orders'))) {
            return;
        }
        ?>
        <div id="guardify-pathao-modal" class="guardify-pt-modal" style="display:none;">
            <div class="guardify-pt-modal-overlay"></div>
            <div class="guardify-pt-modal-content">
                <div class="guardify-pt-modal-header">
                    <h3><?php _e('Send to Pathao Courier', 'guardify'); ?></h3>
                    <button class="guardify-pt-modal-close">&times;</button>
                </div>
                <div class="guardify-pt-modal-body">
                    <input type="hidden" id="guardify-pt-modal-order-id" value="">
                    
                    <div class="guardify-pt-modal-field">
                        <label for="guardify-pt-store"><?php _e('Store', 'guardify'); ?></label>
                        <select id="guardify-pt-store" class="guardify-pt-select">
                            <option value=""><?php _e('Loading stores...', 'guardify'); ?></option>
                        </select>
                    </div>
                    
                    <div class="guardify-pt-modal-field">
                        <label for="guardify-pt-city"><?php _e('City', 'guardify'); ?></label>
                        <select id="guardify-pt-city" class="guardify-pt-select">
                            <option value=""><?php _e('Loading cities...', 'guardify'); ?></option>
                        </select>
                    </div>
                    
                    <div class="guardify-pt-modal-field">
                        <label for="guardify-pt-zone"><?php _e('Zone', 'guardify'); ?></label>
                        <select id="guardify-pt-zone" class="guardify-pt-select" disabled>
                            <option value=""><?php _e('Select a city first', 'guardify'); ?></option>
                        </select>
                    </div>
                    
                    <div class="guardify-pt-modal-field">
                        <label for="guardify-pt-area"><?php _e('Area', 'guardify'); ?></label>
                        <select id="guardify-pt-area" class="guardify-pt-select" disabled>
                            <option value=""><?php _e('Select a zone first', 'guardify'); ?></option>
                        </select>
                    </div>
                </div>
                <div class="guardify-pt-modal-footer">
                    <button id="guardify-pt-modal-cancel" class="button"><?php _e('Cancel', 'guardify'); ?></button>
                    <button id="guardify-pt-modal-send" class="button button-primary"><?php _e('Send to Pathao', 'guardify'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Send order to Pathao
     */
    public function ajax_send_to_pathao(): void {
        check_ajax_referer('guardify-pathao-nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
            return;
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $store_id = isset($_POST['store_id']) ? sanitize_text_field($_POST['store_id']) : '';
        $city_id = isset($_POST['city_id']) ? sanitize_text_field($_POST['city_id']) : '';
        $zone_id = isset($_POST['zone_id']) ? sanitize_text_field($_POST['zone_id']) : '';
        $area_id = isset($_POST['area_id']) ? sanitize_text_field($_POST['area_id']) : '';
        
        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid order ID', 'guardify')));
            return;
        }

        // Save location data to order meta for future bulk sends
        if (!empty($store_id)) {
            $this->update_order_meta($order_id, '_guardify_pathao_store_id', $store_id);
        }
        if (!empty($city_id)) {
            $this->update_order_meta($order_id, '_guardify_pathao_city_id', $city_id);
        }
        if (!empty($zone_id)) {
            $this->update_order_meta($order_id, '_guardify_pathao_zone_id', $zone_id);
        }
        if (!empty($area_id)) {
            $this->update_order_meta($order_id, '_guardify_pathao_area_id', $area_id);
        }

        $result = $this->send_to_pathao($order_id);
        
        if ($result === 'success') {
            $this->update_order_meta($order_id, '_guardify_pathao_sent', 'yes');
            wp_send_json_success(array('message' => __('Order sent to Pathao successfully', 'guardify')));
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
        check_ajax_referer('guardify-pathao-nonce', 'nonce');

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

        $this->update_order_meta($order_id, '_guardify_pathao_amount', $amount);
        wp_send_json_success(array('message' => __('Amount updated', 'guardify')));
    }

    /**
     * AJAX: Get Pathao stores
     */
    public function ajax_get_stores(): void {
        check_ajax_referer('guardify-pathao-nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
            return;
        }

        // Check transient cache first
        $cached = get_transient('guardify_pathao_stores');
        if ($cached !== false) {
            wp_send_json_success(array('stores' => $cached));
            return;
        }

        $response = $this->courier_request('stores');
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $stores = $data['data']['data'] ?? array();
        
        // Cache for 1 hour
        set_transient('guardify_pathao_stores', $stores, HOUR_IN_SECONDS);
        
        wp_send_json_success(array('stores' => $stores));
    }

    /**
     * AJAX: Get Pathao locations (cities/zones/areas)
     */
    public function ajax_get_locations(): void {
        check_ajax_referer('guardify-pathao-nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
            return;
        }

        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        $city_id = isset($_POST['city_id']) ? sanitize_text_field($_POST['city_id']) : '';
        $zone_id = isset($_POST['zone_id']) ? sanitize_text_field($_POST['zone_id']) : '';

        if (!in_array($type, array('cities', 'zones', 'areas'))) {
            wp_send_json_error(array('message' => __('Invalid location type', 'guardify')));
            return;
        }

        // Check transient cache
        $cache_key = 'guardify_pathao_' . $type;
        if ($type === 'zones') $cache_key .= '_' . $city_id;
        if ($type === 'areas') $cache_key .= '_' . $zone_id;
        
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            wp_send_json_success(array('locations' => $cached));
            return;
        }

        $body = array('type' => $type);
        if ($type === 'zones' && $city_id) {
            $body['city_id'] = $city_id;
        }
        if ($type === 'areas' && $zone_id) {
            $body['zone_id'] = $zone_id;
        }

        $response = $this->courier_request('locations', $body);
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $locations = $data['data']['data'] ?? $data['data'] ?? array();
        
        // Cache based on type (cities longer, zones/areas shorter)
        $ttl = ($type === 'cities') ? DAY_IN_SECONDS : HOUR_IN_SECONDS;
        set_transient($cache_key, $locations, $ttl);
        
        wp_send_json_success(array('locations' => $locations));
    }

    /**
     * AJAX: Check delivery status
     */
    public function ajax_check_delivery_status(): void {
        check_ajax_referer('guardify-pathao-nonce', 'nonce');

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
        
        // Pathao returns status in data.order_status
        $delivery_status = $data['data']['order_status'] ?? ($data['delivery_status'] ?? '');
        
        if (!empty($delivery_status)) {
            $this->update_order_meta($order_id, '_guardify_pathao_delivery_status', $delivery_status);
            wp_send_json_success(array('status' => $delivery_status));
        } else {
            wp_send_json_error(array('message' => __('Failed to get status', 'guardify')));
        }
    }

    /**
     * AJAX: Bulk refresh delivery statuses for all sent orders
     */
    public function ajax_bulk_status_refresh(): void {
        check_ajax_referer('guardify-pathao-nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
            return;
        }

        if (!$this->is_enabled()) {
            wp_send_json_error(array('message' => __('Pathao integration is disabled', 'guardify')));
            return;
        }

        global $wpdb;
        $updated = 0;
        $errors = 0;

        // Final statuses that don't need refreshing
        $final_statuses = array('Delivered', 'Pickup_Cancelled', 'Return');

        if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && 
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $meta_table = $wpdb->prefix . 'wc_orders_meta';
            
            $final_in = "'" . implode("','", array_map('esc_sql', $final_statuses)) . "'";
            $orders = $wpdb->get_results(
                "SELECT m1.order_id, m1.meta_value as consignment_id, COALESCE(m2.meta_value, '') as current_status
                FROM {$meta_table} m1
                LEFT JOIN {$meta_table} m2 ON m1.order_id = m2.order_id AND m2.meta_key = '_guardify_pathao_delivery_status'
                WHERE m1.meta_key = '_guardify_pathao_consignment_id'
                AND m1.meta_value != ''
                AND (m2.meta_value IS NULL OR m2.meta_value NOT IN ({$final_in}))
                LIMIT 50"
            );
        } else {
            $final_in = "'" . implode("','", array_map('esc_sql', $final_statuses)) . "'";
            $orders = $wpdb->get_results(
                "SELECT pm1.post_id as order_id, pm1.meta_value as consignment_id, COALESCE(pm2.meta_value, '') as current_status
                FROM {$wpdb->postmeta} pm1
                LEFT JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_guardify_pathao_delivery_status'
                WHERE pm1.meta_key = '_guardify_pathao_consignment_id'
                AND pm1.meta_value != ''
                AND (pm2.meta_value IS NULL OR pm2.meta_value NOT IN ({$final_in}))
                LIMIT 50"
            );
        }

        foreach ($orders as $order_row) {
            $response = $this->courier_request('status', array('consignment_id' => $order_row->consignment_id));
            
            if (!is_wp_error($response)) {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                $delivery_status = $data['data']['order_status'] ?? ($data['delivery_status'] ?? '');
                
                if (!empty($delivery_status)) {
                    $this->update_order_meta($order_row->order_id, '_guardify_pathao_delivery_status', $delivery_status);
                    $this->sync_wc_order_status($order_row->order_id, $delivery_status);
                    $updated++;
                } else {
                    $errors++;
                }
            } else {
                $errors++;
            }
            
            usleep(100000); // 100ms delay to avoid rate limiting
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
     * Sync WooCommerce order status based on Pathao delivery status
     */
    private function sync_wc_order_status(int $order_id, string $delivery_status): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $current_status = $order->get_status();
        
        if (in_array($current_status, array('completed', 'refunded'))) {
            return;
        }

        switch ($delivery_status) {
            case 'Delivered':
                if ($current_status !== 'completed') {
                    $order->update_status('completed', __('Guardify: Marked as delivered by Pathao courier.', 'guardify'));
                }
                break;
                
            case 'Pickup_Cancelled':
                if (!in_array($current_status, array('cancelled', 'failed'))) {
                    $order->update_status('cancelled', __('Guardify: Pickup cancelled by Pathao courier.', 'guardify'));
                }
                break;
                
            case 'Return':
            case 'Delivery_Failed':
                $order->add_order_note(
                    sprintf(__('Guardify: Pathao delivery status changed to %s.', 'guardify'), $delivery_status)
                );
                break;
                
            case 'Partial_Delivery':
                $order->add_order_note(__('Guardify: Partially delivered by Pathao courier.', 'guardify'));
                break;
                
            case 'On_Hold':
                $order->add_order_note(__('Guardify: Order on hold at Pathao courier.', 'guardify'));
                break;
        }
    }

    /**
     * Send order to Pathao Courier API
     */
    private function send_to_pathao(int $order_id): string {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return __('Order not found', 'guardify');
        }

        // Get location IDs from order meta
        $store_id = $this->get_order_meta($order_id, '_guardify_pathao_store_id');
        $city_id = $this->get_order_meta($order_id, '_guardify_pathao_city_id');
        $zone_id = $this->get_order_meta($order_id, '_guardify_pathao_zone_id');
        $area_id = $this->get_order_meta($order_id, '_guardify_pathao_area_id');

        // Use defaults if not set
        if (empty($store_id)) {
            $store_id = $this->get_default_store();
        }

        if (empty($store_id) || empty($city_id) || empty($zone_id) || empty($area_id)) {
            return __('Missing Pathao location data. Please set city, zone, and area before sending.', 'guardify');
        }

        // Get custom amount or use order total
        $custom_amount = $this->get_order_meta($order_id, '_guardify_pathao_amount');
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
            $order->get_billing_address_2(),
            $order->get_billing_city(),
            $order->get_billing_postcode()
        ));
        $address = implode(', ', $address_parts);

        // Build Pathao order
        $body = array(
            'store_id' => (int)$store_id,
            'merchant_order_id' => gmdate('ymd') . '-' . $order_id,
            'recipient_name' => trim($first_name . ' ' . $last_name),
            'recipient_phone' => $phone,
            'recipient_address' => $address,
            'amount_to_collect' => $cod_amount,
            'recipient_city' => (int)$city_id,
            'recipient_zone' => (int)$zone_id,
            'recipient_area' => (int)$area_id,
            'delivery_type' => 48, // Normal delivery
            'item_type' => 2,      // Parcel
            'item_quantity' => max(1, $order->get_item_count()),
            'item_weight' => 0.5,
            'special_instruction' => $this->should_send_notes() ? $order->get_customer_note() : '',
        );

        // Save the amount we're sending
        $this->update_order_meta($order_id, '_guardify_pathao_amount', (string)$cod_amount);

        $response = $this->courier_request('send', $body);
        
        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        // Handle validation errors
        if ($response_code >= 400 && isset($data['errors'])) {
            $error_messages = array();
            foreach ($data['errors'] as $field => $messages) {
                if (is_array($messages)) {
                    $error_messages[] = implode(', ', $messages);
                } else {
                    $error_messages[] = $messages;
                }
            }
            return implode('; ', $error_messages);
        }

        if (isset($data['message']) && !isset($data['data'])) {
            return $data['message'];
        }

        // Success - Pathao returns consignment_id in data
        $consignment_id = $data['data']['consignment_id'] ?? ($data['consignment_id'] ?? '');
        if ($consignment_id) {
            $this->update_order_meta($order_id, '_guardify_pathao_consignment_id', (string)$consignment_id);
            return 'success';
        }

        // Check for successful response code
        if ($response_code == 200 || $response_code == 201) {
            return 'success';
        }

        return $data['message'] ?? 'unauthorized';
    }

    /**
     * Make API request via TansiqLabs Pathao courier proxy.
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
            __('Guardify Pathao Invoice', 'guardify'),
            __('Guardify Pathao Invoice', 'guardify'),
            'manage_options',
            'guardify-pathao-invoice',
            array($this, 'invoice_page_callback')
        );
    }

    /**
     * Invoice page callback (hidden page)
     */
    public function invoice_page_callback(): void {
        echo esc_html__('Guardify Pathao Invoice', 'guardify');
    }

    /**
     * Render invoice template
     */
    public function render_invoice_template(): void {
        if (!isset($_GET['page']) || $_GET['page'] !== 'guardify-pathao-invoice') {
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

        // Include invoice template (shared with SteadFast)
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
        <button type="button" class="guardify-pt-bulk-refresh" title="<?php esc_attr_e('Refresh delivery statuses for all pending Pathao orders', 'guardify'); ?>">
            <span class="dashicons dashicons-update"></span>
            <?php _e('Refresh Pathao', 'guardify'); ?>
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

        $custom_amount = $this->get_order_meta($order_id, '_guardify_pathao_amount');
        $cod_amount = !empty($custom_amount) || $custom_amount === '0' ? (int)$custom_amount : (int)$order->get_total();

        return array(
            'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customer_email' => $order->get_billing_email(),
            'customer_phone' => $order->get_billing_phone(),
            'customer_address' => array_filter(array(
                $order->get_billing_address_1(),
                $order->get_billing_address_2(),
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
