<?php
/**
 * Guardify Discord Webhook Notifications
 * 
 * Sends rich Discord embed messages when:
 *  1. A new incomplete order is created (browser-only or with form data)
 *  2. An incomplete order gets identifiable data (phone/email filled)
 *  3. A real WooCommerce order is placed
 *
 * Includes:
 *  - All filled form fields (billing, shipping, custom fields like size/color)
 *  - Browser metadata (device, screen, language, referrer, UTM params)
 *  - Cart items and totals
 *  - Fraud report (score, risk, signals, courier stats) from TansiqLabs API
 *  - Color-coded embeds: 🔴 Incomplete, 🟠 Identified, 🟢 Completed, 🔴 Fraud Alert
 *
 * @package Guardify
 * @since 1.1.0
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

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!$this->is_enabled()) {
            return;
        }

        // ─── Incomplete order events (from Guardify_Abandoned_Cart) ───
        add_action('guardify_incomplete_order_created', [$this, 'on_incomplete_created'], 10, 3);
        add_action('guardify_incomplete_order_identified', [$this, 'on_incomplete_identified'], 10, 3);

        // ─── Real order placed ───
        add_action('woocommerce_checkout_order_created', [$this, 'on_order_created'], 20, 1);

        // ─── Order status changes (completed, processing, failed) ───
        add_action('woocommerce_order_status_changed', [$this, 'on_status_changed'], 20, 4);
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

    private function should_notify(string $event_type): bool {
        $events = get_option('guardify_discord_events', ['incomplete', 'identified', 'new_order']);
        if (!is_array($events)) {
            $events = ['incomplete', 'identified', 'new_order'];
        }
        return in_array($event_type, $events, true);
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
        
        $this->send_notification($order, [
            'event'       => 'incomplete_created',
            'title'       => $has_form 
                ? '🔴 Incomplete Order #' . $order_id 
                : '🌐 New Visitor — Incomplete #' . $order_id,
            'description' => $has_form 
                ? 'Customer started checkout but did not complete the order.'
                : 'Visitor landed on checkout page. No form data yet — browser metadata captured.',
            'color'       => $has_form ? 0xDC3545 : 0x6C757D, // red or grey
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
            'color'       => 0xFD7E14, // orange
            'capture_data' => $data,
            'include_fraud' => true, // trigger fraud lookup
        ]);
    }

    /**
     * Real WooCommerce order placed
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

        $this->send_notification($order, [
            'event'       => 'new_order',
            'title'       => '🟢 New Order #' . $order->get_id(),
            'description' => 'Order placed via ' . ucfirst($order->get_payment_method_title() ?: 'Unknown') . '.',
            'color'       => 0x28A745, // green
            'include_fraud' => true,
        ]);
    }

    /**
     * Order status changed (catch failed orders / fraud blocks)
     */
    public function on_status_changed(int $order_id, string $old_status, string $new_status, \WC_Order $order): void {
        // Only notify on specific status changes
        if ($new_status === 'failed' && $this->should_notify('fraud_block')) {
            $score = $order->get_meta('_guardify_fraud_score');
            if ($score !== '' && (int) $score >= 50) {
                $this->send_notification($order, [
                    'event'       => 'fraud_block',
                    'title'       => '🚨 Fraud Block — Order #' . $order_id,
                    'description' => sprintf('Order auto-failed. Fraud score: %d/100 (%s risk).', (int) $score, ucfirst($order->get_meta('_guardify_fraud_risk') ?: 'unknown')),
                    'color'       => 0xFF0000, // bright red
                    'include_fraud' => true,
                ]);
            }
        }
    }

    // =========================================================================
    // NOTIFICATION BUILDER
    // =========================================================================

    /**
     * Build and send a Discord webhook notification
     */
    private function send_notification(\WC_Order $order, array $context): void {
        $embeds = [];

        // ─── MAIN EMBED (order info) ───
        $main_embed = [
            'title'       => $context['title'],
            'description' => $context['description'],
            'color'       => $context['color'],
            'timestamp'   => gmdate('c'),
            'fields'      => [],
            'footer'      => [
                'text' => 'Guardify v' . (defined('GUARDIFY_VERSION') ? GUARDIFY_VERSION : '1.1.0') . ' • ' . wp_parse_url(home_url(), PHP_URL_HOST),
            ],
        ];

        // Order URL (link to WooCommerce admin)
        $order_url = admin_url('admin.php?page=wc-orders&action=edit&id=' . $order->get_id());
        $main_embed['url'] = $order_url;

        // ─── CUSTOMER INFO FIELDS ───
        $this->add_customer_fields($main_embed['fields'], $order);

        // ─── CART ITEMS ───
        $this->add_cart_fields($main_embed['fields'], $order);

        // ─── CUSTOM CHECKOUT FIELDS (size, color, etc.) ───
        $this->add_custom_fields($main_embed['fields'], $order);

        $embeds[] = $main_embed;

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

        // ─── FRAUD REPORT EMBED ───
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
        ]);
    }

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

        // Shipping (if different)
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
            if ($shipping_text) {
                $fields[] = ['name' => '📦 Shipping', 'value' => $shipping_text, 'inline' => false];
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
            $fields[] = ['name' => '📝 Note', 'value' => mb_substr($note, 0, 200), 'inline' => false];
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
        foreach ($items as $item) {
            $qty = $item->get_quantity();
            $name = $item->get_name();
            $total = wc_price($item->get_total());
            // Strip HTML from wc_price for Discord
            $total_text = strip_tags($total);
            $lines[] = "• {$name} × {$qty} — {$total_text}";
        }

        $cart_text = implode("\n", $lines);
        if (mb_strlen($cart_text) > self::FIELD_VALUE_LIMIT) {
            $cart_text = mb_substr($cart_text, 0, self::FIELD_VALUE_LIMIT - 20) . "\n... (truncated)";
        }

        $fields[] = ['name' => '🛒 Cart Items', 'value' => $cart_text, 'inline' => false];

        // Total
        $total = $order->get_total();
        if ($total > 0) {
            $fields[] = ['name' => '💰 Total', 'value' => strip_tags(wc_price($total)), 'inline' => true];
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
            // Make key human-readable (replace underscores, capitalize)
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
     * Build browser/device info embed
     */
    private function build_browser_embed(\WC_Order $order, array $capture_data = []): ?array {
        $fields = [];

        // Get browser data from order meta
        $ua       = $order->get_meta('_guardify_browser_browser_ua') ?: ($capture_data['_browser_browser_ua'] ?? '');
        $language = $order->get_meta('_guardify_browser_browser_language') ?: ($capture_data['_browser_browser_language'] ?? '');
        $platform = $order->get_meta('_guardify_browser_browser_platform') ?: ($capture_data['_browser_browser_platform'] ?? '');
        $screen_w = $order->get_meta('_guardify_browser_screen_width') ?: ($capture_data['_browser_screen_width'] ?? '');
        $screen_h = $order->get_meta('_guardify_browser_screen_height') ?: ($capture_data['_browser_screen_height'] ?? '');
        $referrer = $order->get_meta('_guardify_browser_referrer') ?: ($capture_data['_browser_referrer'] ?? '');
        $timezone = $order->get_meta('_guardify_browser_timezone') ?: ($capture_data['_browser_timezone'] ?? '');
        $touch    = $order->get_meta('_guardify_browser_touch_support') ?: ($capture_data['_browser_touch_support'] ?? '');
        $conn     = $order->get_meta('_guardify_browser_connection_type') ?: ($capture_data['_browser_connection_type'] ?? '');

        // If literally nothing, skip
        if (!$ua && !$platform && !$screen_w && !$referrer) {
            // Fallback to WC-stored user agent
            $wc_ua = $order->get_customer_user_agent();
            if ($wc_ua) {
                $ua = $wc_ua;
            } else {
                return null;
            }
        }

        // Parse user agent for a readable device string
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
            $fields[] = ['name' => '🔗 Referrer', 'value' => $ref_domain, 'inline' => true];
        }

        if (empty($fields)) {
            return null;
        }

        return [
            'title'  => '🖥️ Browser & Device Info',
            'color'  => 0x17A2B8, // info blue
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
        ];

        foreach ($utm_keys as $key => $label) {
            $value = $order->get_meta('_guardify_param_' . $key);
            if ($value) {
                $params[] = ['name' => '📊 ' . $label, 'value' => $value, 'inline' => true];
            }
        }

        if (empty($params)) {
            return null;
        }

        return [
            'title'  => '📈 Campaign / UTM Data',
            'color'  => 0x6F42C1, // purple
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

        // Check if fraud data is already stored on the order
        $score = $order->get_meta('_guardify_fraud_score');
        $risk  = $order->get_meta('_guardify_fraud_risk');

        // If not yet checked, try to fetch from API (non-blocking)
        if ($score === '' && class_exists('Guardify_Fraud_Check')) {
            // Try cached result first (transient)
            $cache_key = 'guardify_fraud_' . md5($phone);
            $cached = get_transient($cache_key);
            if ($cached && is_array($cached)) {
                $score = intval($cached['score'] ?? 0);
                $risk  = $cached['risk'] ?? 'unknown';
            } else {
                // Attempt a quick API call
                $result = $this->fetch_fraud_score_for_discord($phone);
                if ($result) {
                    $score = intval($result['score'] ?? 0);
                    $risk  = $result['risk'] ?? 'unknown';
                    
                    // Save to order
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

        // Color based on risk
        $risk_colors = [
            'low'      => 0x28A745, // green
            'medium'   => 0xFFC107, // yellow
            'high'     => 0xFD7E14, // orange
            'critical' => 0xDC3545, // red
        ];
        $embed_color = $risk_colors[$risk] ?? 0x6C757D;

        // Risk emoji
        $risk_emoji = match ($risk) {
            'low'      => '🟢',
            'medium'   => '🟡',
            'high'     => '🟠',
            'critical' => '🔴',
            default    => '⚪',
        };

        $fields = [];
        $fields[] = ['name' => '🎯 Fraud Score', 'value' => "**{$score}/100**", 'inline' => true];
        $fields[] = ['name' => '⚠️ Risk Level', 'value' => $risk_emoji . ' ' . ucfirst($risk), 'inline' => true];

        // Signals (from transient cache or a fresh call)
        $cache_key = 'guardify_fraud_' . md5($phone);
        $cached = get_transient($cache_key);
        if ($cached && is_array($cached)) {
            // Signals
            $signals = $cached['signals'] ?? [];
            if (!empty($signals)) {
                $signal_lines = [];
                foreach (array_slice($signals, 0, 5) as $sig) {
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

            // Summary
            $summary = $cached['summary'] ?? [];
            if (!empty($summary)) {
                $verdict = $summary['verdict'] ?? '';
                if ($verdict) {
                    $fields[] = ['name' => '📋 Verdict', 'value' => $verdict, 'inline' => false];
                }
                $stats = [];
                if (isset($summary['totalEvents'])) $stats[] = 'Events: ' . $summary['totalEvents'];
                if (isset($summary['blockedEvents'])) $stats[] = 'Blocked: ' . $summary['blockedEvents'];
                if (isset($summary['uniqueSites'])) $stats[] = 'Sites: ' . $summary['uniqueSites'];
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
     * Send payload to Discord webhook
     */
    private function send_webhook(array $payload): bool {
        $webhook_url = $this->get_webhook_url();
        if (empty($webhook_url)) {
            return false;
        }

        // Add username and avatar
        $payload['username'] = get_option('guardify_discord_bot_name', 'Guardify');
        $avatar = get_option('guardify_discord_bot_avatar', '');
        if ($avatar) {
            $payload['avatar_url'] = $avatar;
        }

        $response = wp_remote_post($webhook_url, [
            'timeout'     => 10,
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => wp_json_encode($payload),
            'blocking'    => false, // Non-blocking — don't slow down checkout
            'sslverify'   => true,
        ]);

        if (is_wp_error($response)) {
            error_log('Guardify Discord Error: ' . $response->get_error_message());
            return false;
        }

        return true;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Fetch fraud score from TansiqLabs API for Discord notification
     * (mirrors Guardify_Fraud_Check::fetch_fraud_score but with lower timeout)
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

        // Cache it
        $cache_key = 'guardify_fraud_' . md5($phone);
        set_transient($cache_key, $body, 600); // 10 min

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
        }

        return $browser . ' / ' . $os;
    }
}
