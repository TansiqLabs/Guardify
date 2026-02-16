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
 * Includes:
 *  - All filled form fields (billing, shipping, custom fields like size/color)
 *  - Browser metadata (device, screen, language, referrer, UTM params)
 *  - Cart items, totals, coupons, payment method
 *  - Fraud report (score, risk, signals, courier stats) from TansiqLabs API
 *  - Repeat customer detection and order history
 *  - Color-coded embeds per event type
 *  - Error logging with retry support
 *  - Rate-limit aware sending
 *
 * @package Guardify
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Guardify_Discord {
    private static ?self $instance = null;

    /** Maximum embeds per Discord message */
    private const MAX_EMBEDS = 10;

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

        $avatar = get_option('guardify_discord_bot_avatar', '');
        if ($avatar) $payload['avatar_url'] = $avatar;

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

        $payment = $order->get_payment_method_title() ?: 'Unknown';
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

        // Skip new_order → processing if we already sent a new_order notification
        if ($new_status === 'processing' && in_array($old_status, ['checkout-draft', 'pending', 'incomplete'])) {
            if ($order->get_meta('_guardify_discord_new_order_sent') === '1') {
                // Still send but as a brief status update, not full order details
                $this->send_status_update($order, $old_status, $new_status);
                return;
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

        $this->send_notification($order, [
            'event'       => $event_key,
            'title'       => "{$emoji} Order #{$order_id} — " . ucfirst($new_status),
            'description' => $desc,
            'color'       => self::STATUS_COLORS[$event_key] ?? 0x6C757D,
            'compact'     => in_array($new_status, ['completed', 'cancelled', 'refunded']),
            'include_fraud' => ($new_status === 'failed'),
        ]);
    }

    /**
     * Send a brief status update embed (avoids duplicate full notifications)
     */
    private function send_status_update(\WC_Order $order, string $old_status, string $new_status): void {
        $emoji = self::STATUS_EMOJIS[$new_status] ?? '📋';
        $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $phone = $order->get_billing_phone();

        $fields = [];
        if ($name) $fields[] = ['name' => '👤 Customer', 'value' => $name, 'inline' => true];
        if ($phone) $fields[] = ['name' => '📱 Phone', 'value' => $phone, 'inline' => true];
        $fields[] = ['name' => '💰 Total', 'value' => strip_tags(wc_price($order->get_total())), 'inline' => true];
        $fields[] = ['name' => '📋 Status', 'value' => ucfirst($old_status) . ' → **' . ucfirst($new_status) . '**', 'inline' => false];

        $this->send_webhook([
            'embeds' => [[
                'title'     => "{$emoji} Order #{$order->get_id()} → " . ucfirst($new_status),
                'color'     => self::STATUS_COLORS[$new_status] ?? 0x6C757D,
                'fields'    => $fields,
                'timestamp' => gmdate('c'),
                'url'       => admin_url('admin.php?page=wc-orders&action=edit&id=' . $order->get_id()),
                'footer'    => ['text' => 'Guardify v' . GUARDIFY_VERSION . ' • ' . wp_parse_url(home_url(), PHP_URL_HOST)],
            ]],
        ], 1, $new_status);
    }

    // =========================================================================
    // NOTIFICATION BUILDER
    // =========================================================================

    /**
     * Build and send a Discord webhook notification
     */
    private function send_notification(\WC_Order $order, array $context): void {
        $embeds = [];
        $is_compact = !empty($context['compact']);

        // ─── MAIN EMBED (order info) ───
        $main_embed = [
            'title'       => $context['title'],
            'description' => mb_substr($context['description'], 0, self::DESCRIPTION_LIMIT),
            'color'       => $context['color'],
            'timestamp'   => gmdate('c'),
            'fields'      => [],
            'footer'      => [
                'text' => 'Guardify v' . (defined('GUARDIFY_VERSION') ? GUARDIFY_VERSION : '1.2.0') . ' • ' . wp_parse_url(home_url(), PHP_URL_HOST),
            ],
        ];

        // Order URL (link to WooCommerce admin)
        $order_url = admin_url('admin.php?page=wc-orders&action=edit&id=' . $order->get_id());
        $main_embed['url'] = $order_url;

        // ─── CUSTOMER INFO FIELDS ───
        $this->add_customer_fields($main_embed['fields'], $order);

        if (!$is_compact) {
            // ─── CART ITEMS ───
            $this->add_cart_fields($main_embed['fields'], $order);

            // ─── COUPON INFO ───
            $this->add_coupon_fields($main_embed['fields'], $order);

            // ─── PAYMENT INFO ───
            $this->add_payment_fields($main_embed['fields'], $order);

            // ─── CUSTOM CHECKOUT FIELDS (size, color, etc.) ───
            $this->add_custom_fields($main_embed['fields'], $order);
        } else {
            // Compact mode: just total and payment
            $total = $order->get_total();
            if ($total > 0) {
                $main_embed['fields'][] = ['name' => '💰 Total', 'value' => strip_tags(wc_price($total)), 'inline' => true];
            }
        }

        $embeds[] = $main_embed;

        if (!$is_compact) {
            // ─── REPEAT CUSTOMER EMBED ───
            if (!empty($context['include_repeat'])) {
                $repeat_embed = $this->build_repeat_customer_embed($order);
                if ($repeat_embed) {
                    $embeds[] = $repeat_embed;
                }
            }

            // ─── BROWSER/DEVICE EMBED ───
            $browser_embed = $this->build_browser_embed($order, $context['capture_data'] ?? []);
            if ($browser_embed) {
                $embeds[] = $browser_embed;
            }

            // ─── UTM / CAMPAIGN EMBED ───
            $utm_embed = $this->build_utm_embed($order);
            if ($utm_embed) {
                $embeds[] = $utm_embed;
            }
        }

        // ─── FRAUD REPORT EMBED (always if requested) ───
        if (!empty($context['include_fraud'])) {
            $fraud_embed = $this->build_fraud_embed($order);
            if ($fraud_embed) {
                $embeds[] = $fraud_embed;
            }
        }

        // Limit embeds
        $embeds = array_slice($embeds, 0, self::MAX_EMBEDS);

        // ─── SEND ───
        $this->send_webhook([
            'embeds' => $embeds,
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

        $total = strip_tags(wc_price($order->get_total()));
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
    // FIELD BUILDERS
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
            $total = strip_tags(wc_price($item->get_total()));

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
            $total_lines[] = '**Subtotal:** ' . strip_tags(wc_price($subtotal));
        }
        if ($shipping_total > 0) {
            $shipping_method = '';
            $shipping_items = $order->get_shipping_methods();
            foreach ($shipping_items as $s) {
                $shipping_method = $s->get_method_title();
                break;
            }
            $total_lines[] = '**Shipping:** ' . strip_tags(wc_price($shipping_total)) . ($shipping_method ? " ({$shipping_method})" : '');
        }
        if ($discount > 0) {
            $total_lines[] = '**Discount:** -' . strip_tags(wc_price($discount));
        }
        if ($tax_total > 0) {
            $total_lines[] = '**Tax:** ' . strip_tags(wc_price($tax_total));
        }
        $total_lines[] = '💰 **Total:** ' . strip_tags(wc_price($total));

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
            $discount = strip_tags(wc_price($coupon_item->get_discount()));
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
        if (!$method) return;

        $payment_text = '💳 ' . $method;
        
        // Transaction ID if available
        $txn_id = $order->get_transaction_id();
        if ($txn_id) {
            $payment_text .= "\nTxn: `{$txn_id}`";
        }

        $fields[] = ['name' => '💳 Payment', 'value' => $payment_text, 'inline' => true];

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

    // =========================================================================
    // EMBED BUILDERS
    // =========================================================================

    /**
     * Build repeat customer detection embed
     */
    private function build_repeat_customer_embed(\WC_Order $order): ?array {
        $phone = $order->get_billing_phone();
        $email = $order->get_billing_email();

        if (!$phone && !$email) {
            return null;
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

        // Search by phone
        if ($phone) {
            $phone_orders = wc_get_orders(array_merge($args, [
                'billing_phone' => $phone,
            ]));
            $previous_orders = array_merge($previous_orders, $phone_orders);
        }

        // Search by email (if different results)
        if ($email) {
            $email_orders = wc_get_orders(array_merge($args, [
                'billing_email' => $email,
            ]));
            $previous_orders = array_merge($previous_orders, $email_orders);
        }

        // Deduplicate
        $previous_orders = array_unique($previous_orders);

        if (empty($previous_orders)) {
            return null; // New customer, no need for embed
        }

        $count = count($previous_orders);
        $fields = [];

        $fields[] = ['name' => '🔄 Previous Orders', 'value' => "**{$count}** previous order(s) found", 'inline' => true];

        // Quick summary of recent orders
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

        if ($completed > 0) {
            $fields[] = ['name' => '✅ Completed', 'value' => (string) $completed, 'inline' => true];
            $fields[] = ['name' => '💰 Total Spent', 'value' => strip_tags(wc_price($total_spent)), 'inline' => true];
        }
        if ($cancelled > 0) {
            $fields[] = ['name' => '❌ Cancelled', 'value' => (string) $cancelled, 'inline' => true];
        }

        // Show last 3 orders  
        $recent_lines = [];
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
            $recent_lines[] = "{$status_emoji} **#{$prev_id}** — {$date} — " . strip_tags(wc_price($prev->get_total()));
        }
        if ($recent_lines) {
            $fields[] = ['name' => '📜 Recent Orders', 'value' => implode("\n", $recent_lines), 'inline' => false];
        }

        // Customer reliability score
        $total_orders = $completed + $cancelled;
        if ($total_orders >= 2) {
            $success_rate = round(($completed / $total_orders) * 100);
            $reliability = $success_rate >= 80 ? '🟢 Reliable' : ($success_rate >= 50 ? '🟡 Mixed' : '🔴 Risky');
            $fields[] = ['name' => '📊 Reliability', 'value' => "{$reliability} ({$success_rate}% success rate)", 'inline' => false];
        }

        return [
            'title'  => '👥 Repeat Customer — ' . ($count >= 5 ? '🌟 Loyal' : ($count >= 2 ? '🔁 Returning' : '📌 Known')),
            'color'  => $count >= 5 ? 0x28A745 : ($count >= 2 ? 0x17A2B8 : 0xFFC107),
            'fields' => $fields,
        ];
    }

    /**
     * Build browser/device info embed
     */
    private function build_browser_embed(\WC_Order $order, array $capture_data = []): ?array {
        $fields = [];

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
                return null;
            }
        }

        $device = $this->parse_device_string($ua);
        if ($device) {
            $fields[] = ['name' => '📱 Device', 'value' => $device, 'inline' => true];
        }

        if ($platform) {
            $fields[] = ['name' => '💻 Platform', 'value' => $platform, 'inline' => true];
        }

        if ($screen_w && $screen_h) {
            $fields[] = ['name' => '🖥️ Screen', 'value' => $screen_w . ' × ' . $screen_h, 'inline' => true];
        }

        $device_type = ($touch === '1') ? '📱 Mobile/Tablet' : '🖥️ Desktop';
        $fields[] = ['name' => '🔌 Type', 'value' => $device_type, 'inline' => true];

        if ($language) {
            $fields[] = ['name' => '🌍 Language', 'value' => $language, 'inline' => true];
        }

        if ($timezone) {
            $fields[] = ['name' => '🕐 Timezone', 'value' => $timezone, 'inline' => true];
        }

        if ($conn) {
            $fields[] = ['name' => '📶 Connection', 'value' => $conn, 'inline' => true];
        }

        if ($referrer) {
            $ref_domain = wp_parse_url($referrer, PHP_URL_HOST) ?: $referrer;
            $fields[] = ['name' => '🔗 Referrer', 'value' => "[{$ref_domain}]({$referrer})", 'inline' => true];
        }

        if (empty($fields)) {
            return null;
        }

        return [
            'title'  => '🖥️ Browser & Device Info',
            'color'  => 0x17A2B8,
            'fields' => $fields,
        ];
    }

    /**
     * Build UTM / campaign parameters embed
     */
    private function build_utm_embed(\WC_Order $order): ?array {
        $params = [];
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

        foreach ($utm_keys as $key => $label) {
            $value = $order->get_meta('_guardify_param_' . $key);
            if ($value) {
                $params[] = ['name' => '📊 ' . $label, 'value' => $value, 'inline' => true];
            }
        }

        // Landing page
        $landing = $order->get_meta('_guardify_param_landing_page');
        if ($landing) {
            $params[] = ['name' => '🔗 Landing Page', 'value' => $landing, 'inline' => false];
        }

        if (empty($params)) {
            return null;
        }

        return [
            'title'  => '📈 Campaign / UTM Data',
            'color'  => 0x6F42C1,
            'fields' => $params,
        ];
    }

    /**
     * Build fraud report embed from stored fraud data
     */
    private function build_fraud_embed(\WC_Order $order): ?array {
        $phone = $order->get_billing_phone();
        if (empty($phone)) {
            return null;
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
                    return null;
                }
            }
        }

        if ($score === '') {
            return null;
        }

        $score = intval($score);
        $risk = $risk ?: 'unknown';

        $risk_colors = [
            'low'      => 0x28A745,
            'medium'   => 0xFFC107,
            'high'     => 0xFD7E14,
            'critical' => 0xDC3545,
        ];
        $embed_color = $risk_colors[$risk] ?? 0x6C757D;

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

        $fields = [];
        $fields[] = ['name' => '🎯 Fraud Score', 'value' => "**{$score}/100**\n{$score_bar}", 'inline' => true];
        $fields[] = ['name' => '⚠️ Risk Level', 'value' => $risk_emoji . ' **' . ucfirst($risk) . '**', 'inline' => true];

        // Signals
        $cache_key = 'guardify_fraud_' . md5($phone);
        $cached = get_transient($cache_key);
        if ($cached && is_array($cached)) {
            $signals = $cached['signals'] ?? [];
            if (!empty($signals)) {
                $signal_lines = [];
                foreach (array_slice($signals, 0, 6) as $sig) {
                    $severity_icon = match ($sig['severity'] ?? 'info') {
                        'critical' => '🔴',
                        'high'     => '🟠',
                        'medium'   => '🟡',
                        'low'      => '🟢',
                        default    => 'ℹ️',
                    };
                    $signal_lines[] = $severity_icon . ' **' . ($sig['label'] ?? '') . ':** ' . ($sig['detail'] ?? '');
                }
                $fields[] = ['name' => '🔍 Risk Signals', 'value' => implode("\n", $signal_lines), 'inline' => false];
            }

            // Summary verdict
            $summary = $cached['summary'] ?? [];
            if (!empty($summary)) {
                $verdict = $summary['verdict'] ?? '';
                if ($verdict) {
                    $fields[] = ['name' => '📋 Verdict', 'value' => $verdict, 'inline' => false];
                }
                $stats = [];
                if (isset($summary['totalEvents'])) $stats[] = '📊 Events: **' . $summary['totalEvents'] . '**';
                if (isset($summary['blockedEvents'])) $stats[] = '🚫 Blocked: **' . $summary['blockedEvents'] . '**';
                if (isset($summary['uniqueSites'])) $stats[] = '🌐 Sites: **' . $summary['uniqueSites'] . '**';
                if ($stats) {
                    $fields[] = ['name' => '📊 Network Stats', 'value' => implode(' • ', $stats), 'inline' => false];
                }
            }

            // Courier delivery stats
            $courier = $cached['courier'] ?? [];
            if (!empty($courier) && isset($courier['totalParcels']) && $courier['totalParcels'] > 0) {
                $courier_lines = [];
                $courier_lines[] = '📦 Total Parcels: **' . $courier['totalParcels'] . '**';
                $courier_lines[] = '✅ Delivered: **' . ($courier['totalDelivered'] ?? 0) . '**';
                $courier_lines[] = '❌ Cancelled: **' . ($courier['totalCancelled'] ?? 0) . '**';
                if (isset($courier['successRate'])) {
                    $courier_lines[] = '📈 Success Rate: **' . $courier['successRate'] . '%**';
                }
                if (isset($courier['cancellationRate'])) {
                    $courier_lines[] = '📉 Cancel Rate: **' . $courier['cancellationRate'] . '%**';
                }
                $fields[] = ['name' => '🚚 Courier History', 'value' => implode("\n", $courier_lines), 'inline' => false];
            }
        }

        return [
            'title'  => '🛡️ Fraud Report — ' . $phone,
            'color'  => $embed_color,
            'fields' => $fields,
        ];
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

        // Add username and avatar
        $payload['username'] = get_option('guardify_discord_bot_name', 'Guardify');
        $avatar = get_option('guardify_discord_bot_avatar', '');
        if ($avatar) {
            $payload['avatar_url'] = $avatar;
        }

        $json_body = wp_json_encode($payload);
        if (!$json_body) {
            error_log('Guardify Discord: Failed to encode payload to JSON.');
            return false;
        }

        // Use blocking request to ensure delivery (critical fix)
        $response = wp_remote_post($webhook_url, [
            'timeout'     => 15,
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => $json_body,
            'blocking'    => true, // MUST be true to ensure message is actually sent
            'sslverify'   => true,
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            error_log('Guardify Discord Error (attempt ' . $attempt . '): ' . $response->get_error_message());
            
            // Schedule retry
            if ($attempt < self::MAX_RETRIES) {
                $this->schedule_retry($payload, $attempt + 1, 5, $event_type);
            }
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);

        // Success
        if ($code >= 200 && $code < 300) {
            return true;
        }

        // Rate limited
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

        // Other errors
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
        } elseif (preg_match('/Chrome\/(\d+)/', $ua, $m)) {
            $browser = 'Chrome ' . $m[1];
        } elseif (preg_match('/Safari\/(\d+)/', $ua) && preg_match('/Version\/(\d+)/', $ua, $m)) {
            $browser = 'Safari ' . $m[1];
        } elseif (preg_match('/SamsungBrowser\/(\d+)/', $ua, $m)) {
            $browser = 'Samsung Browser ' . $m[1];
        } elseif (preg_match('/UCBrowser\/(\d+)/', $ua, $m)) {
            $browser = 'UC Browser ' . $m[1];
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
