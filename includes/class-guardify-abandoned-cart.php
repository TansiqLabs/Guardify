<?php
/**
 * Guardify Incomplete Orders Handler
 *
 * Tracks incomplete WooCommerce checkout forms using a custom database table.
 * Inspired by OrderGuard's simple and reliable approach:
 *  - Custom DB table (not WC orders) — lightweight, no order list pollution
 *  - Captures via woocommerce_checkout_update_order_review + AJAX fallback
 *  - Phone-centric deduplication (BD market)
 *  - Cooldown system to prevent duplicates
 *  - Cart data stored as JSON with variation details
 *  - Admin page: list, delete, export, convert to WC order
 *
 * @package Guardify
 * @since 1.2.3
 */

if (!defined('ABSPATH')) {
    exit;
}

class Guardify_Abandoned_Cart {

    /** Singleton */
    private static ?self $instance = null;

    /** Custom DB table name (set in constructor) */
    private string $table_name;

    /** DB version for schema upgrades */
    private const DB_VERSION = '1.0';

    // =========================================================================
    // SINGLETON + CONSTRUCTOR
    // =========================================================================

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'guardify_incomplete_orders';

        // Create / upgrade table
        $this->maybe_create_table();

        if (!$this->is_enabled()) {
            return;
        }

        // ─── Frontend: capture checkout data ───
        add_action('woocommerce_checkout_update_order_review', [$this, 'capture_on_review_update']);
        add_action('wp_ajax_guardify_capture_checkout', [$this, 'ajax_capture_checkout']);
        add_action('wp_ajax_nopriv_guardify_capture_checkout', [$this, 'ajax_capture_checkout']);

        // ─── Frontend: enqueue JS ───
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        // ─── Mark recovered when real order placed ───
        add_action('woocommerce_thankyou', [$this, 'mark_recovered_on_thankyou']);
        add_action('woocommerce_payment_complete', [$this, 'mark_recovered_on_thankyou']);
        add_action('woocommerce_order_status_changed', [$this, 'handle_order_status_change'], 10, 3);

        // ─── Admin: AJAX handlers ───
        add_action('wp_ajax_guardify_delete_incomplete', [$this, 'ajax_delete_incomplete']);
        add_action('wp_ajax_guardify_bulk_delete_incomplete', [$this, 'ajax_bulk_delete_incomplete']);
        add_action('wp_ajax_guardify_export_incomplete', [$this, 'ajax_export_incomplete']);
        add_action('wp_ajax_guardify_convert_to_order', [$this, 'ajax_convert_to_order']);

        // ─── Scheduled cleanup ───
        if (!wp_next_scheduled('guardify_cleanup_incomplete_orders')) {
            wp_schedule_event(time(), 'daily', 'guardify_cleanup_incomplete_orders');
        }
        add_action('guardify_cleanup_incomplete_orders', [$this, 'cleanup_old_records']);
    }

    // =========================================================================
    // SETTINGS CHECK
    // =========================================================================

    public function is_enabled(): bool {
        return get_option('guardify_abandoned_cart_enabled', '1') === '1';
    }

    // =========================================================================
    // DATABASE TABLE
    // =========================================================================

    private function maybe_create_table(): void {
        $installed_ver = get_option('guardify_incomplete_db_version', '0');
        if ($installed_ver === self::DB_VERSION) {
            return;
        }

        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(255)    NULL,
            phone       VARCHAR(30)     NOT NULL DEFAULT '',
            email       VARCHAR(255)    NULL,
            address     TEXT            NULL,
            city        VARCHAR(100)    NULL,
            state       VARCHAR(100)    NULL,
            country     VARCHAR(10)     NULL,
            postcode    VARCHAR(20)     NULL,
            cart_data   LONGTEXT        NULL,
            created_at  DATETIME        NOT NULL,
            status      VARCHAR(20)     NOT NULL DEFAULT 'pending',
            PRIMARY KEY (id),
            KEY idx_phone_status (phone, status),
            KEY idx_status_created (status, created_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('guardify_incomplete_db_version', self::DB_VERSION);
    }

    /**
     * Drop the table (called on plugin uninstall)
     */
    public static function drop_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'guardify_incomplete_orders';
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
        delete_option('guardify_incomplete_db_version');
    }

    // =========================================================================
    // FRONTEND: ENQUEUE SCRIPTS
    // =========================================================================

    public function enqueue_scripts(): void {
        if (!$this->is_checkout_page()) {
            return;
        }

        wp_enqueue_script(
            'guardify-incomplete-orders',
            GUARDIFY_PLUGIN_URL . 'assets/js/abandoned-cart.js',
            ['jquery'],
            GUARDIFY_VERSION,
            true
        );

        wp_localize_script('guardify-incomplete-orders', 'guardify_incomplete', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('guardify-incomplete-nonce'),
        ]);
    }

    /**
     * Detect checkout page (WooCommerce standard + CartFlows)
     */
    private function is_checkout_page(): bool {
        if (function_exists('is_checkout') && is_checkout()) {
            return true;
        }

        // CartFlows checkout detection
        global $post;
        if ($post) {
            $post_content = $post->post_content ?? '';
            if (
                has_shortcode($post_content, 'cartflows_checkout') ||
                has_shortcode($post_content, 'woocommerce_checkout')
            ) {
                return true;
            }

            // CartFlows stores type in post meta
            $cf_type = get_post_meta($post->ID, 'wcf-step-type', true);
            if ($cf_type === 'checkout') {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // CAPTURE: woocommerce_checkout_update_order_review hook
    // =========================================================================

    /**
     * Capture incomplete order when WooCommerce fires checkout update.
     * This hook fires every time checkout form fields are updated.
     */
    public function capture_on_review_update($post_data): void {
        parse_str($post_data, $data);

        $phone = $this->extract_phone($data);
        if (!$phone) {
            return; // No valid phone → skip
        }

        $this->save_incomplete_order($phone, $data);
    }

    // =========================================================================
    // CAPTURE: AJAX handler (fallback from JS)
    // =========================================================================

    public function ajax_capture_checkout(): void {
        // Verify nonce
        if (!check_ajax_referer('guardify-incomplete-nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed'], 403);
            return;
        }

        $phone = $this->extract_phone($_POST);
        if (!$phone) {
            wp_send_json_error(['message' => 'Invalid or missing phone number']);
            return;
        }

        $result = $this->save_incomplete_order($phone, $_POST);

        if ($result === 'cooldown') {
            wp_send_json_success(['message' => 'Phone in cooldown period', 'status' => 'cooldown']);
        } elseif ($result === 'existing') {
            wp_send_json_success(['message' => 'Already tracked', 'status' => 'existing']);
        } elseif ($result === 'new') {
            wp_send_json_success(['message' => 'Incomplete order captured', 'status' => 'new']);
        } else {
            wp_send_json_error(['message' => 'Failed to save']);
        }
    }

    // =========================================================================
    // CORE: Save Incomplete Order
    // =========================================================================

    /**
     * Save or update an incomplete order entry.
     *
     * @return string 'new'|'existing'|'cooldown'|'error'
     */
    private function save_incomplete_order(string $phone, array $data): string {
        global $wpdb;

        // ─── Cooldown check ───
        $cooldown_enabled = get_option('guardify_incomplete_cooldown_enabled', '1');
        if ($cooldown_enabled === '1') {
            $cooldown_minutes = (int) get_option('guardify_incomplete_cooldown_minutes', '30');
            if ($cooldown_minutes > 0 && $this->is_phone_in_cooldown($phone, $cooldown_minutes)) {
                return 'cooldown';
            }
        }

        // ─── Collect cart data ───
        $cart_data = $this->collect_cart_data();

        // ─── Check if this phone already has a pending entry ───
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE phone = %s AND status = 'pending'",
            $phone
        ));

        if ($existing) {
            // Update cart data + fields for existing entry
            $update = [];
            $name = $this->sanitize_field($data, 'billing_first_name');
            if ($name) {
                $update['name'] = $name;
            }
            $email = $this->sanitize_field($data, 'billing_email');
            if ($email && is_email($email)) {
                $update['email'] = sanitize_email($email);
            }
            $address = $this->sanitize_field($data, 'billing_address_1');
            if ($address) {
                $update['address'] = $address;
            }
            $city = $this->sanitize_field($data, 'billing_city');
            if ($city) {
                $update['city'] = $city;
            }
            $state = $this->sanitize_field($data, 'billing_state');
            if ($state) {
                $update['state'] = $state;
            }
            $country = $this->sanitize_field($data, 'billing_country');
            if ($country) {
                $update['country'] = $country;
            }
            $postcode = $this->sanitize_field($data, 'billing_postcode');
            if ($postcode) {
                $update['postcode'] = $postcode;
            }
            if (!empty($cart_data)) {
                $update['cart_data'] = $cart_data;
            }

            if (!empty($update)) {
                $wpdb->update(
                    $this->table_name,
                    $update,
                    ['phone' => $phone, 'status' => 'pending']
                );
            }

            return 'existing';
        }

        // ─── Insert new entry ───
        $insert_data = [
            'name'       => $this->sanitize_field($data, 'billing_first_name'),
            'phone'      => $phone,
            'email'      => is_email($this->sanitize_field($data, 'billing_email') ?: '') ? sanitize_email($data['billing_email']) : '',
            'address'    => $this->sanitize_field($data, 'billing_address_1'),
            'city'       => $this->sanitize_field($data, 'billing_city'),
            'state'      => $this->sanitize_field($data, 'billing_state'),
            'country'    => $this->sanitize_field($data, 'billing_country'),
            'postcode'   => $this->sanitize_field($data, 'billing_postcode'),
            'cart_data'  => $cart_data ?: '',
            'created_at' => current_time('mysql'),
            'status'     => 'pending',
        ];

        $result = $wpdb->insert($this->table_name, $insert_data);

        if ($result) {
            $insert_id = $wpdb->insert_id;

            // ─── Fire action for Discord notifications ───
            do_action('guardify_incomplete_order_captured', $insert_id, $insert_data);

            return 'new';
        }

        return 'error';
    }

    // =========================================================================
    // PHONE VALIDATION (BD market)
    // =========================================================================

    /**
     * Extract and validate a Bangladeshi phone number from form data.
     */
    private function extract_phone(array $data): string {
        $raw = isset($data['billing_phone']) ? trim($data['billing_phone']) : '';
        if (empty($raw)) {
            $raw = isset($data['phone']) ? trim($data['phone']) : '';
        }

        if (empty($raw)) {
            return '';
        }

        // Normalize: strip +88 / 88 prefix, keep 01XXXXXXXXX
        $normalized = preg_replace('/^(?:\+?88)/', '', $raw);

        // Valid BD mobile: 01[3-9] + 8 digits = 11 digits
        if (preg_match('/^01[3-9]\d{8}$/', $normalized)) {
            return $normalized;
        }

        return '';
    }

    /**
     * Public phone validation (used by other classes)
     */
    public function validate_phone_number(string $phone): bool {
        return (bool) preg_match('/^(?:\+?88)?01[3-9]\d{8}$/', $phone);
    }

    // =========================================================================
    // COOLDOWN SYSTEM
    // =========================================================================

    /**
     * Check if a phone is in cooldown (recent order placed or recently recovered).
     */
    private function is_phone_in_cooldown(string $phone, int $cooldown_minutes): bool {
        global $wpdb;

        // Normalize phone variations
        $normalized = preg_replace('/^(?:\+?88)/', '', $phone);
        $variations = [
            $normalized,
            '88' . $normalized,
            '+88' . $normalized,
        ];

        // Cookie check (fastest)
        $phone_hash = md5($normalized);
        if (isset($_COOKIE['guardify_completed_' . $phone_hash])) {
            return true;
        }

        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$cooldown_minutes} minutes"));

        // 1. Check recent WooCommerce orders (HPOS compatible)
        $recent_wc = wc_get_orders([
            'billing_phone' => $variations,
            'status'        => ['wc-processing', 'wc-completed', 'wc-on-hold'],
            'date_created'  => '>' . $cutoff,
            'limit'         => 1,
            'return'        => 'ids',
        ]);
        if (!empty($recent_wc)) {
            return true;
        }

        // 2. Check recently recovered entries in our table
        $placeholders = implode(',', array_fill(0, count($variations), '%s'));
        $values = array_merge($variations, [$cutoff]);

        $recovered = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name}
             WHERE phone IN ({$placeholders})
             AND status = 'recovered'
             AND created_at > %s",
            ...$values
        ));

        if ($recovered > 0) {
            return true;
        }

        return false;
    }

    // =========================================================================
    // CART DATA COLLECTION
    // =========================================================================

    /**
     * Collect current WooCommerce cart items as JSON.
     */
    private function collect_cart_data(): string {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return '';
        }

        $items = [];
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if (!$product) {
                continue;
            }

            $product_name = $product->get_name();
            $variation_id = $cart_item['variation_id'] ?? 0;
            $variation_attributes = [];

            // Append variation details (Size, Color, etc.)
            if ($variation_id > 0 && !empty($cart_item['variation'])) {
                foreach ($cart_item['variation'] as $att_key => $att_value) {
                    $taxonomy = str_replace('attribute_', '', $att_key);
                    $term_name = $att_value;

                    if (taxonomy_exists($taxonomy)) {
                        $term = get_term_by('slug', $att_value, $taxonomy);
                        if ($term && !is_wp_error($term)) {
                            $term_name = $term->name;
                        }
                    }

                    $label = wc_attribute_label($taxonomy);
                    $variation_attributes[] = $label . ': ' . $term_name;
                }

                if (!empty($variation_attributes)) {
                    $product_name .= ' (' . implode(', ', $variation_attributes) . ')';
                }
            }

            $items[] = [
                'product_id'           => $cart_item['product_id'],
                'variation_id'         => $variation_id,
                'name'                 => $product_name,
                'price'                => $product->get_price(),
                'quantity'             => $cart_item['quantity'],
                'variation_attributes' => $variation_attributes,
            ];
        }

        return !empty($items) ? wp_json_encode($items) : '';
    }

    // =========================================================================
    // RECOVERY: Mark as recovered when real order placed
    // =========================================================================

    public function mark_recovered_on_thankyou($order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $phone = $order->get_billing_phone();
        $this->mark_recovered_by_phone($phone);
    }

    public function handle_order_status_change($order_id, $old_status, $new_status): void {
        $recovery_statuses = ['processing', 'completed', 'on-hold'];
        if (!in_array($new_status, $recovery_statuses, true)) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $phone = $order->get_billing_phone();
        $this->mark_recovered_by_phone($phone);
    }

    private function mark_recovered_by_phone(string $raw_phone): void {
        if (empty($raw_phone)) {
            return;
        }

        $normalized = preg_replace('/^(?:\+?88)/', '', trim($raw_phone));
        if (!preg_match('/^01[3-9]\d{8}$/', $normalized)) {
            return;
        }

        global $wpdb;

        // Mark all pending entries for this phone as recovered
        $variations = [$normalized, '88' . $normalized, '+88' . $normalized];
        $placeholders = implode(',', array_fill(0, count($variations), '%s'));

        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_name}
             SET status = 'recovered'
             WHERE phone IN ({$placeholders})
             AND status = 'pending'",
            ...$variations
        ));

        // Set cooldown cookie
        $cooldown_enabled = get_option('guardify_incomplete_cooldown_enabled', '1');
        if ($cooldown_enabled === '1') {
            $cooldown_minutes = (int) get_option('guardify_incomplete_cooldown_minutes', '30');
            if ($cooldown_minutes > 0) {
                $phone_hash = md5($normalized);
                $expiry = time() + ($cooldown_minutes * 60);

                if (!headers_sent()) {
                    setcookie('guardify_completed_' . $phone_hash, '1', $expiry, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
                }
                $_COOKIE['guardify_completed_' . $phone_hash] = '1';
            }
        }
    }

    // =========================================================================
    // ADMIN: Get incomplete orders
    // =========================================================================

    /**
     * Get pending incomplete orders for admin display.
     *
     * @param int $limit  Records per page
     * @param int $offset Pagination offset
     * @return array
     */
    public function get_incomplete_orders(int $limit = 20, int $offset = 0): array {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE status = 'pending'
             ORDER BY id DESC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));

        // Process cart data for display
        foreach ($results as &$row) {
            $row->raw_timestamp = $row->created_at;
            $row->created_at_formatted = $this->format_timestamp($row->created_at);
            $row->time_ago = $this->human_time_diff($row->created_at);
            $row->products = [];
            $row->total = 0;

            $cart = !empty($row->cart_data) ? json_decode($row->cart_data, true) : null;

            if ($cart && is_array($cart)) {
                foreach ($cart as $item) {
                    $price = (float) ($item['price'] ?? 0);
                    $qty   = (int) ($item['quantity'] ?? 1);

                    $row->products[] = [
                        'product_id'   => $item['product_id'] ?? 0,
                        'variation_id' => $item['variation_id'] ?? 0,
                        'name'         => $item['name'] ?? 'Unknown Product',
                        'price'        => $price,
                        'quantity'     => $qty,
                    ];

                    $row->total += $price * $qty;
                }
            }

            if (empty($row->products)) {
                $row->products[] = [
                    'product_id'   => 0,
                    'variation_id' => 0,
                    'name'         => __('Product details not available', 'guardify'),
                    'price'        => 0,
                    'quantity'     => 1,
                ];
            }
        }

        return $results;
    }

    /**
     * Count all pending incomplete orders.
     */
    public function get_incomplete_orders_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'pending'"
        );
    }

    // =========================================================================
    // ADMIN: AJAX — Delete
    // =========================================================================

    public function ajax_delete_incomplete(): void {
        check_ajax_referer('guardify-incomplete-nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }

        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error('Invalid ID');
        }

        global $wpdb;
        $deleted = $wpdb->delete($this->table_name, ['id' => $id], ['%d']);

        if ($deleted) {
            wp_send_json_success(['message' => 'Deleted successfully']);
        } else {
            wp_send_json_error('Delete failed');
        }
    }

    public function ajax_bulk_delete_incomplete(): void {
        check_ajax_referer('guardify-incomplete-nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }

        $ids = isset($_POST['ids']) ? array_map('intval', (array) $_POST['ids']) : [];
        if (empty($ids)) {
            wp_send_json_error('No IDs provided');
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE id IN ({$placeholders})",
            ...$ids
        ));

        wp_send_json_success(['message' => sprintf('%d record(s) deleted', $deleted)]);
    }

    // =========================================================================
    // ADMIN: AJAX — Export CSV
    // =========================================================================

    public function ajax_export_incomplete(): void {
        check_ajax_referer('guardify-incomplete-nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Permission denied');
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} WHERE status = 'pending' ORDER BY id DESC",
            ARRAY_A
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=guardify-incomplete-orders-' . gmdate('Y-m-d') . '.csv');

        $out = fopen('php://output', 'w');
        // Header row
        fputcsv($out, ['ID', 'Name', 'Phone', 'Email', 'Address', 'City', 'State', 'Country', 'Postcode', 'Products', 'Total', 'Date', 'Status']);

        foreach ($rows as $row) {
            $cart = json_decode($row['cart_data'], true);
            $products = '';
            $total = 0;
            if ($cart && is_array($cart)) {
                $parts = [];
                foreach ($cart as $item) {
                    $price = (float) ($item['price'] ?? 0);
                    $qty = (int) ($item['quantity'] ?? 1);
                    $parts[] = ($item['name'] ?? 'Unknown') . ' x' . $qty . ' @ ' . $price;
                    $total += $price * $qty;
                }
                $products = implode('; ', $parts);
            }

            fputcsv($out, [
                $row['id'],
                $row['name'],
                $row['phone'],
                $row['email'],
                $row['address'],
                $row['city'],
                $row['state'],
                $row['country'],
                $row['postcode'],
                $products,
                $total,
                $row['created_at'],
                $row['status'],
            ]);
        }

        fclose($out);
        exit;
    }

    // =========================================================================
    // ADMIN: AJAX — Convert to WooCommerce Order
    // =========================================================================

    public function ajax_convert_to_order(): void {
        check_ajax_referer('guardify-incomplete-nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        global $wpdb;

        $id = (int) ($_POST['id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? 'pending');
        $valid_statuses = ['pending', 'processing', 'completed', 'on-hold'];
        if (!in_array($status, $valid_statuses, true)) {
            $status = 'pending';
        }

        if (!$id) {
            wp_send_json_error(['message' => 'Invalid ID']);
            return;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id),
            ARRAY_A
        );

        if (!$row) {
            wp_send_json_error(['message' => 'Record not found']);
            return;
        }

        $cart = json_decode($row['cart_data'], true);
        if (empty($cart) || !is_array($cart)) {
            wp_send_json_error(['message' => 'No cart data available']);
            return;
        }

        try {
            $order = wc_create_order();

            // Add products
            foreach ($cart as $item) {
                $product_id = (int) ($item['product_id'] ?? 0);
                $variation_id = (int) ($item['variation_id'] ?? 0);
                $quantity = max(1, (int) ($item['quantity'] ?? 1));

                $product = $variation_id > 0 ? wc_get_product($variation_id) : wc_get_product($product_id);
                if (!$product) {
                    $product = wc_get_product($product_id);
                }
                if ($product) {
                    $order->add_product($product, $quantity);
                }
            }

            // Set billing / shipping
            $billing = [
                'first_name' => $row['name'] ?: 'Guest',
                'phone'      => $row['phone'],
                'email'      => $row['email'] ?: '',
                'address_1'  => $row['address'] ?: '',
                'city'       => $row['city'] ?: '',
                'state'      => $row['state'] ?: '',
                'country'    => $row['country'] ?: 'BD',
                'postcode'   => $row['postcode'] ?: '',
            ];
            $order->set_address($billing, 'billing');
            $order->set_address($billing, 'shipping');

            $order->set_payment_method('cod');
            $order->calculate_totals();
            $order->set_status($status);
            $order->save();

            // Mark as recovered
            $wpdb->update(
                $this->table_name,
                ['status' => 'recovered'],
                ['id' => $id],
                ['%s'],
                ['%d']
            );

            wp_send_json_success([
                'message'  => sprintf('WooCommerce Order #%d created (%s)', $order->get_id(), ucfirst($status)),
                'order_id' => $order->get_id(),
            ]);

        } catch (\Exception $e) {
            wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
        }
    }

    // =========================================================================
    // SCHEDULED CLEANUP
    // =========================================================================

    public function cleanup_old_records(): void {
        $retention_days = (int) get_option('guardify_abandoned_cart_retention_days', '30');
        if ($retention_days < 1) {
            return; // 0 = keep forever
        }

        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$retention_days} days"));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE status = 'pending' AND created_at < %s LIMIT 100",
            $cutoff
        ));
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Safely extract and sanitize a field from POST data.
     */
    private function sanitize_field(array $data, string $key): string {
        $val = isset($data[$key]) ? trim($data[$key]) : '';
        if ($val === '' || $val === 'undefined') {
            return '';
        }
        return sanitize_text_field($val);
    }

    /**
     * Format DB timestamp for display using WP settings.
     */
    private function format_timestamp(string $timestamp): string {
        if (empty($timestamp)) {
            return '';
        }
        return date_i18n(
            get_option('date_format') . ' ' . get_option('time_format'),
            strtotime($timestamp)
        );
    }

    /**
     * Human-readable time difference.
     */
    private function human_time_diff(string $timestamp): string {
        if (empty($timestamp)) {
            return '';
        }
        $time = strtotime($timestamp);
        $now  = current_time('timestamp');
        if ($time > $now) {
            return __('just now', 'guardify');
        }
        return human_time_diff($time, $now) . ' ' . __('ago', 'guardify');
    }
}
