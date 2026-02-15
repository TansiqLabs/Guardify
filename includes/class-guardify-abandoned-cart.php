<?php
/**
 * Guardify Abandoned Cart / Incomplete Order Capture
 * 
 * ব্রাউজার থেকে চেকআউট ফর্ম ফিলআপ শুরু করলেই ডেটা ক্যাপচার করে।
 * সাবমিট না করলে "Incomplete" স্ট্যাটাসে অর্ডার তৈরি হয়।
 * 
 * Features:
 * - Real-time AJAX field capture as user types (debounced)
 * - Browser close / tab switch detection (visibilitychange + beforeunload)
 * - CartFlows checkout full support (_wcf_flow_id, _wcf_checkout_id)
 * - WooCommerce HPOS compatible
 * - LiteSpeed Cache + Redis safe (uses admin-ajax.php, not REST)
 * - Custom "wc-incomplete" order status registered in WooCommerce
 * - Incomplete orders show in the regular Orders list
 * - Auto-cleanup of old incomplete orders (configurable)
 * 
 * @package Guardify
 * @since 1.0.9
 */

if (!defined('ABSPATH')) {
    exit;
}

class Guardify_Abandoned_Cart {
    private static ?self $instance = null;

    /**
     * The session key used in a cookie to track the current draft order
     */
    private const COOKIE_NAME = 'guardify_draft_order';

    /**
     * Custom order status slug (without 'wc-' prefix for registration, with prefix for WC usage)
     */
    private const STATUS_SLUG = 'incomplete';
    private const WC_STATUS   = 'wc-incomplete';

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Only proceed if feature is enabled
        if (!$this->is_enabled()) {
            return;
        }

        // ─── Register custom order status ───
        add_action('init', [$this, 'register_order_status']);
        add_filter('wc_order_statuses', [$this, 'add_order_status_to_list']);
        add_filter('woocommerce_valid_order_statuses_for_payment', [$this, 'allow_payment_for_incomplete']);
        
        // Make incomplete orders appear in "All" count on orders page
        add_filter('woocommerce_reports_order_statuses', [$this, 'add_to_reports']);

        // ─── AJAX endpoints (admin-ajax.php — NOT cached by LiteSpeed) ───
        add_action('wp_ajax_guardify_capture_checkout', [$this, 'ajax_capture_checkout']);
        add_action('wp_ajax_nopriv_guardify_capture_checkout', [$this, 'ajax_capture_checkout']);

        // ─── AJAX: Nonce refresh (for cached checkout pages) ───
        add_action('wp_ajax_guardify_refresh_nonce', [$this, 'ajax_refresh_nonce']);
        add_action('wp_ajax_nopriv_guardify_refresh_nonce', [$this, 'ajax_refresh_nonce']);

        // ─── Frontend scripts on checkout pages ───
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        // ─── When a real order is placed, clean up the draft ───
        add_action('woocommerce_checkout_order_created', [$this, 'cleanup_draft_on_order'], 5, 1);
        add_action('woocommerce_thankyou', [$this, 'cleanup_draft_cookie'], 5);

        // ─── Scheduled cleanup of stale incomplete orders ───
        add_action('guardify_daily_cleanup', [$this, 'cleanup_old_incomplete_orders']);

        // ─── Admin: Style the status badge in order list ───
        add_action('admin_head', [$this, 'admin_status_styles']);

        // ─── Bulk actions for incomplete orders ───
        add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'add_bulk_actions']);
        add_filter('bulk_actions-edit-shop_order', [$this, 'add_bulk_actions']);

        // ─── LiteSpeed Cache: Ensure our AJAX is never cached ───
        add_action('litespeed_control_set_nocache', [$this, 'maybe_nocache_our_ajax'], 1);
    }

    // =========================================================================
    // FEATURE TOGGLE
    // =========================================================================

    private function is_enabled(): bool {
        return get_option('guardify_abandoned_cart_enabled', '1') === '1';
    }

    // =========================================================================
    // CUSTOM ORDER STATUS
    // =========================================================================

    /**
     * Register "Incomplete" order status with WooCommerce
     */
    public function register_order_status(): void {
        register_post_status(self::WC_STATUS, [
            'label'                     => _x('Incomplete', 'Order status', 'guardify'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: number of orders */
            'label_count'               => _n_noop(
                'Incomplete <span class="count">(%s)</span>',
                'Incomplete <span class="count">(%s)</span>',
                'guardify'
            ),
        ]);
    }

    /**
     * Add "Incomplete" to WooCommerce order status dropdown
     */
    public function add_order_status_to_list(array $statuses): array {
        $statuses[self::WC_STATUS] = _x('Incomplete', 'Order status', 'guardify');
        return $statuses;
    }

    /**
     * Allow payment for incomplete orders (so customer can complete later)
     */
    public function allow_payment_for_incomplete(array $statuses): array {
        $statuses[] = self::STATUS_SLUG;
        return $statuses;
    }

    /**
     * Include in WC reports
     */
    public function add_to_reports(array $statuses): array {
        $statuses[] = self::STATUS_SLUG;
        return $statuses;
    }

    // =========================================================================
    // FRONTEND SCRIPTS
    // =========================================================================

    /**
     * Enqueue the abandoned cart capture script on checkout pages
     */
    public function enqueue_scripts(): void {
        // Only load on checkout pages (standard WooCommerce + CartFlows)
        if (!$this->is_checkout_page()) {
            return;
        }

        wp_enqueue_script(
            'guardify-abandoned-cart',
            GUARDIFY_PLUGIN_URL . 'assets/js/abandoned-cart.js',
            ['jquery'],
            GUARDIFY_VERSION,
            true
        );

        // Data for JS — uses admin-ajax.php (never cached by LiteSpeed)
        wp_localize_script('guardify-abandoned-cart', 'guardifyAbandonedCart', [
            'ajaxurl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('guardify_capture_checkout'),
            'draft_id'  => $this->get_draft_order_id(),
            'debounce'  => (int) get_option('guardify_abandoned_cart_debounce', 5000),  // ms
            'capture_on_input' => get_option('guardify_abandoned_cart_capture_on_input', '1') === '1',
        ]);

        // Inline CSS for frontend (minimal)
        wp_add_inline_style('woocommerce-general', '
            /* Guardify: hide draft order notice if any */
            .woocommerce-info.guardify-draft-notice { display: none; }
        ');
    }

    /**
     * Detect if current page is a checkout page (WooCommerce or CartFlows)
     */
    private function is_checkout_page(): bool {
        // Standard WooCommerce checkout
        if (function_exists('is_checkout') && is_checkout()) {
            return true;
        }

        // CartFlows checkout detection
        if (class_exists('Cartflows_Loader')) {
            global $post;
            if ($post) {
                $step_type = get_post_meta($post->ID, 'wcf-step-type', true);
                if ($step_type === 'checkout') {
                    return true;
                }
            }
        }

        // CartFlows global checkout
        if (class_exists('Cartflows_Global_Checkout')) {
            // If CartFlows has overridden the checkout, WooCommerce's is_checkout() should be true
            // but as a fallback, check the filter
            if (has_filter('woocommerce_is_checkout') && apply_filters('woocommerce_is_checkout', false)) {
                return true;
            }
        }

        // Fallback: detect checkout-like pages by shortcode or page content
        // This covers custom checkout builders (Elementor Pro, Divi, etc.)
        global $post;
        if ($post && is_a($post, 'WP_Post')) {
            // Check for WooCommerce checkout shortcode
            if (has_shortcode($post->post_content, 'woocommerce_checkout')) {
                return true;
            }
            // Check if current page IS the WooCommerce checkout page by ID
            $checkout_page_id = wc_get_page_id('checkout');
            if ($checkout_page_id && $post->ID == $checkout_page_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * AJAX endpoint: Refresh the nonce
     * Used when LiteSpeed/Varnish serves a cached checkout page with a stale nonce.
     * This endpoint doesn't require a nonce itself (it generates one).
     */
    public function ajax_refresh_nonce(): void {
        // LiteSpeed: don't cache this
        do_action('litespeed_control_set_nocache', 'guardify nonce refresh');

        wp_send_json_success([
            'nonce' => wp_create_nonce('guardify_capture_checkout'),
        ]);
    }

    // =========================================================================
    // AJAX HANDLER — CAPTURE CHECKOUT DATA
    // =========================================================================

    /**
     * AJAX endpoint to capture checkout field data and create/update a draft order.
     * 
     * Uses admin-ajax.php which is NEVER cached by LiteSpeed (even with aggressive preset).
     * Redis object cache is transparent and doesn't affect AJAX.
     * 
     * Called when:
     * 1. Page load (immediately — even with zero form data, just browser metadata)
     * 2. User fills in a field and moves away (blur event, debounced)
     * 3. User closes browser / switches tab (visibilitychange / beforeunload via sendBeacon)
     */
    public function ajax_capture_checkout(): void {
        // Verify nonce
        if (!check_ajax_referer('guardify_capture_checkout', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            return;
        }

        // LiteSpeed: Explicitly tell LiteSpeed not to cache this response
        do_action('litespeed_control_set_nocache', 'guardify abandoned cart ajax');

        // Sanitize input fields
        $data = $this->sanitize_checkout_data($_POST);

        // ── PERFORMANCE FIX: Skip order creation on page_load with zero form data ──
        // page_load fires every checkout visit. Creating a WC order (DB write + cart loop)
        // on every visit is extremely heavy. Only create an order once user interacts.
        $trigger = $data['_capture_trigger'] ?? 'page_load';
        $has_identity = !empty($data['billing_phone']) || !empty($data['billing_email']) || !empty($data['billing_first_name']);

        if ($trigger === 'page_load' && !$has_identity) {
            wp_send_json_success([
                'draft_id'  => 0,
                'message'   => 'Page load captured (no order created yet)',
                'new_nonce' => wp_create_nonce('guardify_capture_checkout'),
            ]);
            return;
        }

        // Get or create draft order
        $order_id = $this->get_or_create_draft_order($data);

        if (is_wp_error($order_id)) {
            wp_send_json_error(['message' => $order_id->get_error_message()], 500);
            return;
        }

        // Update the draft order with latest data
        $this->update_draft_order($order_id, $data);

        wp_send_json_success([
            'draft_id'  => $order_id,
            'message'   => 'Checkout data captured',
            'new_nonce' => wp_create_nonce('guardify_capture_checkout'),
        ]);
    }

    /**
     * Sanitize checkout POST data (billing, shipping, browser metadata, custom fields)
     */
    private function sanitize_checkout_data(array $raw): array {
        $fields = [
            'billing_first_name', 'billing_last_name', 'billing_company',
            'billing_address_1', 'billing_address_2', 'billing_city',
            'billing_state', 'billing_postcode', 'billing_country',
            'billing_email', 'billing_phone',
            'shipping_first_name', 'shipping_last_name', 'shipping_company',
            'shipping_address_1', 'shipping_address_2', 'shipping_city',
            'shipping_state', 'shipping_postcode', 'shipping_country',
            'order_comments',
        ];

        $data = [];
        foreach ($fields as $field) {
            $data[$field] = isset($raw[$field]) ? sanitize_text_field(wp_unslash($raw[$field])) : '';
        }

        // CartFlows specific fields
        $data['_wcf_flow_id']     = isset($raw['_wcf_flow_id']) ? absint($raw['_wcf_flow_id']) : 0;
        $data['_wcf_checkout_id'] = isset($raw['_wcf_checkout_id']) ? absint($raw['_wcf_checkout_id']) : 0;

        // Capture source info
        $data['_capture_url']     = isset($raw['capture_url']) ? esc_url_raw(wp_unslash($raw['capture_url'])) : '';
        $data['_capture_trigger'] = isset($raw['capture_trigger']) ? sanitize_text_field($raw['capture_trigger']) : 'field_blur';

        // Existing draft order ID
        $data['_draft_order_id']  = isset($raw['draft_id']) ? absint($raw['draft_id']) : 0;

        // ─── BROWSER METADATA ───
        $browser_fields = [
            'browser_ua', 'browser_language', 'browser_platform',
            'screen_width', 'screen_height', 'viewport_width', 'viewport_height',
            'referrer', 'timezone', 'page_url', 'page_title',
            'connection_type', 'device_memory', 'hardware_concurrency',
            'touch_support', 'cookie_enabled', 'do_not_track',
            'color_depth', 'pixel_ratio',
        ];
        foreach ($browser_fields as $bf) {
            $data['_browser_' . $bf] = isset($raw[$bf]) ? sanitize_text_field(wp_unslash($raw[$bf])) : '';
        }

        // UTM / campaign parameters
        $utm_fields = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid'];
        foreach ($utm_fields as $uf) {
            $key = 'param_' . $uf;
            $data['_' . $key] = isset($raw[$key]) ? sanitize_text_field(wp_unslash($raw[$key])) : '';
        }

        // ─── CUSTOM / DYNAMIC CHECKOUT FIELDS ───
        // Sent as JSON from frontend (size, color, any WooCommerce custom checkout field)
        $data['_custom_fields'] = [];
        if (!empty($raw['custom_fields'])) {
            $custom = json_decode(wp_unslash($raw['custom_fields']), true);
            if (is_array($custom)) {
                foreach ($custom as $key => $value) {
                    $safe_key = sanitize_key($key);
                    $safe_val = sanitize_text_field($value);
                    if ($safe_key && $safe_val !== '') {
                        $data['_custom_fields'][$safe_key] = $safe_val;
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Get existing draft order ID or create a new one
     */
    private function get_or_create_draft_order(array $data): int|\WP_Error {
        // 1. Check if JS sent an existing draft ID
        $draft_id = $data['_draft_order_id'];

        // 2. Check cookie
        if (!$draft_id) {
            $draft_id = $this->get_draft_order_id();
        }

        // 3. Validate existing draft
        if ($draft_id) {
            $order = wc_get_order($draft_id);
            if ($order && $order->get_status() === self::STATUS_SLUG) {
                return $draft_id;
            }
            // Draft no longer valid, create new one
            $draft_id = 0;
        }

        // 4. Check if there's already a recent incomplete order with same phone/email
        //    to avoid duplicates (e.g., page refresh)
        $existing = $this->find_existing_draft($data);
        if ($existing) {
            $this->set_draft_cookie($existing);
            return $existing;
        }

        // 5. Create a new order with "incomplete" status
        return $this->create_draft_order($data);
    }

    /**
     * Find an existing recent incomplete order with matching phone, email, or IP
     */
    private function find_existing_draft(array $data): int {
        // If we have phone or email, look for a matching recent draft
        if (!empty($data['billing_phone']) || !empty($data['billing_email'])) {
            $args = [
                'status'  => self::STATUS_SLUG,
                'limit'   => 1,
                'orderby' => 'date',
                'order'   => 'DESC',
                'date_created' => '>' . gmdate('Y-m-d H:i:s', strtotime('-2 hours')),
            ];

            // Prefer phone match (more reliable for BD market)
            if (!empty($data['billing_phone'])) {
                $args['billing_phone'] = $data['billing_phone'];
            } elseif (!empty($data['billing_email'])) {
                $args['billing_email'] = $data['billing_email'];
            }

            $orders = wc_get_orders($args);
            if (!empty($orders)) {
                return $orders[0]->get_id();
            }
        }

        // Fallback: find by IP + user agent (for browser-only captures with no form data)
        $ip = $this->get_client_ip();
        if ($ip && $ip !== '0.0.0.0') {
            $orders = wc_get_orders([
                'status'       => self::STATUS_SLUG,
                'limit'        => 1,
                'orderby'      => 'date',
                'order'        => 'DESC',
                'date_created' => '>' . gmdate('Y-m-d H:i:s', strtotime('-2 hours')),
                'customer_ip_address' => $ip,
            ]);

            if (!empty($orders)) {
                // Verify no identifiable data on this existing draft (so we don't merge different visitors on same IP)
                $existing = $orders[0];
                if (empty($existing->get_billing_phone()) && empty($existing->get_billing_email())) {
                    return $existing->get_id();
                }
            }
        }

        return 0;
    }

    /**
     * Create a new WooCommerce order with "incomplete" status
     */
    private function create_draft_order(array $data): int|\WP_Error {
        try {
            $order = wc_create_order([
                'status' => self::STATUS_SLUG,
            ]);

            if (is_wp_error($order)) {
                return $order;
            }

            // Add cart items if WooCommerce cart is available
            if (WC()->cart && !WC()->cart->is_empty()) {
                foreach (WC()->cart->get_cart() as $cart_item) {
                    $product = $cart_item['data'];
                    $quantity = $cart_item['quantity'];
                    
                    if ($product) {
                        $order->add_product($product, $quantity, [
                            'subtotal' => $cart_item['line_subtotal'] ?? '',
                            'total'    => $cart_item['line_total'] ?? '',
                        ]);
                    }
                }
                
                // Set totals from cart
                $order->set_shipping_total(WC()->cart->get_shipping_total());
                $order->set_discount_total(WC()->cart->get_discount_total());
                $order->set_discount_tax(WC()->cart->get_discount_tax());
                $order->set_cart_tax(WC()->cart->get_cart_contents_tax());
                $order->set_shipping_tax(WC()->cart->get_shipping_tax());
                $order->set_total(WC()->cart->get_total('edit'));
            }

            // Store core metadata
            $order->update_meta_data('_guardify_incomplete', 'yes');
            $order->update_meta_data('_guardify_capture_time', current_time('mysql'));
            $order->update_meta_data('_guardify_capture_trigger', $data['_capture_trigger'] ?? 'page_load');
            $order->update_meta_data('_guardify_capture_url', $data['_capture_url'] ?? '');

            // Store client IP
            $ip = $this->get_client_ip();
            $order->set_customer_ip_address($ip);

            // Store user agent (from WC helper)
            $order->set_customer_user_agent(wc_get_user_agent());

            // ─── BROWSER METADATA ───
            $this->save_browser_metadata($order, $data);

            // ─── UTM / CAMPAIGN PARAMS ───
            $this->save_utm_params($order, $data);

            // ─── CUSTOM CHECKOUT FIELDS ───
            $this->save_custom_fields($order, $data);

            // CartFlows metadata
            if (!empty($data['_wcf_flow_id'])) {
                $order->update_meta_data('_wcf_flow_id', $data['_wcf_flow_id']);
            }
            if (!empty($data['_wcf_checkout_id'])) {
                $order->update_meta_data('_wcf_checkout_id', $data['_wcf_checkout_id']);
            }

            // Add order note
            $source = !empty($data['_wcf_checkout_id']) ? 'CartFlows Checkout' : 'WooCommerce Checkout';
            $trigger_label = $this->get_trigger_label($data['_capture_trigger'] ?? 'page_load');
            $has_form_data = !empty($data['billing_phone']) || !empty($data['billing_email']) || !empty($data['billing_first_name']);
            $note_icon = $has_form_data ? '🔸' : '🌐';
            $note_extra = $has_form_data ? '' : ' (browser data only — no form fields filled yet)';
            $order->add_order_note(
                sprintf(
                    /* translators: 1: icon, 2: source, 3: trigger, 4: extra info */
                    __('%1$s Guardify: Incomplete order captured from %2$s — Trigger: %3$s%4$s', 'guardify'),
                    $note_icon,
                    $source,
                    $trigger_label,
                    $note_extra
                ),
                0,
                true
            );

            $order->save();

            // Set cookie for tracking
            $this->set_draft_cookie($order->get_id());

            // ─── Fire action for Discord / notifications ───
            do_action('guardify_incomplete_order_created', $order->get_id(), $order, $data);

            return $order->get_id();

        } catch (\Exception $e) {
            error_log('Guardify Abandoned Cart Error: ' . $e->getMessage());
            return new \WP_Error('guardify_draft_error', $e->getMessage());
        }
    }

    /**
     * Update existing draft order with latest checkout field data
     */
    private function update_draft_order(int $order_id, array $data): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Only update if still incomplete
        if ($order->get_status() !== self::STATUS_SLUG) {
            return;
        }

        // Track if identifiable data was just added (for Discord notification on first fill)
        $had_phone_before = !empty($order->get_billing_phone());
        $had_email_before = !empty($order->get_billing_email());

        // Update billing fields
        $billing_setters = [
            'billing_first_name' => 'set_billing_first_name',
            'billing_last_name'  => 'set_billing_last_name',
            'billing_company'    => 'set_billing_company',
            'billing_address_1'  => 'set_billing_address_1',
            'billing_address_2'  => 'set_billing_address_2',
            'billing_city'       => 'set_billing_city',
            'billing_state'      => 'set_billing_state',
            'billing_postcode'   => 'set_billing_postcode',
            'billing_country'    => 'set_billing_country',
            'billing_email'      => 'set_billing_email',
            'billing_phone'      => 'set_billing_phone',
        ];

        foreach ($billing_setters as $field => $setter) {
            if (!empty($data[$field])) {
                $order->$setter($data[$field]);
            }
        }

        // Update shipping fields
        $shipping_setters = [
            'shipping_first_name' => 'set_shipping_first_name',
            'shipping_last_name'  => 'set_shipping_last_name',
            'shipping_company'    => 'set_shipping_company',
            'shipping_address_1'  => 'set_shipping_address_1',
            'shipping_address_2'  => 'set_shipping_address_2',
            'shipping_city'       => 'set_shipping_city',
            'shipping_state'      => 'set_shipping_state',
            'shipping_postcode'   => 'set_shipping_postcode',
            'shipping_country'    => 'set_shipping_country',
        ];

        foreach ($shipping_setters as $field => $setter) {
            if (!empty($data[$field])) {
                $order->$setter($data[$field]);
            }
        }

        // Order notes
        if (!empty($data['order_comments'])) {
            $order->set_customer_note($data['order_comments']);
        }

        // Update capture metadata
        $order->update_meta_data('_guardify_last_update', current_time('mysql'));
        $order->update_meta_data('_guardify_capture_trigger', $data['_capture_trigger'] ?? 'update');

        // Update CartFlows meta if present
        if (!empty($data['_wcf_flow_id'])) {
            $order->update_meta_data('_wcf_flow_id', $data['_wcf_flow_id']);
        }
        if (!empty($data['_wcf_checkout_id'])) {
            $order->update_meta_data('_wcf_checkout_id', $data['_wcf_checkout_id']);
        }

        // ─── BROWSER METADATA ───
        $this->save_browser_metadata($order, $data);

        // ─── UTM / CAMPAIGN PARAMS ───
        $this->save_utm_params($order, $data);

        // ─── CUSTOM CHECKOUT FIELDS ───
        $this->save_custom_fields($order, $data);

        // Try to update cart items if cart available and order has no items yet
        if ($order->get_item_count() === 0 && WC()->cart && !WC()->cart->is_empty()) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                $product = $cart_item['data'];
                $quantity = $cart_item['quantity'];
                
                if ($product) {
                    $order->add_product($product, $quantity, [
                        'subtotal' => $cart_item['line_subtotal'] ?? '',
                        'total'    => $cart_item['line_total'] ?? '',
                    ]);
                }
            }
            
            $order->set_shipping_total(WC()->cart->get_shipping_total());
            $order->set_discount_total(WC()->cart->get_discount_total());
            $order->set_cart_tax(WC()->cart->get_cart_contents_tax());
            $order->set_total(WC()->cart->get_total('edit'));
        }

        $order->save();

        // ─── Fire action when identifiable data is first added (phone or email) ───
        $has_phone_now = !empty($data['billing_phone']);
        $has_email_now = !empty($data['billing_email']);
        if ((!$had_phone_before && $has_phone_now) || (!$had_email_before && $has_email_now)) {
            do_action('guardify_incomplete_order_identified', $order_id, $order, $data);
        }

        // Always fire update action
        do_action('guardify_incomplete_order_updated', $order_id, $order, $data);
    }

    // =========================================================================
    // CLEANUP
    // =========================================================================

    /**
     * When a real order is placed, trash the corresponding draft order
     */
    public function cleanup_draft_on_order($order): void {
        $draft_id = $this->get_draft_order_id();
        
        if (!$draft_id) {
            return;
        }

        $draft_order = wc_get_order($draft_id);
        if (!$draft_order) {
            return;
        }

        // Only delete if it's still an incomplete order
        if ($draft_order->get_status() === self::STATUS_SLUG) {
            // Check if the new real order has the same phone/email
            $new_order = is_object($order) ? $order : wc_get_order($order);
            if ($new_order) {
                $same_phone = $draft_order->get_billing_phone() === $new_order->get_billing_phone();
                $same_email = $draft_order->get_billing_email() === $new_order->get_billing_email();
                
                if ($same_phone || $same_email) {
                    $draft_order->add_order_note(
                        sprintf(
                            __('🔹 Guardify: Customer completed checkout. Real order #%s created. This incomplete order is now trashed.', 'guardify'),
                            $new_order->get_id()
                        )
                    );
                    $draft_order->delete(false); // move to trash, not permanent delete
                }
            }
        }

        $this->clear_draft_cookie();
    }

    /**
     * Clear draft cookie on thank you page
     */
    public function cleanup_draft_cookie($order_id): void {
        $this->clear_draft_cookie();
    }

    /**
     * Daily cleanup: Delete incomplete orders older than configured days
     */
    public function cleanup_old_incomplete_orders(): void {
        $retention_days = (int) get_option('guardify_abandoned_cart_retention_days', 30);
        
        if ($retention_days < 1) {
            return; // 0 = keep forever
        }

        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$retention_days} days"));

        $orders = wc_get_orders([
            'status'       => self::STATUS_SLUG,
            'date_created' => '<' . $cutoff,
            'limit'        => 50, // Process in batches
        ]);

        foreach ($orders as $order) {
            $order->add_order_note(
                sprintf(
                    __('🗑️ Guardify: Auto-deleted incomplete order after %d days.', 'guardify'),
                    $retention_days
                )
            );
            $order->delete(true); // permanent delete for old ones
        }
    }

    // =========================================================================
    // COOKIE MANAGEMENT
    // =========================================================================

    private function get_draft_order_id(): int {
        if (isset($_COOKIE[self::COOKIE_NAME])) {
            return absint($_COOKIE[self::COOKIE_NAME]);
        }
        return 0;
    }

    private function set_draft_cookie(int $order_id): void {
        // Set cookie for 2 hours (matches draft dedup window)
        if (!headers_sent()) {
            setcookie(
                self::COOKIE_NAME,
                (string) $order_id,
                time() + (2 * HOUR_IN_SECONDS),
                COOKIEPATH,
                COOKIE_DOMAIN,
                is_ssl(),
                true // httpOnly — JS reads order ID from localized data, not cookie
            );
        }
        // Also set in $_COOKIE for the current request
        $_COOKIE[self::COOKIE_NAME] = (string) $order_id;
    }

    private function clear_draft_cookie(): void {
        if (!headers_sent()) {
            setcookie(self::COOKIE_NAME, '', time() - HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        }
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    // =========================================================================
    // ADMIN STYLING
    // =========================================================================

    /**
     * Add CSS for the "Incomplete" status badge in WooCommerce admin orders list
     */
    public function admin_status_styles(): void {
        $screen = get_current_screen();
        if (!$screen) return;
        
        // WooCommerce orders page (HPOS or legacy)
        $is_orders_page = in_array($screen->id, [
            'woocommerce_page_wc-orders',
            'edit-shop_order',
        ], true);

        if (!$is_orders_page) return;

        ?>
        <style>
            /* Guardify Incomplete Order Status Badge */
            .order-status.status-incomplete,
            mark.order-status.status-incomplete {
                background: #f8d7da !important;
                color: #721c24 !important;
                border-radius: 3px;
                padding: 2px 8px;
                font-weight: 600;
                border: none;
            }
            mark.order-status.status-incomplete::after {
                content: '';
                display: none;
            }
            /* HPOS table status */
            .wc-orders-list-table .order-status.status-incomplete {
                background: #f8d7da !important;
                color: #721c24 !important;
            }
            /* Incomplete order row highlight */
            tr.status-incomplete {
                background-color: #fff5f5 !important;
            }
            tr.status-incomplete:hover {
                background-color: #fee !important;
            }
            /* Status filter count */
            .subsubsub .incomplete-orders .count {
                color: #dc3545;
            }
        </style>
        <?php
    }

    /**
     * Add bulk actions for incomplete orders
     */
    public function add_bulk_actions(array $actions): array {
        $actions['guardify_delete_incomplete'] = __('Delete Incomplete Orders', 'guardify');
        return $actions;
    }

    // =========================================================================
    // LITESPEED CACHE COMPATIBILITY
    // =========================================================================

    /**
     * Ensure our AJAX actions are marked no-cache
     * (admin-ajax.php is already not cached by default, but this is defense-in-depth)
     */
    public function maybe_nocache_our_ajax(): void {
        if (wp_doing_ajax()) {
            $action = isset($_REQUEST['action']) ? sanitize_text_field($_REQUEST['action']) : '';
            if ($action === 'guardify_capture_checkout') {
                do_action('litespeed_control_set_nocache', 'guardify abandoned cart capture');
            }
        }
    }

    // =========================================================================
    // METADATA SAVE HELPERS
    // =========================================================================

    /**
     * Save browser metadata to order meta
     */
    private function save_browser_metadata(\WC_Order $order, array $data): void {
        $browser_fields = [
            'browser_ua', 'browser_language', 'browser_platform',
            'screen_width', 'screen_height', 'viewport_width', 'viewport_height',
            'referrer', 'timezone', 'page_url', 'page_title',
            'connection_type', 'device_memory', 'hardware_concurrency',
            'touch_support', 'cookie_enabled', 'do_not_track',
            'color_depth', 'pixel_ratio',
        ];

        foreach ($browser_fields as $bf) {
            $key = '_browser_' . $bf;
            if (!empty($data[$key])) {
                $order->update_meta_data('_guardify' . $key, $data[$key]);
            }
        }
    }

    /**
     * Save UTM / campaign parameters to order meta
     */
    private function save_utm_params(\WC_Order $order, array $data): void {
        $utm_fields = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid'];
        foreach ($utm_fields as $uf) {
            $key = '_param_' . $uf;
            if (!empty($data[$key])) {
                $order->update_meta_data('_guardify' . $key, $data[$key]);
            }
        }
    }

    /**
     * Save custom / dynamic checkout fields (size, color, etc.) to order meta
     */
    private function save_custom_fields(\WC_Order $order, array $data): void {
        if (!empty($data['_custom_fields']) && is_array($data['_custom_fields'])) {
            // Store as a serialized array for easy retrieval
            $order->update_meta_data('_guardify_custom_fields', $data['_custom_fields']);

            // Also store each field individually for WooCommerce admin display
            foreach ($data['_custom_fields'] as $field_key => $field_value) {
                $order->update_meta_data('_guardify_cf_' . $field_key, $field_value);
            }
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Get client IP (same logic as cooldown class)
     */
    private function get_client_ip(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',    // Cloudflare
            'HTTP_X_REAL_IP',           // Nginx / OpenLiteSpeed
            'HTTP_X_FORWARDED_FOR',     // General proxy
            'REMOTE_ADDR',              // Direct connection
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // X-Forwarded-For may contain multiple IPs
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Get human-readable trigger label
     */
    private function get_trigger_label(string $trigger): string {
        return match ($trigger) {
            'page_load'         => __('পেইজ লোড (Page Load)', 'guardify'),
            'field_blur'        => __('ফিল্ড ফিলআপ (Field Blur)', 'guardify'),
            'page_hide'         => __('ব্রাউজার ট্যাব সুইচ (Visibility Hidden)', 'guardify'),
            'before_unload'     => __('ব্রাউজার বন্ধ/নেভিগেট (Before Unload)', 'guardify'),
            'periodic'          => __('পিরিয়ডিক সেভ (Auto-save)', 'guardify'),
            'phone_complete'    => __('ফোন নাম্বার এন্ট্রি (Phone Complete)', 'guardify'),
            default             => $trigger,
        };
    }
}
