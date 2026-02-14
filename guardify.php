<?php
/**
 * Plugin Name: Guardify
 * Plugin URI: https://github.com/TansiqLabs/Guardify
 * Description: Advanced WooCommerce fraud prevention plugin with Bangladesh phone validation, IP/Phone cooldown, Cartflows support, Whitelist, Address Detection, Analytics, SteadFast courier integration, and order tracking features.
 * Version: 1.0.7
 * Author: Tansiq Labs
 * Author URI: https://tansiqlabs.com/
 * Text Domain: guardify
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.5
 * License: Proprietary - Copyright © 2026 Tansiq Labs
 * License URI: https://tansiqlabs.com/license
 * 
 * This plugin is the property of Tansiq Labs. Unauthorized use, reproduction, or distribution
 * without explicit permission is strictly prohibited and will result in legal consequences.
 * অনুমতি ছাড়া এই প্লাগিন ব্যবহার, কপি বা বিতরণ করলে দুনিয়া এবং আখিরাতে দায়ী থাকবেন।
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('GUARDIFY_VERSION', '1.0.7');
define('GUARDIFY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GUARDIFY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GUARDIFY_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('GUARDIFY_MIN_WP_VERSION', '6.0');
define('GUARDIFY_MIN_PHP_VERSION', '8.0');
define('GUARDIFY_MIN_WC_VERSION', '8.0');

/**
 * Check system requirements
 */
function guardify_check_requirements(): bool {
    $errors = array();

    // Check PHP version
    if (version_compare(PHP_VERSION, GUARDIFY_MIN_PHP_VERSION, '<')) {
        $errors[] = sprintf(
            /* translators: 1: Required PHP version, 2: Current PHP version */
            __('Guardify এর জন্য PHP %1$s বা তার উপরের ভার্সন প্রয়োজন। আপনার বর্তমান PHP ভার্সন %2$s।', 'guardify'),
            GUARDIFY_MIN_PHP_VERSION,
            PHP_VERSION
        );
    }

    // Check WordPress version
    global $wp_version;
    if (version_compare($wp_version, GUARDIFY_MIN_WP_VERSION, '<')) {
        $errors[] = sprintf(
            /* translators: 1: Required WP version, 2: Current WP version */
            __('Guardify এর জন্য WordPress %1$s বা তার উপরের ভার্সন প্রয়োজন। আপনার বর্তমান WordPress ভার্সন %2$s।', 'guardify'),
            GUARDIFY_MIN_WP_VERSION,
            $wp_version
        );
    }

    if (!empty($errors)) {
        add_action('admin_notices', function() use ($errors) {
            foreach ($errors as $error) {
                printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($error));
            }
        });
        return false;
    }

    return true;
}

/**
 * Check if WooCommerce is active and meets minimum version
 */
function guardify_check_woocommerce(): bool {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'guardify_woocommerce_missing_notice');
        return false;
    }

    // Check WooCommerce version
    if (defined('WC_VERSION') && version_compare(WC_VERSION, GUARDIFY_MIN_WC_VERSION, '<')) {
        add_action('admin_notices', function() {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                sprintf(
                    /* translators: 1: Required WC version, 2: Current WC version */
                    esc_html__('Guardify এর জন্য WooCommerce %1$s বা তার উপরের ভার্সন প্রয়োজন। আপনার বর্তমান WooCommerce ভার্সন %2$s।', 'guardify'),
                    GUARDIFY_MIN_WC_VERSION,
                    WC_VERSION
                )
            );
        });
        return false;
    }

    return true;
}

/**
 * Admin notice for missing WooCommerce
 */
function guardify_woocommerce_missing_notice(): void {
    ?>
    <div class="notice notice-error">
        <p><?php esc_html_e('Guardify এর জন্য WooCommerce প্লাগিন সক্রিয় থাকা আবশ্যক।', 'guardify'); ?></p>
    </div>
    <?php
}

/**
 * Safely include Guardify class files with error handling
 */
function guardify_safe_include(string $file_path, string $class_name = ''): void {
    if (file_exists($file_path)) {
        try {
            require_once $file_path;
            if (!empty($class_name) && !class_exists($class_name)) {
                error_log("Guardify Error: Class '$class_name' not found after including file: $file_path");
            }
        } catch (ParseError $e) {
            error_log("Guardify Parse Error in $file_path: " . $e->getMessage());
        } catch (Error $e) {
            error_log("Guardify Error in $file_path: " . $e->getMessage());
        } catch (Exception $e) {
            error_log("Guardify Exception in $file_path: " . $e->getMessage());
        }
    } else {
        error_log("Guardify Error: File not found: $file_path");
    }
}

/**
 * Check if the plugin is connected to TansiqLabs via a valid license.
 * Returns true when:
 *  1. A license key exists
 *  2. The stored status is 'active'
 *  3. Either: Last check was within 3 days, OR no check yet (newly activated)
 */
function guardify_is_connected(): bool {
    $api_key = get_option('guardify_api_key', '');
    $status  = get_option('guardify_license_status', '');
    
    // Must have key and active status
    if (empty($api_key) || $status !== 'active') {
        return false;
    }
    
    // Staleness check — require server contact within the last 3 days
    // BUT: allow newly activated licenses (no check yet) to work
    $last_check = get_option('guardify_license_last_check', '');
    if (!empty($last_check)) {
        // Use gmdate/strtotime with UTC to avoid timezone mismatch
        $last_check_time = strtotime($last_check . ' UTC');
        $stale_threshold = 3 * DAY_IN_SECONDS; // 3 days
        if ($last_check_time && (time() - $last_check_time) > $stale_threshold) {
            return false; // Stale cache, not verified recently
        }
    }
    // If no last_check, that's OK (newly activated, will verify on first periodic check)
    
    // Ensure the daily validation cron is scheduled
    if (!wp_next_scheduled('guardify_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'guardify_daily_cleanup');
    }
    
    return true;
}

/**
 * Initialize the plugin
 */
function guardify_init(): void {
    // Check system requirements first
    if (!guardify_check_requirements()) {
        return;
    }

    if (!guardify_check_woocommerce()) {
        return;
    }

    // Load text domain for translations
    load_plugin_textdomain('guardify', false, dirname(GUARDIFY_PLUGIN_BASENAME) . '/languages');

    // =============================================
    // ALWAYS load: Settings + SSO receiver
    // (so users can enter their license key even when not connected)
    // =============================================
    if (is_admin()) {
        guardify_safe_include(GUARDIFY_PLUGIN_DIR . 'includes/class-guardify-settings.php', 'Guardify_Settings');
        if (class_exists('Guardify_Settings')) {
            Guardify_Settings::get_instance();
        }
    }

    // =============================================
    // LICENSE GATE — All features require an active API connection
    // =============================================
    if (!guardify_is_connected()) {
        // Show admin notice prompting license activation
        add_action('admin_notices', 'guardify_not_connected_notice');
        return; // Stop loading any feature classes
    }

    // =============================================
    // FRONTEND CLASSES - Load only when needed
    // =============================================
    
    // Phone Validation - Only on checkout
    guardify_safe_include(GUARDIFY_PLUGIN_DIR . 'includes/class-guardify-phone-validation.php', 'Guardify_Phone_Validation');
    if (class_exists('Guardify_Phone_Validation')) {
        Guardify_Phone_Validation::get_instance();
    }
    
    // Order Cooldown - Only on checkout
    guardify_safe_include(GUARDIFY_PLUGIN_DIR . 'includes/class-guardify-order-cooldown.php', 'Guardify_Order_Cooldown');
    if (class_exists('Guardify_Order_Cooldown')) {
        Guardify_Order_Cooldown::get_instance();
    }
    
    // VPN Block - Only on checkout
    guardify_safe_include(GUARDIFY_PLUGIN_DIR . 'includes/class-guardify-vpn-block.php', 'Guardify_VPN_Block');
    if (class_exists('Guardify_VPN_Block')) {
        Guardify_VPN_Block::get_instance();
    }
    
    // Advanced Protection (Whitelist, Address Detection, Notifications) - Only on checkout
    guardify_safe_include(GUARDIFY_PLUGIN_DIR . 'includes/class-guardify-advanced-protection.php', 'Guardify_Advanced_Protection');
    if (class_exists('Guardify_Advanced_Protection')) {
        Guardify_Advanced_Protection::get_instance();
    }

    // Fraud Check - Universal fraud intelligence (auto-check on new orders + admin UI)
    // Loaded outside is_admin() because woocommerce_new_order fires on frontend checkout
    guardify_safe_include(GUARDIFY_PLUGIN_DIR . 'includes/class-guardify-fraud-check.php', 'Guardify_Fraud_Check');
    if (class_exists('Guardify_Fraud_Check')) {
        Guardify_Fraud_Check::get_instance();
    }

    // =============================================
    // ADMIN CLASSES - Load only in admin
    // =============================================
    if (is_admin()) {
        // Order Completed By - Admin order list
        guardify_safe_include(GUARDIFY_PLUGIN_DIR . 'includes/class-guardify-order-completed-by.php', 'Guardify_Order_Completed_By');
        if (class_exists('Guardify_Order_Completed_By')) {
            Guardify_Order_Completed_By::get_instance();
        }
        
        // Phone History - Admin order list
        guardify_safe_include(GUARDIFY_PLUGIN_DIR . 'includes/class-guardify-phone-history.php', 'Guardify_Phone_History');
        if (class_exists('Guardify_Phone_History')) {
            Guardify_Phone_History::get_instance();
        }
        
        // Staff Report
        guardify_safe_include(GUARDIFY_PLUGIN_DIR . 'includes/class-guardify-staff-report.php', 'Guardify_Staff_Report');
        if (class_exists('Guardify_Staff_Report')) {
            Guardify_Staff_Report::get_instance();
        }
        
        // SteadFast Courier Integration
        guardify_safe_include(GUARDIFY_PLUGIN_DIR . 'includes/class-guardify-steadfast.php', 'Guardify_SteadFast');
        if (class_exists('Guardify_SteadFast')) {
            Guardify_SteadFast::get_instance();
        }
        
        // Order Columns - Score, Duplicate, Block Action
        guardify_safe_include(GUARDIFY_PLUGIN_DIR . 'includes/class-guardify-order-columns.php', 'Guardify_Order_Columns');
        if (class_exists('Guardify_Order_Columns')) {
            Guardify_Order_Columns::get_instance();
        }
        
        // Blocklist Manager - Manage blocked phones, IPs, devices
        guardify_safe_include(GUARDIFY_PLUGIN_DIR . 'includes/class-guardify-blocklist.php', 'Guardify_Blocklist');
        if (class_exists('Guardify_Blocklist')) {
            Guardify_Blocklist::get_instance();
        }
    }
}

/**
 * Admin notice shown when the plugin is not connected to TansiqLabs.
 */
function guardify_not_connected_notice(): void {
    // Only show on non-Guardify pages to avoid clutter on the settings page itself
    $screen = get_current_screen();
    if ($screen && str_contains($screen->id, 'guardify')) {
        return;
    }
    
    $api_key = get_option('guardify_api_key', '');
    $status  = get_option('guardify_license_status', '');
    
    // Determine the reason for disconnection
    if (empty($api_key)) {
        $message = __('Plugin is not connected. All protection features are disabled. <a href="%s">Activate your license</a> to enable fraud prevention.', 'guardify');
    } elseif ($status === 'pending_verification') {
        $message = __('License is pending verification. Click "Verify License" on the settings page to activate protection. <a href="%s">Go to settings</a>.', 'guardify');
    } elseif ($status === 'error') {
        $message = __('License verification error. All protection features are disabled. <a href="%s">Check your license</a> to resolve the issue.', 'guardify');
    } elseif ($status === 'disconnected') {
        $message = __('License server unreachable. All protection features are disabled until the connection is restored. <a href="%s">Check license status</a>.', 'guardify');
    } elseif ($status === 'expired') {
        $message = __('License has expired. All protection features are disabled. <a href="%s">Renew your license</a> to restore protection.', 'guardify');
    } else {
        $message = __('License is not active (status: %2$s). All protection features are disabled. <a href="%1$s">Check your license</a>.', 'guardify');
    }
    ?>
    <div class="notice notice-warning">
        <p>
            <strong>Guardify:</strong>
            <?php printf($message, esc_url(admin_url('admin.php?page=guardify-settings')), esc_html($status)); ?>
        </p>
    </div>
    <?php
}

add_action('plugins_loaded', 'guardify_init');

/**
 * Initialize Plugin Update Checker
 */
add_action('init', function() {
    if (file_exists(GUARDIFY_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php')) {
        require_once GUARDIFY_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';
        
        if (class_exists('YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
            global $guardifyUpdateChecker;
            $guardifyUpdateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
                'https://github.com/TansiqLabs/Guardify/',
                __FILE__,
                'guardify',
                6 // Check for updates every 6 hours
            );

            // Set the branch that contains the stable release
            $guardifyUpdateChecker->setBranch('main');

            // Prevent the "View details" link from opening the WP.org modal for a different plugin
            // (Some slugs conflict on wordpress.org; disabling prevents showing another plugin's data)
            add_filter($guardifyUpdateChecker->getUniqueName('view_details_link'), '__return_empty_string');
        }
    }
}, 1);

/**
 * Add manual "Check for Updates" button
 */
add_filter('plugin_action_links_' . GUARDIFY_PLUGIN_BASENAME, 'guardify_add_check_update_link');
function guardify_add_check_update_link(array $links): array {
    global $guardifyUpdateChecker;
    if (isset($guardifyUpdateChecker)) {
        $check_update_link = '<a href="' . esc_url(wp_nonce_url(admin_url('plugins.php?guardify_force_check=1'), 'guardify_force_check')) . '" style="color: #2271b1; font-weight: 600;">' . esc_html__('Check for Updates', 'guardify') . '</a>';
        $links[] = $check_update_link;
    }
    return $links;
}

/**
 * Handle manual update check
 */
add_action('admin_init', 'guardify_handle_manual_update_check');
function guardify_handle_manual_update_check(): void {
    if (isset($_GET['guardify_force_check']) && check_admin_referer('guardify_force_check')) {
        global $guardifyUpdateChecker;
        if ($guardifyUpdateChecker) {
            // Force check for updates
            $guardifyUpdateChecker->checkForUpdates();
            
            // Redirect with success message
            wp_safe_redirect(add_query_arg('guardify_update_checked', '1', admin_url('plugins.php')));
            exit;
        }
    }
    
    // Show success notice after manual check
    if (isset($_GET['guardify_update_checked'])) {
        add_action('admin_notices', function(): void {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>Guardify:</strong> ' . esc_html__('Update check completed. If a new version is available, you will see an update notification.', 'guardify') . '</p>';
            echo '</div>';
        });
    }
}

/**
 * Plugin activation hook
 */
function guardify_activate(): void {
    // Check requirements on activation
    if (version_compare(PHP_VERSION, '8.0', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('Guardify এর জন্য PHP 8.0 বা তার উপরের ভার্সন প্রয়োজন।', 'guardify'),
            'Plugin Activation Error',
            array('back_link' => true)
        );
    }

    // Set default options
    $default_options = array(
        // Phone Validation
        'guardify_bd_phone_validation_enabled' => '1',
        'guardify_bd_phone_validation_message' => 'অনুগ্রহ করে একটি সঠিক বাংলাদেশি মোবাইল নাম্বার দিন (যেমন: 01712345678)',
        
        // Cooldown
        'guardify_phone_cooldown_enabled' => '0',
        'guardify_phone_cooldown_time' => '24',
        'guardify_ip_cooldown_enabled' => '0',
        'guardify_ip_cooldown_time' => '1',
        'guardify_phone_cooldown_message' => 'আপনি ইতিমধ্যে এই নাম্বার থেকে অর্ডার করেছেন। অনুগ্রহ করে %d ঘন্টা পর আবার চেষ্টা করুন।',
        'guardify_ip_cooldown_message' => 'আপনি ইতিমধ্যে অর্ডার করেছেন। অনুগ্রহ করে %d ঘন্টা পর আবার চেষ্টা করুন।',
        
        // Tracking
        'guardify_order_completed_by_enabled' => '1',
        'guardify_phone_history_enabled' => '1',
        
        // VPN Block
        'guardify_vpn_block_enabled' => '0',
        'guardify_vpn_block_message' => 'দুঃখিত, VPN/Proxy ব্যবহার করে অর্ডার করা যাবে না।',
        
        // Advanced Protection - Whitelist
        'guardify_whitelist_enabled' => '0',
        'guardify_whitelisted_phones' => '',
        'guardify_whitelisted_ips' => '',
        
        // Advanced Protection - Address Detection
        'guardify_address_detection_enabled' => '0',
        'guardify_max_orders_per_address' => '5',
        'guardify_address_time_hours' => '24',
        'guardify_address_block_message' => 'এই ঠিকানা থেকে অনেক অর্ডার হয়েছে। অনুগ্রহ করে যোগাযোগ করুন।',
        
        // Advanced Protection - Name Similarity
        'guardify_name_similarity_enabled' => '0',
        'guardify_name_similarity_threshold' => '80',
        'guardify_name_check_hours' => '24',
        'guardify_similar_name_message' => 'সন্দেহজনক অর্ডার প্যাটার্ন সনাক্ত হয়েছে।',
        
        // Notifications
        'guardify_notification_enabled' => '0',
        'guardify_email_notification' => '1',
        
        // SteadFast Courier
        'guardify_steadfast_enabled' => '0',
        'guardify_steadfast_api_key' => '',
        'guardify_steadfast_secret_key' => '',
        'guardify_steadfast_send_notes' => '0',
        'guardify_steadfast_business_name' => '',
        'guardify_steadfast_business_address' => '',
        'guardify_steadfast_business_email' => '',
        'guardify_steadfast_business_phone' => '',
        'guardify_steadfast_business_logo' => '',
        'guardify_steadfast_terms' => '',
    );

    foreach ($default_options as $option => $value) {
        if (get_option($option) === false) {
            add_option($option, $value);
        }
    }

    // Store activation time for analytics
    if (get_option('guardify_activated_at') === false) {
        add_option('guardify_activated_at', current_time('mysql'));
    }
    update_option('guardify_version', GUARDIFY_VERSION);

    // Schedule daily cleanup
    if (!wp_next_scheduled('guardify_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'guardify_daily_cleanup');
    }

    // Flush rewrite rules
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'guardify_activate');

/**
 * Daily cleanup task
 */
add_action('guardify_daily_cleanup', 'guardify_cleanup_old_data');
function guardify_cleanup_old_data(): void {
    // Clean up old blocked logs (keep only last 30 days)
    $daily_stats = get_option('guardify_daily_stats', array());
    if (count($daily_stats) > 30) {
        $daily_stats = array_slice($daily_stats, -30, 30, true);
        update_option('guardify_daily_stats', $daily_stats, false);
    }
    
    // Clean up blocked log (keep only last 500 entries)
    $log = get_option('guardify_blocked_log', array());
    if (count($log) > 500) {
        $log = array_slice($log, -500);
        update_option('guardify_blocked_log', $log, false);
    }
}

/**
 * Plugin deactivation hook
 */
function guardify_deactivate(): void {
    // Clear any scheduled hooks
    wp_clear_scheduled_hook('guardify_daily_cleanup');
    
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'guardify_deactivate');

/**
 * Add settings link to plugins page
 */
function guardify_add_settings_link(array $links): array {
    $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=guardify-settings')) . '">' . esc_html__('Settings', 'guardify') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . GUARDIFY_PLUGIN_BASENAME, 'guardify_add_settings_link');

/**
 * Declare WooCommerce HPOS (High-Performance Order Storage) compatibility
 */
add_action('before_woocommerce_init', function(): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

/**
 * Add support for WooCommerce Block-based Checkout
 */
add_action('woocommerce_blocks_loaded', function(): void {
    if (class_exists('Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface')) {
        add_action('woocommerce_blocks_checkout_block_registration', function($integration_registry) {
            // Register block checkout integration if needed
        });
    }
});
