<?php
/**
 * Guardify Fraud Check Class
 * TansiqLabs API-powered fraud risk assessment displayed on WooCommerce order pages.
 *
 * Shows a real-time fraud score, risk signals, and event history for each
 * order's phone number directly in the WooCommerce admin order edit screen.
 *
 * @package Guardify
 * @since   1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Guardify_Fraud_Check {
    private static ?self $instance = null;

    /** TansiqLabs Fraud Check endpoint. */
    private const API_ENDPOINT = 'https://tansiqlabs.com/api/guardify/fraud-check';

    /** TansiqLabs Courier Sync endpoint (bulk cached data). */
    private const COURIER_SYNC_ENDPOINT = 'https://tansiqlabs.com/api/guardify/courier-sync';

    /** Transient TTL: cache each result for 10 minutes. */
    private const CACHE_TTL = 600;

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Meta box on single order
        add_action('add_meta_boxes', [$this, 'register_meta_box']);

        // HPOS support
        add_action('woocommerce_page_wc-orders', function () {
            add_action('add_meta_boxes', [$this, 'register_meta_box']);
        }, 5);

        // AJAX handler for on-demand fraud check
        add_action('wp_ajax_guardify_fraud_check', [$this, 'ajax_fraud_check']);

        // AJAX handler for refreshing global courier data (Score column)
        add_action('wp_ajax_guardify_refresh_courier', [$this, 'ajax_refresh_courier']);
        add_action('wp_ajax_guardify_batch_refresh_courier', [$this, 'ajax_batch_refresh_courier']);

        // AJAX handler for bulk courier sync (fetches cached data from TansiqLabs DB)
        add_action('wp_ajax_guardify_courier_sync', [$this, 'ajax_courier_sync']);

        // AJAX handler for bulk fraud score update (old orders)
        add_action('wp_ajax_guardify_bulk_fraud_update', [$this, 'ajax_bulk_fraud_update']);

        // Enqueue
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // Add fraud score badge to order list column
        add_filter('manage_edit-shop_order_columns', [$this, 'add_list_column'], 15);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_list_column_legacy'], 10, 2);
        add_filter('woocommerce_shop_order_list_table_columns', [$this, 'add_list_column'], 15);
        add_action('woocommerce_shop_order_list_table_custom_column', [$this, 'render_list_column'], 10, 2);

        // Auto fraud check on new orders (universal fraud intelligence)
        add_action('woocommerce_new_order', [$this, 'auto_fraud_check_on_new_order'], 20, 2);
        add_action('woocommerce_checkout_order_created', [$this, 'auto_fraud_check_on_new_order'], 20, 2);

        // Add bulk update button above orders table
        add_action('manage_posts_extra_tablenav', [$this, 'render_bulk_update_button']);
        add_action('woocommerce_order_list_table_extra_tablenav', [$this, 'render_bulk_update_button']);
    }

    // ── Meta Box ──────────────────────────────────────────────────────────

    public function register_meta_box(): void {
        $screens = ['shop_order'];
        if (class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil') &&
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $screens[] = 'woocommerce_page_wc-orders';
        }

        foreach ($screens as $screen) {
            add_meta_box(
                'guardify_fraud_check',
                __('Guardify Fraud Check', 'guardify'),
                [$this, 'render_meta_box'],
                $screen,
                'side',
                'high'
            );
        }
    }

    public function render_meta_box($post_or_order): void {
        $order = $post_or_order instanceof \WC_Order
            ? $post_or_order
            : wc_get_order($post_or_order->ID ?? 0);

        if (!$order) {
            echo '<p class="guardify-fc-empty">Order not found.</p>';
            return;
        }

        $phone   = $order->get_billing_phone();
        $orderId = $order->get_id();

        if (empty($phone)) {
            echo '<p class="guardify-fc-empty">No billing phone number for this order.</p>';
            return;
        }

        // Check cache
        $cached = $this->get_cached_result($phone);
        ?>
        <div id="guardify-fraud-check-box" data-order-id="<?php echo esc_attr($orderId); ?>" data-phone="<?php echo esc_attr($phone); ?>">
            <?php if ($cached): ?>
                <?php $this->render_result_html($cached, $phone); ?>
            <?php else: ?>
                <div class="guardify-fc-loading">
                    <div class="guardify-fc-spinner"></div>
                    <p>Checking fraud risk for <strong><?php echo esc_html($phone); ?></strong>...</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── AJAX Handler ──────────────────────────────────────────────────────

    public function ajax_fraud_check(): void {
        check_ajax_referer('guardify_ajax_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $phone    = sanitize_text_field($_POST['phone'] ?? '');
        $order_id = intval($_POST['order_id'] ?? 0);

        if (empty($phone)) {
            wp_send_json_error(['message' => 'Phone number required']);
        }

        $result = $this->fetch_fraud_score($phone);

        if ($result === null) {
            wp_send_json_error(['message' => 'Could not connect to TansiqLabs API']);
        }

        // Cache
        $this->cache_result($phone, $result);

        $score = intval($result['score'] ?? 0);
        $risk  = $result['risk'] ?? 'low';

        // Persist to order meta if order_id provided
        if ($order_id > 0) {
            $this->save_score_to_order($order_id, $score, $risk);
        }

        // Return rendered HTML for the meta box
        ob_start();
        $this->render_result_html($result, $phone);
        $html = ob_get_clean();

        wp_send_json_success([
            'html'  => $html,
            'score' => $score,
            'risk'  => $risk,
        ]);
    }

    // ── API Communication ─────────────────────────────────────────────────

    /**
     * Auto fraud check when a new order is created.
     * Calls the universal TansiqLabs fraud check API and auto-fails orders
     * that exceed the site's configured threshold.
     *
     * @param int $order_id The order ID
     * @param \WC_Order|null $order The order object
     */
    /**
     * Static flag to track which orders have been processed in this request.
     * Prevents woocommerce_new_order + woocommerce_checkout_order_created
     * from both firing the API call within the same PHP process.
     * @var array<int, true>
     */
    private static $processed_orders = [];

    public function auto_fraud_check_on_new_order($order_id, $order = null): void {
        try {
            // PRIMARY GUARD: In-memory flag prevents double API call within same request
            // This is more reliable than meta check which may not be persisted yet
            $order_id = absint($order_id);
            if (isset(self::$processed_orders[$order_id])) return;
            self::$processed_orders[$order_id] = true;

            // Get order object if not passed
            if (!$order || !($order instanceof \WC_Order)) {
                $order = wc_get_order($order_id);
            }
            if (!$order) return;

            // SECONDARY GUARD: Persisted meta check prevents re-check on page reload/cron
            if ($order->get_meta('_guardify_fraud_score') !== '') return;

            // Get billing phone
            $phone = $order->get_billing_phone();
            if (empty($phone)) return;

            // Call universal fraud check API
            $result = $this->fetch_fraud_score($phone);
            if (!$result || empty($result['success'])) return;

            $score     = intval($result['score'] ?? 0);
            $risk      = $result['risk'] ?? 'low';
            $threshold = intval($result['threshold'] ?? 70);

            // Store fraud data as order meta
            $order->update_meta_data('_guardify_fraud_score', $score);
            $order->update_meta_data('_guardify_fraud_risk', $risk);
            $order->update_meta_data('_guardify_fraud_checked_at', current_time('mysql'));
            $order->save();

            // Cache courier data as phone-level transient (24h) so Score column can use global data
            if (!empty($result['courier'])) {
                $normalized_phone = preg_replace('/\D/', '', preg_replace('/^(?:\+?88)/', '', $phone));
                set_transient('guardify_courier_' . $normalized_phone, $result['courier'], DAY_IN_SECONDS);
            }

            // Cache the result
            $this->cache_result($phone, $result);

            // Auto-fail if score exceeds threshold
            if ($score >= $threshold && !in_array($order->get_status(), ['failed', 'cancelled', 'refunded'], true)) {
                $order->update_status(
                    'failed',
                    sprintf(
                        __('Guardify: Auto-blocked — Fraud score %d/100 (%s risk) exceeds threshold %d. Universal fraud intelligence detected threats across the network.', 'guardify'),
                        $score,
                        ucfirst($risk),
                        $threshold
                    )
                );

                // Add detailed note with signals
                $signals = $result['signals'] ?? [];
                if (!empty($signals)) {
                    $signal_text = array_map(function ($s) {
                        return '• ' . ($s['label'] ?? '') . ': ' . ($s['detail'] ?? '');
                    }, $signals);
                    $order->add_order_note(
                        __('Guardify Fraud Signals:', 'guardify') . "\n" . implode("\n", $signal_text)
                    );
                }
            } else if ($score > 0) {
                // Add informational note for non-zero scores
                $order->add_order_note(
                    sprintf(
                        __('Guardify: Fraud check complete — Score %d/100 (%s risk). Threshold: %d.', 'guardify'),
                        $score,
                        ucfirst($risk),
                        $threshold
                    )
                );
            }
        } catch (\Exception $e) {
            // Don't block order creation on fraud check failure
            error_log('Guardify Auto Fraud Check Error: ' . $e->getMessage());
        }
    }

    /**
     * Fetch fraud score / courier data from TansiqLabs API.
     *
     * If Steadfast data was already fetched locally (using client's own credentials),
     * pass it here so TansiqLabs can cache it in the DB and skip its own Steadfast call.
     *
     * @param string     $phone          Phone number
     * @param array|null $steadfast_data Pre-fetched Steadfast data from local call, or null
     * @return array|null API response body
     */
    private function fetch_fraud_score(string $phone, ?array $steadfast_data = null): ?array {
        $api_key = get_option('guardify_api_key', '');

        $payload = [
            'api_key' => $api_key,
            'phone'   => $phone,
        ];

        // If we already have Steadfast data from the client's own API call,
        // tell TansiqLabs to skip Steadfast and just cache what we send
        if ($steadfast_data) {
            $payload['skip_steadfast'] = true;
            $payload['steadfast_data'] = $steadfast_data;
        }

        $response = wp_remote_post(self::API_ENDPOINT, [
            'timeout'     => 15,
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => wp_json_encode($payload),
            'sslverify'   => true,
        ]);

        if (is_wp_error($response)) {
            error_log('Guardify Fraud Check Error: ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['success'])) {
            error_log('Guardify Fraud Check API Error: HTTP ' . $code . ' — ' . ($body['message'] ?? 'Unknown'));
            return null;
        }

        return $body;
    }

    // ── Save Score to Order Meta ──────────────────────────────────────────

    /**
     * Persist fraud score/risk to WooCommerce order meta for long-term storage.
     * This ensures the badge survives transient expiry.
     */
    private function save_score_to_order(int $order_id, int $score, string $risk): void {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $order->update_meta_data('_guardify_fraud_score', $score);
        $order->update_meta_data('_guardify_fraud_risk', $risk);
        $order->update_meta_data('_guardify_fraud_checked_at', current_time('mysql'));
        $order->save();
    }

    // ── Refresh Global Courier Data ──────────────────────────────────────

    /**
     * Fetch Steadfast courier data DIRECTLY using the client's own Steadfast API credentials.
     * This avoids the rate limit on our central TansiqLabs key — the client's merchant account
     * has much higher or no limits.
     *
     * Reads credentials from steadfast-api plugin options.
     *
     * @param string $phone Phone number (will be normalized)
     * @return array|null {provider, totalParcels, totalDelivered, totalCancelled, successRate, cancellationRate}
     */
    private function fetch_local_steadfast_data(string $phone): ?array {
        // Read client's own Steadfast API credentials (from steadfast-api plugin)
        $api_key    = get_option('api_settings_tab_api_key', '');
        $api_secret = get_option('api_settings_tab_api_secret_key', '');

        // Fallback: Try alternative option names that some setups use
        if (empty($api_key)) {
            $api_key = get_option('steadfast_api_key', '');
        }
        if (empty($api_secret)) {
            $api_secret = get_option('steadfast_secret_key', '');
        }

        if (empty($api_key) || empty($api_secret)) {
            return null; // No Steadfast credentials configured on this site
        }

        $normalized = preg_replace('/\D/', '', preg_replace('/^(?:\+?88)/', '', $phone));
        if (empty($normalized)) return null;

        $url = 'https://portal.packzy.com/api/v1/fraud_check/' . $normalized;

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'content-type' => 'application/json',
                'api-key'      => $api_key,
                'secret-key'   => $api_secret,
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('Guardify Steadfast Direct Error: ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 429) {
            error_log('Guardify Steadfast Rate Limit: ' . wp_remote_retrieve_body($response));
            return null;
        }

        if ($code !== 200 || !is_array($body)) {
            error_log('Guardify Steadfast Error: HTTP ' . $code);
            return null;
        }

        // Check for 401 (unauthorized)
        if (isset($body['status']) && (string) $body['status'] === '401') {
            error_log('Guardify Steadfast Auth Error: Invalid API credentials');
            return null;
        }

        $totalParcels   = intval($body['total_parcels'] ?? 0);
        $totalDelivered = intval($body['total_delivered'] ?? 0);
        $totalCancelled = intval($body['total_cancelled'] ?? 0);
        $successRate    = $totalParcels > 0 ? round(($totalDelivered / $totalParcels) * 100, 2) : 0;
        $cancelRate     = $totalParcels > 0 ? round(($totalCancelled / $totalParcels) * 100, 2) : 0;
        $fraudReports   = is_array($body['total_fraud_reports'] ?? null) ? count($body['total_fraud_reports']) : 0;

        return [
            'provider'         => 'Steadfast',
            'totalParcels'     => $totalParcels,
            'totalDelivered'   => $totalDelivered,
            'totalCancelled'   => $totalCancelled,
            'fraudReports'     => $fraudReports,
            'successRate'      => $successRate,
            'cancellationRate' => $cancelRate,
        ];
    }

    /**
     * AJAX handler: Fetch courier data for a phone number.
     *
     * FLOW:
     * 1. Call Steadfast DIRECTLY using client's own API credentials (no rate limit)
     * 2. Call TansiqLabs API for Pathao data + scoring + DB caching
     * 3. Merge both sources into a combined courier report
     * 4. Cache in WP transient + order meta for instant future renders
     */
    public function ajax_refresh_courier(): void {
        check_ajax_referer('guardify_ajax_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $phone = sanitize_text_field($_POST['phone'] ?? '');
        if (empty($phone)) {
            wp_send_json_error(['message' => 'Phone number required']);
        }

        $order_id = intval($_POST['order_id'] ?? 0);

        // Source 1: Fetch Steadfast data DIRECTLY using client's own API credentials
        $steadfast = $this->fetch_local_steadfast_data($phone);

        // Source 2: Fetch from TansiqLabs API (Pathao + scoring + DB cache)
        // Pass steadfast_data so TansiqLabs can cache it and skip its own Steadfast call
        $result = $this->fetch_fraud_score($phone, $steadfast);

        // Build combined courier data
        $sources = [];
        if ($steadfast) {
            $sources[] = $steadfast;
        }

        // Extract Pathao/other sources from TansiqLabs API response
        if ($result && !empty($result['success'])) {
            $api_sources = $result['courierSources'] ?? [];
            foreach ($api_sources as $src) {
                // Don't duplicate Steadfast if TansiqLabs also returned it
                if (isset($src['provider']) && strtolower($src['provider']) === 'steadfast' && $steadfast) {
                    continue;
                }
                $sources[] = $src;
            }
        }

        // Aggregate totals across all sources
        $courier = $this->aggregate_courier_sources($sources);

        if (empty($courier) || $courier['totalParcels'] === 0) {
            // Even with no data, return the empty result so the UI can show "No Data"
            wp_send_json_success([
                'courier' => $courier ?: ['totalParcels' => 0, 'totalDelivered' => 0, 'totalCancelled' => 0, 'successRate' => 0, 'cancellationRate' => 0, 'sources' => []],
                'score'   => intval($result['score'] ?? 0),
                'risk'    => $result['risk'] ?? 'low',
            ]);
            return;
        }

        // Cache courier data
        $normalized = preg_replace('/\D/', '', preg_replace('/^(?:\+?88)/', '', $phone));
        set_transient('guardify_courier_' . $normalized, $courier, DAY_IN_SECONDS);

        // Persist to order meta for instant rendering on next page load
        if ($order_id > 0) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_meta_data('_guardify_courier_data', $courier);
                $order->update_meta_data('_guardify_courier_updated', current_time('mysql'));
                $order->save();
            }
        }

        wp_send_json_success([
            'courier' => $courier,
            'score'   => intval($result['score'] ?? 0),
            'risk'    => $result['risk'] ?? 'low',
        ]);
    }

    /**
     * Aggregate multiple courier sources into a combined report.
     *
     * @param array $sources Array of per-provider data
     * @return array {totalParcels, totalDelivered, totalCancelled, successRate, cancellationRate, sources}
     */
    private function aggregate_courier_sources(array $sources): array {
        $totalParcels = 0;
        $totalDelivered = 0;
        $totalCancelled = 0;
        $totalFraud = 0;
        $cleanSources = [];

        foreach ($sources as $src) {
            $p = intval($src['totalParcels'] ?? 0);
            $d = intval($src['totalDelivered'] ?? 0);
            $c = intval($src['totalCancelled'] ?? 0);
            $f = intval($src['fraudReports'] ?? 0);

            $totalParcels   += $p;
            $totalDelivered += $d;
            $totalCancelled += $c;
            $totalFraud     += $f;

            $cleanSources[] = [
                'provider'         => $src['provider'] ?? 'Unknown',
                'totalParcels'     => $p,
                'totalDelivered'   => $d,
                'totalCancelled'   => $c,
                'fraudReports'     => $f,
                'successRate'      => $p > 0 ? round(($d / $p) * 100, 2) : 0,
                'cancellationRate' => $p > 0 ? round(($c / $p) * 100, 2) : 0,
            ];
        }

        $successRate = $totalParcels > 0 ? round(($totalDelivered / $totalParcels) * 100, 2) : 0;
        $cancelRate  = $totalParcels > 0 ? round(($totalCancelled / $totalParcels) * 100, 2) : 0;

        return [
            'totalParcels'     => $totalParcels,
            'totalDelivered'   => $totalDelivered,
            'totalCancelled'   => $totalCancelled,
            'fraudReports'     => $totalFraud,
            'successRate'      => $successRate,
            'cancellationRate' => $cancelRate,
            'sources'          => $cleanSources,
        ];
    }

    /**
     * Get cached global courier data for a phone number.
     * Returns null if no cached data available.
     *
     * @param string $phone Phone number
     * @return array|null Courier data from fraud-check API: {totalParcels, totalDelivered, totalCancelled, successRate, ...}
     */
    public static function get_cached_courier_data(string $phone): ?array {
        $normalized = preg_replace('/\D/', '', preg_replace('/^(?:\+?88)/', '', $phone));
        $data = get_transient('guardify_courier_' . $normalized);
        return is_array($data) ? $data : null;
    }

    // ── Courier Sync (bulk DB cache fetch) ─────────────────────────────

    /**
     * AJAX handler: Bulk-fetch cached courier data from TansiqLabs DB.
     * Returns ONLY data already cached in TansiqLabs PostgreSQL — no courier API calls.
     * For phones missing from the cache, returns null so the JS can do individual fetches.
     *
     * Request:  { nonce, phones: ['01...', ...], order_ids: {'01...': 123, ...} }
     * Response: { success: true, data: { results: {phone: {...}|null}, cached, missing } }
     */
    public function ajax_courier_sync(): void {
        check_ajax_referer('guardify_ajax_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $phones = isset($_POST['phones']) && is_array($_POST['phones'])
            ? array_map('sanitize_text_field', $_POST['phones'])
            : [];

        $order_ids = isset($_POST['order_ids']) && is_array($_POST['order_ids'])
            ? array_map('intval', $_POST['order_ids'])
            : [];

        if (empty($phones)) {
            wp_send_json_error(['message' => 'No phone numbers provided']);
        }

        // Limit to 50 phones
        $phones = array_slice(array_unique($phones), 0, 50);

        $api_key = get_option('guardify_api_key', '');
        if (empty($api_key)) {
            wp_send_json_error(['message' => 'Plugin not activated']);
        }

        // Call TansiqLabs courier-sync endpoint (DB-cached, no courier API calls)
        $response = wp_remote_post(self::COURIER_SYNC_ENDPOINT, [
            'timeout' => 15,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body' => wp_json_encode([
                'api_key' => $api_key,
                'phones'  => $phones,
            ]),
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Failed to connect to TansiqLabs: ' . $response->get_error_message()]);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['success'])) {
            wp_send_json_error(['message' => $body['message'] ?? 'Courier sync failed']);
        }

        $results = $body['results'] ?? [];

        // Save each result to WP transient AND order meta
        foreach ($results as $phone => $data) {
            if (!is_array($data) || empty($data['totalParcels'])) {
                continue;
            }

            $normalized = preg_replace('/\D/', '', preg_replace('/^(?:\+?88)/', '', $phone));
            
            // Save to WP transient (24h)
            set_transient('guardify_courier_' . $normalized, $data, DAY_IN_SECONDS);

            // Save to order meta if order_id is provided
            $order_id = $order_ids[$phone] ?? ($order_ids[$normalized] ?? 0);
            if ($order_id > 0) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $order->update_meta_data('_guardify_courier_data', $data);
                    $order->update_meta_data('_guardify_courier_updated', current_time('mysql'));
                    $order->save();
                }
            }
        }

        wp_send_json_success([
            'results' => $results,
            'cached'  => $body['cached'] ?? 0,
            'missing' => $body['missing'] ?? 0,
        ]);
    }

    // ── Batch Refresh Courier (auto-load after page paint) ─────────────

    /**
     * AJAX handler: Batch-fetch global courier data for multiple phones.
     * Called automatically after WC orders page loads to populate Score columns.
     * Fetches fraud data for each phone sequentially to avoid API overload.
     */
    public function ajax_batch_refresh_courier(): void {
        check_ajax_referer('guardify_ajax_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $phones = isset($_POST['phones']) && is_array($_POST['phones'])
            ? array_map('sanitize_text_field', $_POST['phones'])
            : [];

        if (empty($phones)) {
            wp_send_json_error(['message' => 'No phone numbers provided']);
        }

        // Limit to 20 phones per batch (server-side 6h courier cache makes this safe)
        $phones = array_slice(array_unique($phones), 0, 20);
        $results = [];

        foreach ($phones as $phone) {
            if (empty($phone)) continue;

            $normalized = preg_replace('/\D/', '', preg_replace('/^(?:\+?88)/', '', $phone));

            // Check local WP transient cache first — skip API call if already cached
            $cached = get_transient('guardify_courier_' . $normalized);
            if (is_array($cached) && !empty($cached['totalParcels'])) {
                $results[$phone] = $cached;
                continue;
            }

            // Fetch from Steadfast directly first, then TansiqLabs API
            $steadfast = $this->fetch_local_steadfast_data($phone);
            $result = $this->fetch_fraud_score($phone, $steadfast);

            // Merge sources
            $sources = [];
            if ($steadfast) $sources[] = $steadfast;
            if ($result && !empty($result['success'])) {
                foreach (($result['courierSources'] ?? []) as $src) {
                    if (isset($src['provider']) && strtolower($src['provider']) === 'steadfast' && $steadfast) continue;
                    $sources[] = $src;
                }
            }

            $courier = $this->aggregate_courier_sources($sources);

            if (!empty($courier) && $courier['totalParcels'] > 0) {
                set_transient('guardify_courier_' . $normalized, $courier, DAY_IN_SECONDS);
                $results[$phone] = $courier;
            } else {
                $results[$phone] = null;
            }
        }

        wp_send_json_success($results);
    }

    // ── Bulk Update Old Orders ────────────────────────────────────────────

    /**
     * AJAX handler: Bulk update fraud scores for orders missing scores.
     * Processes a batch (default 10) per AJAX call for progress tracking.
     */
    public function ajax_bulk_fraud_update(): void {
        check_ajax_referer('guardify_ajax_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $batch_size = intval($_POST['batch_size'] ?? 10);
        $offset     = intval($_POST['offset'] ?? 0);
        $mode       = sanitize_text_field($_POST['mode'] ?? 'missing'); // 'missing' or 'all'

        // Find orders that need scoring
        $args = [
            'limit'   => $batch_size,
            'offset'  => $offset,
            'orderby' => 'ID',
            'order'   => 'DESC',
            'return'  => 'ids',
        ];

        if ($mode === 'missing') {
            // Only orders without a fraud score
            $args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key'     => '_guardify_fraud_score',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'   => '_guardify_fraud_score',
                    'value' => '',
                ],
            ];
        }

        $order_ids = wc_get_orders($args);

        // Get total count for progress
        $count_args = $args;
        $count_args['limit']  = -1;
        $count_args['offset'] = 0;
        unset($count_args['return']);
        $count_args['return'] = 'ids';
        $total_orders = count(wc_get_orders($count_args));

        if (empty($order_ids)) {
            wp_send_json_success([
                'completed' => true,
                'processed' => 0,
                'updated'   => 0,
                'failed'    => 0,
                'total'     => $total_orders,
                'message'   => 'All orders have been scored.',
            ]);
            return;
        }

        $updated = 0;
        $failed  = 0;
        $results = [];

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                $failed++;
                continue;
            }

            $phone = $order->get_billing_phone();
            if (empty($phone)) {
                $failed++;
                continue;
            }

            // Call API
            $result = $this->fetch_fraud_score($phone);
            if ($result === null || empty($result['success'])) {
                $failed++;
                continue;
            }

            $score = intval($result['score'] ?? 0);
            $risk  = $result['risk'] ?? 'low';

            // Save to order meta
            $this->save_score_to_order($order_id, $score, $risk);

            // Cache
            $this->cache_result($phone, $result);

            $updated++;
            $results[] = [
                'order_id' => $order_id,
                'phone'    => $phone,
                'score'    => $score,
                'risk'     => $risk,
            ];

            // Small delay to avoid API rate limiting
            usleep(200000); // 200ms
        }

        wp_send_json_success([
            'completed' => count($order_ids) < $batch_size,
            'processed' => count($order_ids),
            'updated'   => $updated,
            'failed'    => $failed,
            'total'     => $total_orders,
            'offset'    => $offset + count($order_ids),
            'results'   => $results,
            'message'   => sprintf('Processed %d orders: %d updated, %d failed.', count($order_ids), $updated, $failed),
        ]);
    }

    /**
     * Render bulk update button above the orders table
     */
    public function render_bulk_update_button($which = ''): void {
        $screen = get_current_screen();
        if (!$screen) return;
        
        // Only show on order list pages
        $valid_screens = ['edit-shop_order', 'woocommerce_page_wc-orders'];
        if (!in_array($screen->id, $valid_screens, true)) return;
        
        // Only show on top tablenav
        if ($which !== 'top' && $which !== '') return;
        ?>
        <button type="button" id="guardify-bulk-fraud-update" class="button" style="margin-left: 8px;" title="Update fraud scores for all orders">
            🛡️ Update All Scores
        </button>
        <span id="guardify-bulk-fraud-progress" style="display:none; margin-left: 8px; font-size: 12px; color: #666;"></span>
        <?php
    }

    // ── Cache ─────────────────────────────────────────────────────────────

    private function cache_result(string $phone, array $result): void {
        $key = 'guardify_fc_' . md5($phone);
        set_transient($key, $result, self::CACHE_TTL);
    }

    private function get_cached_result(string $phone): ?array {
        $key   = 'guardify_fc_' . md5($phone);
        $cached = get_transient($key);
        return is_array($cached) ? $cached : null;
    }

    // ── Render: Meta Box HTML ─────────────────────────────────────────────

    private function render_result_html(array $result, string $phone): void {
        $score   = intval($result['score'] ?? 0);
        $risk    = $result['risk'] ?? 'low';
        $signals = $result['signals'] ?? [];
        $summary = $result['summary'] ?? [];
        $history = $result['history'] ?? [];
        $courier = $result['courier'] ?? [];

        $risk_labels = [
            'low'      => 'Low Risk',
            'medium'   => 'Medium Risk',
            'high'     => 'High Risk',
            'critical' => 'Critical Risk',
        ];
        $risk_colors = [
            'low'      => '#22c55e',
            'medium'   => '#f59e0b',
            'high'     => '#f97316',
            'critical' => '#ef4444',
        ];

        $color = $risk_colors[$risk] ?? '#22c55e';
        $label = $risk_labels[$risk] ?? 'Unknown';
        ?>
        <div class="guardify-fc-result" data-risk="<?php echo esc_attr($risk); ?>">
            <!-- Score ring -->
            <div class="guardify-fc-score-ring">
                <svg viewBox="0 0 80 80" class="guardify-fc-ring-svg">
                    <circle cx="40" cy="40" r="34" fill="none" stroke-width="6" stroke="#e5e7eb" opacity="0.2"/>
                    <circle cx="40" cy="40" r="34" fill="none" stroke-width="6"
                            stroke="<?php echo esc_attr($color); ?>"
                            stroke-linecap="round"
                            stroke-dasharray="<?php echo esc_attr(($score / 100) * 213.6); ?> 213.6"
                            transform="rotate(-90 40 40)"/>
                </svg>
                <div class="guardify-fc-ring-value" style="color:<?php echo esc_attr($color); ?>"><?php echo esc_html($score); ?></div>
            </div>

            <div class="guardify-fc-risk-label" style="color:<?php echo esc_attr($color); ?>">
                <?php echo esc_html($label); ?>
            </div>
            <div class="guardify-fc-phone"><?php echo esc_html($phone); ?></div>

            <!-- Verdict Summary -->
            <?php $verdict = $summary['verdict'] ?? ''; ?>
            <?php if (!empty($verdict)): ?>
                <div class="guardify-fc-verdict" style="background:<?php echo esc_attr($color); ?>10;border:1px solid <?php echo esc_attr($color); ?>30;border-radius:8px;padding:10px 14px;margin:8px 0 12px;font-size:13px;line-height:1.5;color:#374151">
                    <strong style="color:<?php echo esc_attr($color); ?>">⚡ Verdict:</strong>
                    <?php echo esc_html($verdict); ?>
                </div>
            <?php endif; ?>

            <!-- Courier Delivery Stats -->
            <?php
            $c_parcels   = intval($courier['totalParcels'] ?? 0);
            $c_delivered  = intval($courier['totalDelivered'] ?? 0);
            $c_cancelled  = intval($courier['totalCancelled'] ?? 0);
            $c_success    = intval($courier['successRate'] ?? 0);
            $c_cancel_rate = intval($courier['cancellationRate'] ?? 0);
            ?>
            <div class="guardify-fc-courier">
                <?php
                $c_sources = $courier['sources'] ?? [];
                $source_names = !empty($c_sources) ? implode(', ', array_map(function($s) { return $s['provider'] ?? ''; }, $c_sources)) : 'No Courier';
                ?>
                <h4 class="guardify-fc-section-title">📦 Courier History (<?php echo esc_html($source_names); ?>)</h4>
                <div class="guardify-fc-courier-stats">
                    <div class="guardify-fc-courier-stat">
                        <span class="guardify-fc-courier-val"><?php echo $c_parcels; ?></span>
                        <span class="guardify-fc-courier-lbl">Total</span>
                    </div>
                    <div class="guardify-fc-courier-stat">
                        <span class="guardify-fc-courier-val" style="color:#22c55e"><?php echo $c_delivered; ?></span>
                        <span class="guardify-fc-courier-lbl">Delivered</span>
                    </div>
                    <div class="guardify-fc-courier-stat">
                        <span class="guardify-fc-courier-val" style="color:<?php echo $c_cancelled > 0 ? '#ef4444' : '#71717a'; ?>"><?php echo $c_cancelled; ?></span>
                        <span class="guardify-fc-courier-lbl">Cancelled</span>
                    </div>
                    <div class="guardify-fc-courier-stat">
                        <span class="guardify-fc-courier-val" style="color:<?php echo $c_success >= 80 ? '#22c55e' : ($c_success >= 50 ? '#f59e0b' : '#ef4444'); ?>"><?php echo $c_success; ?>%</span>
                        <span class="guardify-fc-courier-lbl">Success</span>
                    </div>
                </div>
                <?php if ($c_parcels > 0): ?>
                    <div class="guardify-fc-courier-bar">
                        <?php if ($c_delivered > 0): ?>
                            <div class="guardify-fc-courier-bar-fill guardify-fc-courier-bar-delivered" style="width:<?php echo $c_success; ?>%" title="<?php echo $c_delivered; ?> delivered"></div>
                        <?php endif; ?>
                        <?php if ($c_cancelled > 0): ?>
                            <div class="guardify-fc-courier-bar-fill guardify-fc-courier-bar-cancelled" style="width:<?php echo $c_cancel_rate; ?>%" title="<?php echo $c_cancelled; ?> cancelled"></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Quick stats -->
            <div class="guardify-fc-stats">
                <div class="guardify-fc-stat">
                    <span class="guardify-fc-stat-val"><?php echo intval($summary['totalEvents'] ?? 0); ?></span>
                    <span class="guardify-fc-stat-lbl">Events</span>
                </div>
                <div class="guardify-fc-stat">
                    <span class="guardify-fc-stat-val"><?php echo intval($summary['blockedEvents'] ?? 0); ?></span>
                    <span class="guardify-fc-stat-lbl">Blocked</span>
                </div>
                <div class="guardify-fc-stat">
                    <span class="guardify-fc-stat-val"><?php echo intval($summary['uniqueSites'] ?? 0); ?></span>
                    <span class="guardify-fc-stat-lbl">Sites</span>
                </div>
            </div>

            <!-- Signals -->
            <?php if (!empty($signals)): ?>
                <div class="guardify-fc-signals">
                    <h4 class="guardify-fc-section-title">Risk Signals</h4>
                    <?php foreach ($signals as $signal): ?>
                        <?php
                        $sev_colors = [
                            'low'      => '#22c55e',
                            'medium'   => '#f59e0b',
                            'high'     => '#f97316',
                            'critical' => '#ef4444',
                        ];
                        $sc = $sev_colors[$signal['severity'] ?? 'low'] ?? '#6b7280';
                        ?>
                        <div class="guardify-fc-signal" style="border-left-color:<?php echo esc_attr($sc); ?>">
                            <div class="guardify-fc-signal-header">
                                <span class="guardify-fc-signal-label"><?php echo esc_html($signal['label'] ?? ''); ?></span>
                                <span class="guardify-fc-signal-badge" style="background:<?php echo esc_attr($sc); ?>20;color:<?php echo esc_attr($sc); ?>">
                                    <?php echo esc_html(ucfirst($signal['severity'] ?? '')); ?>
                                </span>
                            </div>
                            <p class="guardify-fc-signal-detail"><?php echo esc_html($signal['detail'] ?? ''); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- History (collapsible) -->
            <?php if (!empty($history)): ?>
                <details class="guardify-fc-history">
                    <summary class="guardify-fc-section-title guardify-fc-toggle">
                        Event History (<?php echo count($history); ?>)
                    </summary>
                    <div class="guardify-fc-history-list">
                        <?php foreach (array_slice($history, 0, 10) as $event): ?>
                            <?php
                            $lvl_colors = ['LOW' => '#6b7280', 'MEDIUM' => '#f59e0b', 'HIGH' => '#f97316', 'CRITICAL' => '#ef4444'];
                            $ec = $lvl_colors[$event['threatLevel'] ?? 'LOW'] ?? '#6b7280';
                            ?>
                            <div class="guardify-fc-event">
                                <span class="guardify-fc-event-dot" style="background:<?php echo esc_attr($ec); ?>"></span>
                                <div class="guardify-fc-event-body">
                                    <span class="guardify-fc-event-type">
                                        <?php echo esc_html(ucwords(str_replace('_', ' ', $event['eventType'] ?? ''))); ?>
                                    </span>
                                    <span class="guardify-fc-event-meta">
                                        <?php echo esc_html($event['sourceIp'] ?? ''); ?>
                                        · <?php echo esc_html($event['site'] ?? ''); ?>
                                        · <?php echo $event['blocked'] ? '<span style="color:#22c55e">Blocked</span>' : '<span style="color:#ef4444">Allowed</span>'; ?>
                                    </span>
                                </div>
                                <span class="guardify-fc-event-time">
                                    <?php
                                    $ts = strtotime($event['date'] ?? 'now');
                                    echo esc_html(human_time_diff($ts, time()) . ' ago');
                                    ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>

            <!-- Refresh button -->
            <button type="button" class="guardify-fc-refresh" onclick="guardifyRefreshFraudCheck()">
                ↻ Refresh
            </button>
        </div>
        <?php
    }

    // ── Order List Column ─────────────────────────────────────────────────

    public function add_list_column(array $columns): array {
        $new = [];
        foreach ($columns as $key => $val) {
            $new[$key] = $val;
            if ($key === 'order_number') {
                $new['guardify_fraud_risk'] = __('Fraud Risk', 'guardify');
            }
        }
        return $new;
    }

    public function render_list_column(string $column, $order): void {
        if ($column !== 'guardify_fraud_risk') return;
        $order_id = is_object($order) ? $order->get_id() : $order;
        if (!is_object($order)) $order = wc_get_order($order_id);
        if (!$order) return;
        $this->render_fraud_badge($order);
    }

    public function render_list_column_legacy(string $column, int $post_id): void {
        if ($column !== 'guardify_fraud_risk') return;
        $order = wc_get_order($post_id);
        if (!$order) return;
        $this->render_fraud_badge($order);
    }

    private function render_fraud_badge($order): void {
        $phone = $order->get_billing_phone();
        if (empty($phone)) {
            echo '<span class="guardify-fc-badge guardify-fc-badge--na" title="No phone">—</span>';
            return;
        }

        // Priority 1: Persistent order meta (survives transient expiry)
        $meta_score = $order->get_meta('_guardify_fraud_score');
        $meta_risk  = $order->get_meta('_guardify_fraud_risk');
        if ($meta_score !== '' && $meta_score !== false) {
            $score = intval($meta_score);
            $risk  = !empty($meta_risk) ? $meta_risk : 'low';
            $title = ucfirst($risk) . ' Risk — Score: ' . $score . '/100';
            echo '<span class="guardify-fc-badge guardify-fc-badge--' . esc_attr($risk) . '" title="' . esc_attr($title) . '">' . esc_html($score) . '</span>';
            return;
        }

        // Priority 2: Transient cache (short-lived)
        $cached = $this->get_cached_result($phone);
        if ($cached) {
            $score = intval($cached['score'] ?? 0);
            $risk  = $cached['risk'] ?? 'low';
            $title = ucfirst($risk) . ' Risk — Score: ' . $score . '/100';
            echo '<span class="guardify-fc-badge guardify-fc-badge--' . esc_attr($risk) . '" title="' . esc_attr($title) . '">' . esc_html($score) . '</span>';
            return;
        }

        // Priority 3: Not checked yet
        echo '<span class="guardify-fc-badge guardify-fc-badge--pending" title="Click to check" data-phone="' . esc_attr($phone) . '" data-order-id="' . esc_attr($order->get_id()) . '">?</span>';
    }

    // ── Assets ────────────────────────────────────────────────────────────

    public function enqueue_assets(string $hook): void {
        // Load only on WooCommerce order screens (list + single order)
        $screen = get_current_screen();
        if (!$screen) return;
        
        $is_order_screen = in_array($screen->id, [
            'edit-shop_order',
            'shop_order',
            'woocommerce_page_wc-orders',
        ], true);

        if (!$is_order_screen) return;

        wp_enqueue_style(
            'guardify-fraud-check',
            GUARDIFY_PLUGIN_URL . 'assets/css/fraud-check.css',
            [],
            GUARDIFY_VERSION
        );

        wp_enqueue_script(
            'guardify-fraud-check',
            GUARDIFY_PLUGIN_URL . 'assets/js/fraud-check.js',
            ['jquery'],
            GUARDIFY_VERSION,
            true
        );

        // Use wp_add_inline_script instead of wp_localize_script
        // This is immune to LiteSpeed Cache JS combination/reordering
        wp_add_inline_script('guardify-fraud-check',
            'var guardifyFraudCheck = ' . wp_json_encode([
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('guardify_ajax_nonce'),
            ]) . ';',
            'before'
        );
    }
}
