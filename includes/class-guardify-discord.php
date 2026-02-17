<?php
/**
 * Guardify Discord Webhook Notifications
 * 
 * Sends rich Discord embed messages for all order lifecycle events:
 *  1. New incomplete order created (browser-only or with form data)
 *  2. Incomplete order gets identifiable data (phone/email filled)
 *  3. Real WooCommerce order placed
 *  4. Order status changes (processing, completed, on-hold, cancelled, refunded, failed)
 *  5. Fraud blocks (auto-failed high-score orders)
 *
 * All information is consolidated into a SINGLE Discord message per event:
 *  - Customer info (name, phone, email, address)
 *  - Cart items with variation details (size, color, etc.)
 *  - Order totals, coupons, payment method, date
 *  - Browser metadata (device, screen, language, referrer)
 *  - UTM / campaign parameters
 *  - Fraud report (score, risk, signals, courier stats)
 *  - Repeat customer detection and order history
 *
 * @package Guardify
 * @since 1.2.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class Guardify_Discord {
    private static ?self $instance = null;

    /** Discord embed field character limit */
    private const FIELD_VALUE_LIMIT = 1024;

    /** Discord embed description character limit */
    private const DESCRIPTION_LIMIT = 4096;

    /** Max retries for failed webhook calls */
    private const MAX_RETRIES = 2;

    /** Status colors for embeds */
    private const STATUS_COLORS = [
        'incomplete_created'    => 0x6C757D, // grey
        'incomplete_with_data'  => 0xDC3545, // red
        'incomplete_identified' => 0xFD7E14, // orange
        'new_order'             => 0x28A745, // green
        'processing'            => 0x007BFF, // blue
        'on-hold'               => 0xFFC107, // yellow
        'completed'             => 0x17A2B8, // teal
        'cancelled'             => 0x6C757D, // grey
        'refunded'              => 0x6F42C1, // purple
        'failed'                => 0xDC3545, // red
        'fraud_block'           => 0xFF0000, // bright red
    ];

    /** Status emojis */
    private const STATUS_EMOJIS = [
        'incomplete_created'    => '🌐',
        'incomplete_with_data'  => '🔴',
        'incomplete_identified' => '🟠',
        'new_order'             => '🟢',
        'processing'            => '🔵',
        'on-hold'               => '🟡',
        'completed'             => '✅',
        'cancelled'             => '⚫',
        'refunded'              => '🟣',
        'failed'                => '❌',
        'fraud_block'           => '🚨',
    ];

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Always register test webhook handler (so admin can test even if disabled)
        add_action('wp_ajax_guardify_test_discord', [$this, 'ajax_test_webhook']);

        if (!$this->is_enabled()) {
            return;
        }

        // ─── Incomplete order events (from Guardify_Abandoned_Cart) ───
        add_action('guardify_incomplete_order_created', [$this, 'on_incomplete_created'], 20, 3);
        add_action('guardify_incomplete_order_identified', [$this, 'on_incomplete_identified'], 20, 3);

        // ─── Real order placed ───
        add_action('woocommerce_checkout_order_created', [$this, 'on_order_created'], 20, 1);
        // Fallback hook for orders created by other means (REST API, admin, etc.)
        add_action('woocommerce_new_order', [$this, 'on_new_order_fallback'], 25, 2);

        // ─── Order status changes ───
        add_action('woocommerce_order_status_changed', [$this, 'on_status_changed'], 20, 4);

        // ─── Scheduled retry for failed webhooks ───
        add_action('guardify_discord_retry_webhook', [$this, 'retry_failed_webhook'], 10, 3);
    }

    // =========================================================================
    // FEATURE TOGGLE
    // =========================================================================

    private function is_enabled(): bool {
        return get_option('guardify_discord_enabled', '0') === '1' && !empty($this->get_webhook_url());
    }

    private function get_webhook_url(): string {
        return get_option('guardify_discord_webhook_url', '');
    }

    /**
     * Get the webhook URL for a specific event type.
     * Falls back to the primary webhook URL if no per-event URL exists.
     */
    private function get_webhook_url_for_event(string $event_type): string {
        $per_event = get_option('guardify_discord_webhook_urls', []);
        if (is_array($per_event) && !empty($per_event[$event_type])) {
            return $per_event[$event_type];
        }
        return $this->get_webhook_url();
    }

    private function should_notify(string $event_type): bool {
        $events = get_option('guardify_discord_events', [
            'incomplete', 'identified', 'new_order', 
            'processing', 'completed', 'cancelled', 'on-hold', 'refunded', 'failed',
            'fraud_block',
        ]);
        if (!is_array($events)) {
            $events = ['incomplete', 'identified', 'new_order', 'processing', 'completed', 'cancelled'];
        }
        return in_array($event_type, $events, true);
    }

    // =========================================================================
    // PRICE FORMATTING HELPER
    // =========================================================================

    /**
     * Format a price for plain-text Discord display.
     * Converts WooCommerce HTML price to clean text with "Taka" instead of currency symbol.
     */
    private function format_price($price): string {
        $formatted = strip_tags(wc_price($price));
        // Decode HTML entities (&#2547; -> taka symbol, &nbsp; -> space)
        $formatted = html_entity_decode($formatted, ENT_QUOTES, 'UTF-8');
        // Replace taka symbol with "Taka"
        $formatted = str_replace("\u{09F3}", 'Taka', $formatted);
        // Clean up non-breaking spaces and extra whitespace
        $formatted = str_replace("\xC2\xA0", ' ', $formatted);
        $formatted = preg_replace('/\s+/', ' ', $formatted);
        return trim($formatted);
    }

    // =========================================================================
    // AJAX: TEST WEBHOOK
    // =========================================================================

    public function ajax_test_webhook(): void {
        check_ajax_referer('guardify_test_discord', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }

        $url = get_option('guardify_discord_webhook_url', '');
        if (empty($url)) {
            wp_send_json_error(['message' => 'Webhook URL সেট করা হয়নি। প্রথমে URL দিন এবং সেভ করুন।']);
            return;
        }

        $site_name = get_bloginfo('name');
        $total_orders = 0;
        $today_orders = 0;

        if (function_exists('wc_get_orders')) {
            $total_orders = count(wc_get_orders(['limit' => -1, 'return' => 'ids', 'status' => ['processing', 'completed', 'on-hold']]));
            $today_orders = count(wc_get_orders([
                'limit' => -1, 'return' => 'ids',
                'date_created' => '>=' . date('Y-m-d', current_time('timestamp')),
                'status' => ['processing', 'completed', 'on-hold'],
            ]));
        }

        $payload = [
            'username'   => get_option('guardify_discord_bot_name', 'Guardify'),
            'embeds'     => [[
                'title'       => '🧪 Guardify Test Message',
                'description' => "This is a test notification from **{$site_name}**.\n\nIf you see this message, your Discord webhook is working correctly! ✅",
                'color'       => 0x6366F1,
                'timestamp'   => gmdate('c'),
                'fields'      => [
                    ['name' => '🌐 Site', 'value' => home_url(), 'inline' => true],
                    ['name' => '🔌 Plugin', 'value' => 'Guardify v' . GUARDIFY_VERSION, 'inline' => true],
                    ['name' => '👤 Tested by', 'value' => wp_get_current_user()->display_name, 'inline' => true],
                    ['name' => '📊 Today\'s Orders', 'value' => (string) $today_orders, 'inline' => true],
                    ['name' => '📦 Total Active Orders', 'value' => (string) $total_orders, 'inline' => true],
                    ['name' => '⏰ Server Time', 'value' => current_time('Y-m-d H:i:s'), 'inline' => true],
                ],
                'footer' => [
                    'text' => 'Guardify Discord Integration — Test',
                ],
                'thumbnail' => [
                    'url' => 'https://tansiqlabs.com/guardify-icon.png',
                ],
            ]],
        ];

        $response = wp_remote_post($url, [
            'timeout'   => 15,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => wp_json_encode($payload),
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Error: ' . $response->get_error_message()]);
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            wp_send_json_success(['message' => 'টেস্ট মেসেজ সফলভাবে পাঠানো হয়েছে! ✅']);
        } elseif ($code === 429) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $retry_after = $body['retry_after'] ?? 'unknown';
            wp_send_json_error(['message' => "Discord Rate Limited! Retry after {$retry_after}ms."]);
        } else {
            $body = wp_remote_retrieve_body($response);
            wp_send_json_error(['message' => "Discord Error (HTTP {$code}): " . mb_substr($body, 0, 200)]);
        }
    }

    // =========================================================================
    // EVENT HANDLERS
    // =========================================================================

    /**
     * New incomplete order created (may have only browser data)
     */
    public function on_incomplete_created(int $order_id, \WC_Order $order, array $data): void {
        if (!$this->should_notify('incomplete')) {
            return;
        }

        $has_form = !empty($order->get_billing_phone()) || !empty($order->get_billing_email());
        $event = $has_form ? 'incomplete_with_data' : 'incomplete_created';
        
        $this->send_notification($order, [
            'event'       => $event,
            'title'       => self::STATUS_EMOJIS[$event] . ($has_form 
                ? ' Incomplete Order #' . $order_id 
                : ' New Visitor — Incomplete #' . $order_id),
            'description' => $has_form 
                ? 'Customer started checkout but did not complete the order.'
                : 'Visitor landed on checkout page. No form data yet — browser metadata captured.',
            'color'       => self::STATUS_COLORS[$event],
            'capture_data' => $data,
        ]);
    }

    /**
     * Incomplete order now has identifiable data (phone or email just filled)
     */
    public function on_incomplete_identified(int $order_id, \WC_Order $order, array $data): void {
        if (!$this->should_notify('identified')) {
            return;
        }

        $this->send_notification($order, [
            'event'       => 'incomplete_identified',
            'title'       => '🟠 Customer Identified — Incomplete #' . $order_id,
            'description' => 'Customer filled phone/email but has not placed the order yet.',
            'color'       => self::STATUS_COLORS['incomplete_identified'],
            'capture_data' => $data,
            'include_fraud' => true,
        ]);
    }

    /**
     * Real WooCommerce order placed via checkout
     */
    public function on_order_created($order): void {
        if (!$this->should_notify('new_order')) {
            return;
        }

        if (!($order instanceof \WC_Order)) {
            $order = wc_get_order($order);
        }
        if (!$order) return;

        // Skip incomplete orders (they have their own handler)
        if ($order->get_status() === 'incomplete') {
            return;
        }

        // Mark that we already sent the new_order notification
        $order->update_meta_data('_guardify_discord_new_order_sent', '1');
        $order->save();

        $this->send_notification($order, [
            'event'       => 'new_order',
            'title'       => '🟢 New Order #' . $order->get_id(),
            'description' => $this->build_order_summary_description($order),
            'color'       => self::STATUS_COLORS['new_order'],
            'include_fraud'  => true,
            'include_repeat' => true,
        ]);
    }

    /**
     * Fallback for orders created via REST API, admin, or other means
     */
    public function on_new_order_fallback(int $order_id, \WC_Order $order): void {
        // Skip if already notified via checkout hook
        if ($order->get_meta('_guardify_discord_new_order_sent') === '1') {
            return;
        }
        if ($order->get_status() === 'incomplete') {
            return;
        }
        if (!$this->should_notify('new_order')) {
            return;
        }

        $this->send_notification($order, [
            'event'       => 'new_order',
            'title'       => '🟢 New Order #' . $order_id . ' (Admin/API)',
            'description' => $this->build_order_summary_description($order),
            'color'       => self::STATUS_COLORS['new_order'],
            'include_fraud'  => true,
            'include_repeat' => true,
        ]);
    }

    /**
     * Order status changed — notify for all tracked statuses
     */
    public function on_status_changed(int $order_id, string $old_status, string $new_status, \WC_Order $order): void {
        // Fraud block check (special case)
        if ($new_status === 'failed') {
            $score = $order->get_meta('_guardify_fraud_score');
            if ($score !== '' && (int) $score >= 50 && $this->should_notify('fraud_block')) {
                $this->send_notification($order, [
                    'event'       => 'fraud_block',
                    'title'       => '🚨 Fraud Block — Order #' . $order_id,
                    'description' => sprintf(
                        "Order auto-failed due to **high fraud score**.\n\n**Score:** %d/100\n**Risk:** %s\n**Previous Status:** %s",
                        (int) $score,
                        ucfirst($order->get_meta('_guardify_fraud_risk') ?: 'unknown'),
                        ucfirst($old_status)
                    ),
                    'color'       => self::STATUS_COLORS['fraud_block'],
                    'include_fraud' => true,
                ]);
                return; // Don't also send a regular 'failed' notification
            }
        }

        // Map WC statuses to our event keys
        $status_event_map = [
            'processing' => 'processing',
            'completed'  => 'completed',
            'on-hold'    => 'on-hold',
            'cancelled'  => 'cancelled',
            'refunded'   => 'refunded',
            'failed'     => 'failed',
        ];

        if (!isset($status_event_map[$new_status])) {
            return;
        }

        $event_key = $status_event_map[$new_status];
        if (!$this->should_notify($event_key)) {
            return;
        }

        // ─── DEDUPLICATION: Skip processing if new_order was already sent ───
        // When a new order is placed, on_order_created already sends a full notification.
        // The immediate transition to processing should NOT send a second message.
        if ($new_status === 'processing' && in_array($old_status, ['checkout-draft', 'pending', 'incomplete'])) {
            if ($order->get_meta('_guardify_discord_new_order_sent') === '1') {
                return; // Already sent full details in new_order notification — no duplicate
            }
        }

        $emoji = self::STATUS_EMOJIS[$event_key] ?? '📋';
        $descriptions = [
            'processing' => "Order is now being **processed**.\nPayment confirmed — ready for fulfillment.",
            'completed'  => "Order has been **completed** and marked as fulfilled. ✅",
            'on-hold'    => "Order is **on hold** — awaiting payment or manual review.",
            'cancelled'  => "Order has been **cancelled**.",
            'refunded'   => "Order has been **refunded**.",
            'failed'     => "Order payment **failed**.",
        ];

        $desc = $descriptions[$new_status] ?? "Status changed to **{$new_status}**.";
        $desc .= "\n\n**Previous:** " . ucfirst($old_status) . " → **Current:** " . ucfirst($new_status);

        // Add who changed the status (if in admin)
        $changed_by = $this->get_status_changed_by();
        if ($changed_by) {
            $desc .= "\n**Changed by:** {$changed_by}";
        }

        // Always include full order details in EVERY notification
        $this->send_notification($order, [
            'event'          => $event_key,
            'title'          => "{$emoji} Order #{$order_id} — " . ucfirst($new_status),
            'description'    => $desc,
            'color'          => self::STATUS_COLORS[$event_key] ?? 0x6C757D,
            'include_fraud'  => ($new_status === 'failed'),
            'include_repeat' => true,
        ]);
    }

    // =========================================================================
    // NOTIFICATION BUILDER — SINGLE MESSAGE
    // =========================================================================

    /**
     * Build and send a single consolidated Discord webhook notification.
     * All information (customer, cart, browser, fraud, repeat) goes into ONE message.
     */
    private function send_notification(\WC_Order $order, array $context): void {
        $fields = [];

        // ─── CUSTOMER INFO ───
        $this->add_customer_fields($fields, $order);

        // ─── CART ITEMS & TOTALS ───
        $this->add_cart_fields($fields, $order);

        // ─── COUPON INFO ───
        $this->add_coupon_fields($fields, $order);

        // ─── PAYMENT & DATE ───
        $this->add_payment_fields($fields, $order);

        // ─── CUSTOM CHECKOUT FIELDS (size, color, etc.) ───
        $this->add_custom_fields($fields, $order);

        // ─── SEPARATOR ───
        if (!empty($fields)) {
            $fields[] = ['name' => "\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}", 'value' => '** **', 'inline' => false];
        }

        // ─── BROWSER & DEVICE INFO ───
        $this->add_browser_fields($fields, $order, $context['capture_data'] ?? []);

        // ─── UTM / CAMPAIGN ───
        $this->add_utm_fields($fields, $order);

        // ─── REPEAT CUSTOMER ───
        if (!empty($context['include_repeat'])) {
            $this->add_repeat_customer_fields($fields, $order);
        }

        // ─── FRAUD REPORT ───
        if (!empty($context['include_fraud'])) {
            $this->add_fraud_fields($fields, $order);
        }

        // Trim to 25 fields max (Discord limit per embed)
        $fields = array_slice($fields, 0, 25);

        // ─── BUILD SINGLE EMBED ───
        $embed = [
            'title'       => $context['title'],
            'description' => mb_substr($context['description'], 0, self::DESCRIPTION_LIMIT),
            'color'       => $context['color'],
            'timestamp'   => gmdate('c'),
            'fields'      => $fields,
            'url'         => admin_url('admin.php?page=wc-orders&action=edit&id=' . $order->get_id()),
            'footer'      => [
                'text' => 'Guardify v' . (defined('GUARDIFY_VERSION') ? GUARDIFY_VERSION : '1.2.2') . ' • ' . wp_parse_url(home_url(), PHP_URL_HOST),
            ],
        ];

        // ─── SEND SINGLE MESSAGE ───
        $this->send_webhook([
            'embeds' => [$embed],
        ], 1, $context['event'] ?? '');
    }

    /**
     * Build a one-line order summary for the description
     */
    private function build_order_summary_description(\WC_Order $order): string {
        $parts = [];

        $payment = $order->get_payment_method_title();
        if ($payment) {
            $parts[] = "**Payment:** {$payment}";
        }

        $items_count = count($order->get_items());
        $parts[] = "**Items:** {$items_count}";

        $total = $this->format_price($order->get_total());
        $parts[] = "**Total:** {$total}";

        // Coupons used
        $coupons = $order->get_coupon_codes();
        if (!empty($coupons)) {
            $parts[] = "**Coupon:** " . implode(', ', $coupons);
        }

        return implode(' • ', $parts);
    }

    /**
     * Get who changed the order status (admin user in current session)
     */
    private function get_status_changed_by(): string {
        if (!is_admin() && !wp_doing_ajax()) {
            return 'Customer / System';
        }

        $current_user = wp_get_current_user();
        if ($current_user && $current_user->ID > 0) {
            return $current_user->display_name . ' (' . $current_user->user_email . ')';
        }

        return '';
    }

    // =========================================================================
    // FIELD BUILDERS (all add to the same fields array)
    // =========================================================================

    /**
     * Add customer info fields to embed
     */
    private function add_customer_fields(array &$fields, \WC_Order $order): void {
        $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        if ($name) {
            $fields[] = ['name' => '👤 Name', 'value' => $name, 'inline' => true];
        }

        $phone = $order->get_billing_phone();
        if ($phone) {
            $fields[] = ['name' => '📱 Phone', 'value' => $phone, 'inline' => true];
        }

        $email = $order->get_billing_email();
        if ($email) {
            $fields[] = ['name' => '📧 Email', 'value' => $email, 'inline' => true];
        }

        // Address
        $address_parts = array_filter([
            $order->get_billing_address_1(),
            $order->get_billing_address_2(),
            $order->get_billing_city(),
            $order->get_billing_state(),
            $order->get_billing_postcode(),
        ]);
        if ($address_parts) {
            $fields[] = ['name' => '📍 Address', 'value' => implode(', ', $address_parts), 'inline' => false];
        }

        // Shipping (if different from billing)
        $ship_name = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
        $ship_addr = array_filter([
            $order->get_shipping_address_1(),
            $order->get_shipping_address_2(),
            $order->get_shipping_city(),
            $order->get_shipping_state(),
        ]);
        if ($ship_name || $ship_addr) {
            $shipping_text = $ship_name ? $ship_name . "\n" : '';
            $shipping_text .= implode(', ', $ship_addr);
            if (trim($shipping_text)) {
                $fields[] = ['name' => '📦 Shipping To', 'value' => $shipping_text, 'inline' => false];
            }
        }

        // IP address
        $ip = $order->get_customer_ip_address();
        if ($ip && $ip !== '0.0.0.0') {
            $fields[] = ['name' => '🌐 IP', 'value' => '`' . $ip . '`', 'inline' => true];
        }

        // Customer note
        $note = $order->get_customer_note();
        if ($note) {
            $fields[] = ['name' => '📝 Customer Note', 'value' => mb_substr($note, 0, 200), 'inline' => false];
        }

        // Capture trigger
        $trigger = $order->get_meta('_guardify_capture_trigger');
        if ($trigger) {
            $fields[] = ['name' => '⚡ Trigger', 'value' => $trigger, 'inline' => true];
        }

        // CartFlows source
        $wcf_flow = $order->get_meta('_wcf_flow_id');
        if ($wcf_flow) {
            $fields[] = ['name' => '🔄 CartFlows', 'value' => 'Flow #' . $wcf_flow, 'inline' => true];
        }
    }

    /**
     * Add cart items to embed
     */
    private function add_cart_fields(array &$fields, \WC_Order $order): void {
        $items = $order->get_items();
        if (empty($items)) {
            return;
        }

        $lines = [];
        $item_count = 0;
        foreach ($items as $item) {
            $qty = $item->get_quantity();
            $name = $item->get_name();
            $total = $this->format_price($item->get_total());

            // Include variation details if available
            $variation_info = '';
            if ($item instanceof \WC_Order_Item_Product) {
                $meta_data = $item->get_formatted_meta_data('');
                if (!empty($meta_data)) {
                    $meta_parts = [];
                    foreach (array_slice($meta_data, 0, 3) as $meta) {
                        $meta_parts[] = wp_strip_all_tags($meta->display_key) . ': ' . wp_strip_all_tags($meta->display_value);
                    }
                    if ($meta_parts) {
                        $variation_info = ' (' . implode(', ', $meta_parts) . ')';
                    }
                }
            }

            $lines[] = "• **{$name}**{$variation_info} × {$qty} — {$total}";
            $item_count++;

            if ($item_count >= 10) {
                $remaining = count($items) - $item_count;
                if ($remaining > 0) {
                    $lines[] = "... +{$remaining} more items";
                }
                break;
            }
        }

        $cart_text = implode("\n", $lines);
        if (mb_strlen($cart_text) > self::FIELD_VALUE_LIMIT) {
            $cart_text = mb_substr($cart_text, 0, self::FIELD_VALUE_LIMIT - 20) . "\n... (truncated)";
        }

        $fields[] = ['name' => '🛒 Cart Items (' . count($items) . ')', 'value' => $cart_text, 'inline' => false];

        // Subtotal, Shipping, Tax, Total
        $subtotal = $order->get_subtotal();
        $shipping_total = (float) $order->get_shipping_total();
        $tax_total = (float) $order->get_total_tax();
        $discount = (float) $order->get_total_discount();
        $total = $order->get_total();

        $total_lines = [];
        if ($subtotal > 0) {
            $total_lines[] = '**Subtotal:** ' . $this->format_price($subtotal);
        }
        if ($shipping_total > 0) {
            $shipping_method = '';
            $shipping_items = $order->get_shipping_methods();
            foreach ($shipping_items as $s) {
                $shipping_method = $s->get_method_title();
                break;
            }
            $total_lines[] = '**Shipping:** ' . $this->format_price($shipping_total) . ($shipping_method ? " ({$shipping_method})" : '');
        }
        if ($discount > 0) {
            $total_lines[] = '**Discount:** -' . $this->format_price($discount);
        }
        if ($tax_total > 0) {
            $total_lines[] = '**Tax:** ' . $this->format_price($tax_total);
        }
        $total_lines[] = '💰 **Total:** ' . $this->format_price($total);

        $fields[] = ['name' => '💳 Order Totals', 'value' => implode("\n", $total_lines), 'inline' => false];
    }

    /**
     * Add coupon/discount info
     */
    private function add_coupon_fields(array &$fields, \WC_Order $order): void {
        $coupons = $order->get_coupon_codes();
        if (empty($coupons)) {
            return;
        }

        $lines = [];
        foreach ($order->get_items('coupon') as $coupon_item) {
            $code = $coupon_item->get_code();
            $discount = $this->format_price($coupon_item->get_discount());
            $lines[] = "🏷️ `{$code}` — -{$discount}";
        }

        if ($lines) {
            $fields[] = ['name' => '🎟️ Coupons Used', 'value' => implode("\n", $lines), 'inline' => false];
        }
    }

    /**
     * Add payment method details
     */
    private function add_payment_fields(array &$fields, \WC_Order $order): void {
        $method = $order->get_payment_method_title();
        if ($method) {
            $payment_text = '💳 ' . $method;
            
            // Transaction ID if available
            $txn_id = $order->get_transaction_id();
            if ($txn_id) {
                $payment_text .= "\nTxn: `{$txn_id}`";
            }

            $fields[] = ['name' => '💳 Payment', 'value' => $payment_text, 'inline' => true];
        }

        // Order date
        $date_created = $order->get_date_created();
        if ($date_created) {
            $fields[] = ['name' => '📅 Order Date', 'value' => $date_created->date('Y-m-d H:i:s'), 'inline' => true];
        }
    }

    /**
     * Add custom checkout fields (size, color, etc.)
     */
    private function add_custom_fields(array &$fields, \WC_Order $order): void {
        $custom = $order->get_meta('_guardify_custom_fields');
        if (empty($custom) || !is_array($custom)) {
            return;
        }

        $lines = [];
        foreach ($custom as $key => $value) {
            $label = ucwords(str_replace(['_', '-'], ' ', $key));
            $lines[] = "**{$label}:** {$value}";
        }

        if ($lines) {
            $text = implode("\n", $lines);
            if (mb_strlen($text) > self::FIELD_VALUE_LIMIT) {
                $text = mb_substr($text, 0, self::FIELD_VALUE_LIMIT - 20) . "\n... (truncated)";
            }
            $fields[] = ['name' => '📋 Custom Fields', 'value' => $text, 'inline' => false];
        }
    }

    /**
     * Add browser/device info fields directly into the main embed
     */
    private function add_browser_fields(array &$fields, \WC_Order $order, array $capture_data = []): void {
        $ua       = $order->get_meta('_guardify_browser_browser_ua') ?: ($capture_data['_browser_browser_ua'] ?? '');
        $language = $order->get_meta('_guardify_browser_browser_language') ?: ($capture_data['_browser_browser_language'] ?? '');
        $platform = $order->get_meta('_guardify_browser_browser_platform') ?: ($capture_data['_browser_browser_platform'] ?? '');
        $screen_w = $order->get_meta('_guardify_browser_screen_width') ?: ($capture_data['_browser_screen_width'] ?? '');
        $screen_h = $order->get_meta('_guardify_browser_screen_height') ?: ($capture_data['_browser_screen_height'] ?? '');
        $referrer = $order->get_meta('_guardify_browser_referrer') ?: ($capture_data['_browser_referrer'] ?? '');
        $timezone = $order->get_meta('_guardify_browser_timezone') ?: ($capture_data['_browser_timezone'] ?? '');
        $touch    = $order->get_meta('_guardify_browser_touch_support') ?: ($capture_data['_browser_touch_support'] ?? '');
        $conn     = $order->get_meta('_guardify_browser_connection_type') ?: ($capture_data['_browser_connection_type'] ?? '');

        if (!$ua && !$platform && !$screen_w && !$referrer) {
            $wc_ua = $order->get_customer_user_agent();
            if ($wc_ua) {
                $ua = $wc_ua;
            } else {
                return;
            }
        }

        // Build a compact browser/device info block
        $browser_lines = [];

        $device = $this->parse_device_string($ua);
        if ($device) {
            $browser_lines[] = "📱 **Device:** {$device}";
        }
        if ($platform) {
            $browser_lines[] = "💻 **Platform:** {$platform}";
        }
        if ($screen_w && $screen_h) {
            $browser_lines[] = "🖥️ **Screen:** {$screen_w} × {$screen_h}";
        }
        $device_type = ($touch === '1') ? '📱 Mobile/Tablet' : '🖥️ Desktop';
        $browser_lines[] = "🔌 **Type:** {$device_type}";
        if ($language) {
            $browser_lines[] = "🌍 **Language:** {$language}";
        }
        if ($timezone) {
            $browser_lines[] = "🕐 **Timezone:** {$timezone}";
        }
        if ($conn) {
            $browser_lines[] = "📶 **Connection:** {$conn}";
        }
        if ($referrer) {
            $ref_domain = wp_parse_url($referrer, PHP_URL_HOST) ?: $referrer;
            $browser_lines[] = "🔗 **Referrer:** [{$ref_domain}]({$referrer})";
        }

        if (!empty($browser_lines)) {
            $text = implode("\n", $browser_lines);
            if (mb_strlen($text) > self::FIELD_VALUE_LIMIT) {
                $text = mb_substr($text, 0, self::FIELD_VALUE_LIMIT - 20) . "\n...";
            }
            $fields[] = ['name' => '🖥️ Browser & Device Info', 'value' => $text, 'inline' => false];
        }
    }

    /**
     * Add UTM / campaign parameter fields directly into the main embed
     */
    private function add_utm_fields(array &$fields, \WC_Order $order): void {
        $utm_keys = [
            'utm_source'   => 'Source',
            'utm_medium'   => 'Medium',
            'utm_campaign' => 'Campaign',
            'utm_term'     => 'Term',
            'utm_content'  => 'Content',
            'fbclid'       => 'Facebook Click',
            'gclid'        => 'Google Click',
            'ttclid'       => 'TikTok Click',
        ];

        $utm_lines = [];
        foreach ($utm_keys as $key => $label) {
            $value = $order->get_meta('_guardify_param_' . $key);
            if ($value) {
                $utm_lines[] = "📊 **{$label}:** {$value}";
            }
        }

        // Landing page
        $landing = $order->get_meta('_guardify_param_landing_page');
        if ($landing) {
            $utm_lines[] = "🔗 **Landing Page:** {$landing}";
        }

        if (!empty($utm_lines)) {
            $text = implode("\n", $utm_lines);
            if (mb_strlen($text) > self::FIELD_VALUE_LIMIT) {
                $text = mb_substr($text, 0, self::FIELD_VALUE_LIMIT - 20) . "\n...";
            }
            $fields[] = ['name' => '📈 Campaign / UTM Data', 'value' => $text, 'inline' => false];
        }
    }

    /**
     * Add repeat customer info fields directly into the main embed
     */
    private function add_repeat_customer_fields(array &$fields, \WC_Order $order): void {
        $phone = $order->get_billing_phone();
        $email = $order->get_billing_email();

        if (!$phone && !$email) {
            return;
        }

        // Search for previous orders by this customer
        $args = [
            'limit'   => 10,
            'return'  => 'ids',
            'exclude' => [$order->get_id()],
            'status'  => ['processing', 'completed', 'on-hold', 'cancelled', 'refunded', 'failed'],
            'orderby' => 'date',
            'order'   => 'DESC',
        ];

        $previous_orders = [];

        if ($phone) {
            $phone_orders = wc_get_orders(array_merge($args, ['billing_phone' => $phone]));
            $previous_orders = array_merge($previous_orders, $phone_orders);
        }
        if ($email) {
            $email_orders = wc_get_orders(array_merge($args, ['billing_email' => $email]));
            $previous_orders = array_merge($previous_orders, $email_orders);
        }

        $previous_orders = array_unique($previous_orders);

        if (empty($previous_orders)) {
            return; // New customer
        }

        $count = count($previous_orders);
        $completed = 0;
        $cancelled = 0;
        $total_spent = 0;

        foreach (array_slice($previous_orders, 0, 10) as $prev_id) {
            $prev = wc_get_order($prev_id);
            if (!$prev) continue;
            $status = $prev->get_status();
            if (in_array($status, ['completed', 'processing'])) {
                $completed++;
                $total_spent += (float) $prev->get_total();
            } elseif ($status === 'cancelled') {
                $cancelled++;
            }
        }

        $repeat_lines = [];
        $label = $count >= 5 ? '🌟 Loyal' : ($count >= 2 ? '🔁 Returning' : '📌 Known');
        $repeat_lines[] = "**{$label}** — **{$count}** previous order(s)";

        if ($completed > 0) {
            $repeat_lines[] = "✅ Completed: **{$completed}** • 💰 Total Spent: **" . $this->format_price($total_spent) . "**";
        }
        if ($cancelled > 0) {
            $repeat_lines[] = "❌ Cancelled: **{$cancelled}**";
        }

        // Last 3 orders
        foreach (array_slice($previous_orders, 0, 3) as $prev_id) {
            $prev = wc_get_order($prev_id);
            if (!$prev) continue;
            $date = $prev->get_date_created() ? $prev->get_date_created()->date('M j, Y') : '—';
            $status_emoji = match ($prev->get_status()) {
                'completed' => '✅',
                'processing' => '🔵',
                'cancelled' => '❌',
                'refunded' => '🟣',
                'failed' => '⛔',
                default => '📋',
            };
            $repeat_lines[] = "{$status_emoji} **#{$prev_id}** — {$date} — " . $this->format_price($prev->get_total());
        }

        // Reliability
        $total_orders = $completed + $cancelled;
        if ($total_orders >= 2) {
            $success_rate = round(($completed / $total_orders) * 100);
            $reliability = $success_rate >= 80 ? '🟢 Reliable' : ($success_rate >= 50 ? '🟡 Mixed' : '🔴 Risky');
            $repeat_lines[] = "📊 **Reliability:** {$reliability} ({$success_rate}% success)";
        }

        $text = implode("\n", $repeat_lines);
        if (mb_strlen($text) > self::FIELD_VALUE_LIMIT) {
            $text = mb_substr($text, 0, self::FIELD_VALUE_LIMIT - 20) . "\n...";
        }
        $fields[] = ['name' => '👥 Repeat Customer', 'value' => $text, 'inline' => false];
    }

    /**
     * Add fraud report fields directly into the main embed
     */
    private function add_fraud_fields(array &$fields, \WC_Order $order): void {
        $phone = $order->get_billing_phone();
        if (empty($phone)) {
            return;
        }

        $score = $order->get_meta('_guardify_fraud_score');
        $risk  = $order->get_meta('_guardify_fraud_risk');

        // If not yet checked, try to fetch from API
        if ($score === '' && class_exists('Guardify_Fraud_Check')) {
            $cache_key = 'guardify_fraud_' . md5($phone);
            $cached = get_transient($cache_key);
            if ($cached && is_array($cached)) {
                $score = intval($cached['score'] ?? 0);
                $risk  = $cached['risk'] ?? 'unknown';
            } else {
                $result = $this->fetch_fraud_score_for_discord($phone);
                if ($result) {
                    $score = intval($result['score'] ?? 0);
                    $risk  = $result['risk'] ?? 'unknown';
                    $order->update_meta_data('_guardify_fraud_score', $score);
                    $order->update_meta_data('_guardify_fraud_risk', $risk);
                    $order->update_meta_data('_guardify_fraud_checked_at', current_time('mysql'));
                    $order->save();
                } else {
                    return;
                }
            }
        }

        if ($score === '') {
            return;
        }

        $score = intval($score);
        $risk = $risk ?: 'unknown';

        $risk_emoji = match ($risk) {
            'low'      => '🟢',
            'medium'   => '🟡',
            'high'     => '🟠',
            'critical' => '🔴',
            default    => '⚪',
        };

        // Score bar visualization
        $bar_filled = (int) round($score / 10);
        $bar_empty = 10 - $bar_filled;
        $score_bar = str_repeat('🟥', $bar_filled) . str_repeat('⬜', $bar_empty);

        $fraud_lines = [];
        $fraud_lines[] = "🎯 **Score:** {$score}/100 {$score_bar}";
        $fraud_lines[] = "⚠️ **Risk:** {$risk_emoji} **" . ucfirst($risk) . "**";

        // Signals & courier from cache
        $cache_key = 'guardify_fraud_' . md5($phone);
        $cached = get_transient($cache_key);
        if ($cached && is_array($cached)) {
            $signals = $cached['signals'] ?? [];
            if (!empty($signals)) {
                $signal_parts = [];
                foreach (array_slice($signals, 0, 4) as $sig) {
                    $severity_icon = match ($sig['severity'] ?? 'info') {
                        'critical' => '🔴',
                        'high'     => '🟠',
                        'medium'   => '🟡',
                        'low'      => '🟢',
                        default    => 'ℹ️',
                    };
                    $signal_parts[] = $severity_icon . ' **' . ($sig['label'] ?? '') . ':** ' . ($sig['detail'] ?? '');
                }
                $fraud_lines[] = implode("\n", $signal_parts);
            }

            // Summary verdict
            $summary = $cached['summary'] ?? [];
            if (!empty($summary['verdict'])) {
                $fraud_lines[] = "📋 **Verdict:** " . $summary['verdict'];
            }
            if (!empty($summary)) {
                $stats = [];
                if (isset($summary['totalEvents'])) $stats[] = 'Events: **' . $summary['totalEvents'] . '**';
                if (isset($summary['blockedEvents'])) $stats[] = 'Blocked: **' . $summary['blockedEvents'] . '**';
                if (isset($summary['uniqueSites'])) $stats[] = 'Sites: **' . $summary['uniqueSites'] . '**';
                if ($stats) {
                    $fraud_lines[] = '📊 ' . implode(' • ', $stats);
                }
            }

            // Courier delivery stats (important for delivery report)
            $courier = $cached['courier'] ?? [];
            if (!empty($courier) && isset($courier['totalParcels']) && $courier['totalParcels'] > 0) {
                $courier_parts = [];
                $courier_parts[] = '📦 Parcels: **' . $courier['totalParcels'] . '**';
                $courier_parts[] = '✅ Delivered: **' . ($courier['totalDelivered'] ?? 0) . '**';
                $courier_parts[] = '❌ Cancelled: **' . ($courier['totalCancelled'] ?? 0) . '**';
                if (isset($courier['successRate'])) {
                    $courier_parts[] = '📈 Success: **' . $courier['successRate'] . '%**';
                }
                if (isset($courier['cancellationRate'])) {
                    $courier_parts[] = '📉 Cancel: **' . $courier['cancellationRate'] . '%**';
                }
                $fraud_lines[] = '🚚 ' . implode(' • ', $courier_parts);
            }
        }

        $text = implode("\n", $fraud_lines);
        if (mb_strlen($text) > self::FIELD_VALUE_LIMIT) {
            $text = mb_substr($text, 0, self::FIELD_VALUE_LIMIT - 20) . "\n...";
        }
        $fields[] = ['name' => '🛡️ Fraud Report — ' . $phone, 'value' => $text, 'inline' => false];
    }

    // =========================================================================
    // DISCORD API
    // =========================================================================

    /**
     * Send payload to Discord webhook (blocking with error handling + retry)
     */
    private function send_webhook(array $payload, int $attempt = 1, string $event_type = ''): bool {
        $webhook_url = !empty($event_type) ? $this->get_webhook_url_for_event($event_type) : $this->get_webhook_url();
        if (empty($webhook_url)) {
            error_log('Guardify Discord: No webhook URL configured (event: ' . ($event_type ?: 'general') . ').');
            return false;
        }

        // Add bot username only (no avatar option)
        $payload['username'] = get_option('guardify_discord_bot_name', 'Guardify');

        $json_body = wp_json_encode($payload);
        if (!$json_body) {
            error_log('Guardify Discord: Failed to encode payload to JSON.');
            return false;
        }

        // Use blocking request to ensure delivery
        $response = wp_remote_post($webhook_url, [
            'timeout'     => 15,
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => $json_body,
            'blocking'    => true,
            'sslverify'   => true,
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            error_log('Guardify Discord Error (attempt ' . $attempt . '): ' . $response->get_error_message());
            if ($attempt < self::MAX_RETRIES) {
                $this->schedule_retry($payload, $attempt + 1, 5, $event_type);
            }
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code >= 200 && $code < 300) {
            return true;
        }

        if ($code === 429) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $retry_after = isset($body['retry_after']) ? (float) $body['retry_after'] : 2;
            $retry_after_seconds = max(1, (int) ceil($retry_after));
            error_log("Guardify Discord: Rate limited. Retry after {$retry_after}s (attempt {$attempt}).");
            if ($attempt < self::MAX_RETRIES) {
                $this->schedule_retry($payload, $attempt + 1, $retry_after_seconds, $event_type);
            }
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        error_log("Guardify Discord Error (HTTP {$code}, attempt {$attempt}): " . mb_substr($body, 0, 300));

        if ($attempt < self::MAX_RETRIES && $code >= 500) {
            $this->schedule_retry($payload, $attempt + 1, 5, $event_type);
        }

        return false;
    }

    /**
     * Schedule a retry for a failed webhook
     */
    private function schedule_retry(array $payload, int $attempt, int $delay_seconds = 5, string $event_type = ''): void {
        wp_schedule_single_event(
            time() + $delay_seconds,
            'guardify_discord_retry_webhook',
            [$payload, $attempt, $event_type]
        );
    }

    /**
     * Handle scheduled webhook retry
     */
    public function retry_failed_webhook(array $payload, int $attempt, string $event_type = ''): void {
        $this->send_webhook($payload, $attempt, $event_type);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Fetch fraud score from TansiqLabs API for Discord notification
     */
    private function fetch_fraud_score_for_discord(string $phone): ?array {
        $api_key = get_option('guardify_site_api_key', '');
        if (empty($api_key)) {
            $api_key = get_option('guardify_api_key', '');
        }
        if (empty($api_key)) {
            return null;
        }

        $response = wp_remote_post('https://tansiqlabs.com/api/guardify/fraud-check', [
            'timeout'   => 8,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => wp_json_encode([
                'api_key' => $api_key,
                'phone'   => $phone,
            ]),
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['success'])) {
            return null;
        }

        $cache_key = 'guardify_fraud_' . md5($phone);
        set_transient($cache_key, $body, 600);

        return $body;
    }

    /**
     * Parse user agent into a human-readable device string
     */
    private function parse_device_string(string $ua): string {
        if (empty($ua)) {
            return '';
        }

        $browser = 'Unknown Browser';
        $os = 'Unknown OS';

        // Detect browser
        if (preg_match('/Firefox\/(\d+)/', $ua, $m)) {
            $browser = 'Firefox ' . $m[1];
        } elseif (preg_match('/Edg\/(\d+)/', $ua, $m)) {
            $browser = 'Edge ' . $m[1];
        } elseif (preg_match('/OPR\/(\d+)/', $ua, $m)) {
            $browser = 'Opera ' . $m[1];
        } elseif (preg_match('/SamsungBrowser\/(\d+)/', $ua, $m)) {
            $browser = 'Samsung Browser ' . $m[1];
        } elseif (preg_match('/UCBrowser\/(\d+)/', $ua, $m)) {
            $browser = 'UC Browser ' . $m[1];
        } elseif (preg_match('/Chrome\/(\d+)/', $ua, $m)) {
            $browser = 'Chrome ' . $m[1];
        } elseif (preg_match('/Safari\/(\d+)/', $ua) && preg_match('/Version\/(\d+)/', $ua, $m)) {
            $browser = 'Safari ' . $m[1];
        }

        // Detect OS
        if (stripos($ua, 'Android') !== false) {
            $os = 'Android';
            if (preg_match('/Android\s*([\d.]+)/', $ua, $m)) {
                $os = 'Android ' . $m[1];
            }
        } elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
            $os = 'iOS';
            if (preg_match('/OS\s*([\d_]+)/', $ua, $m)) {
                $os = 'iOS ' . str_replace('_', '.', $m[1]);
            }
        } elseif (stripos($ua, 'Windows') !== false) {
            $os = 'Windows';
            if (preg_match('/Windows NT (\d+\.\d+)/', $ua, $m)) {
                $nt_map = ['10.0' => '10/11', '6.3' => '8.1', '6.2' => '8', '6.1' => '7'];
                $os = 'Windows ' . ($nt_map[$m[1]] ?? $m[1]);
            }
        } elseif (stripos($ua, 'Mac OS X') !== false) {
            $os = 'macOS';
            if (preg_match('/Mac OS X (\d+[\._]\d+)/', $ua, $m)) {
                $os = 'macOS ' . str_replace('_', '.', $m[1]);
            }
        } elseif (stripos($ua, 'Linux') !== false) {
            $os = 'Linux';
        } elseif (stripos($ua, 'CrOS') !== false) {
            $os = 'Chrome OS';
        }

        return $browser . ' / ' . $os;
    }
}
