<?php
/**
 * Guardify Settings Class
 * প্লাগিন সেটিংস পেজ - ট্যাব-ভিত্তিক ইন্টারফেস
 */

if (!defined('ABSPATH')) {
    exit;
}

class Guardify_Settings {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'save_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
        add_action('wp_ajax_guardify_activate_license', array($this, 'ajax_activate_license'));
        add_action('wp_ajax_guardify_deactivate_license', array($this, 'ajax_deactivate_license'));
        add_action('wp_ajax_guardify_check_license', array($this, 'ajax_check_license'));
        add_action('wp_ajax_guardify_sso_login', array($this, 'ajax_sso_login'));

        // Non-AJAX fallback for license activation (form POST)
        add_action('admin_post_guardify_activate_license_form', array($this, 'handle_activate_license_form'));
        
        // SSO receiver — auto-login from TansiqLabs Console/Ops
        add_action('init', array($this, 'handle_wp_sso_login'));
        
        // Periodic license validation (once daily)
        add_action('guardify_daily_cleanup', array($this, 'periodic_license_check'));
    }

    /**
     * TansiqLabs API Base URL
     */
    private const TANSIQLABS_API_URL = 'https://tansiqlabs.com/api/guardify/';

    /**
     * Add admin menu with submenus
     */
    public function add_admin_menu() {
        // Main menu (redirect to dashboard)
        add_menu_page(
            __('Guardify', 'guardify'),
            __('Guardify', 'guardify'),
            'manage_options',
            'guardify-settings',
            array($this, 'render_settings_page'),
            'data:image/svg+xml;base64,' . base64_encode($this->get_menu_icon()),
            2  // Position 2 (right after Dashboard)
        );

        // Submenus
        add_submenu_page(
            'guardify-settings',
            __('Dashboard', 'guardify'),
            __('Dashboard', 'guardify'),
            'manage_options',
            'guardify-settings',
            array($this, 'render_settings_page')
        );

        add_submenu_page(
            'guardify-settings',
            __('Phone Validation', 'guardify'),
            __('Phone Validation', 'guardify'),
            'manage_options',
            'guardify-validation',
            array($this, 'render_validation_page')
        );

        add_submenu_page(
            'guardify-settings',
            __('Cooldown Settings', 'guardify'),
            __('Cooldown', 'guardify'),
            'manage_options',
            'guardify-cooldown',
            array($this, 'render_cooldown_page')
        );

        add_submenu_page(
            'guardify-settings',
            __('Order Tracking', 'guardify'),
            __('Tracking', 'guardify'),
            'manage_options',
            'guardify-tracking',
            array($this, 'render_tracking_page')
        );

        add_submenu_page(
            'guardify-settings',
            __('SteadFast Courier', 'guardify'),
            __('SteadFast', 'guardify'),
            'manage_options',
            'guardify-steadfast',
            array($this, 'render_steadfast_page')
        );
    }

    /**
     * Get custom SVG icon for menu
     */
    private function get_menu_icon() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#a7aaad"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg>';
    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles($hook) {
        // Reliably detect any Guardify admin page via the 'page' query arg
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if (strpos($page, 'guardify') === false) {
            return;
        }

        wp_enqueue_style(
            'guardify-admin-styles',
            GUARDIFY_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            GUARDIFY_VERSION
        );

        wp_enqueue_script(
            'guardify-admin-script',
            GUARDIFY_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            GUARDIFY_VERSION,
            true
        );
        
        // Localize script for AJAX
        wp_localize_script('guardify-admin-script', 'guardifyAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('guardify_ajax_nonce')
        ));
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        $_GET['tab'] = 'dashboard';
        $this->render_settings_page();
    }

    /**
     * Render validation page
     */
    public function render_validation_page() {
        $_GET['tab'] = 'validation';
        $this->render_settings_page();
    }

    /**
     * Render cooldown page
     */
    public function render_cooldown_page() {
        $_GET['tab'] = 'cooldown';
        $this->render_settings_page();
    }

    /**
     * Render tracking page
     */
    public function render_tracking_page() {
        $_GET['tab'] = 'tracking';
        $this->render_settings_page();
    }

    /**
     * Save settings
     */
    public function save_settings() {
        if (!isset($_POST['guardify_save_settings']) || !check_admin_referer('guardify_settings')) {
            return;
        }

        // BD Phone Validation
        update_option('guardify_bd_phone_validation_enabled', isset($_POST['guardify_bd_phone_validation_enabled']) ? '1' : '0');
        if (isset($_POST['guardify_bd_phone_validation_message'])) {
            update_option('guardify_bd_phone_validation_message', sanitize_textarea_field($_POST['guardify_bd_phone_validation_message']));
        }

        // Phone Cooldown
        update_option('guardify_phone_cooldown_enabled', isset($_POST['guardify_phone_cooldown_enabled']) ? '1' : '0');
        if (isset($_POST['guardify_phone_cooldown_time'])) {
            update_option('guardify_phone_cooldown_time', absint($_POST['guardify_phone_cooldown_time']));
        }
        if (isset($_POST['guardify_phone_cooldown_message'])) {
            update_option('guardify_phone_cooldown_message', sanitize_textarea_field($_POST['guardify_phone_cooldown_message']));
        }

        // IP Cooldown
        update_option('guardify_ip_cooldown_enabled', isset($_POST['guardify_ip_cooldown_enabled']) ? '1' : '0');
        if (isset($_POST['guardify_ip_cooldown_time'])) {
            update_option('guardify_ip_cooldown_time', absint($_POST['guardify_ip_cooldown_time']));
        }
        if (isset($_POST['guardify_ip_cooldown_message'])) {
            update_option('guardify_ip_cooldown_message', sanitize_textarea_field($_POST['guardify_ip_cooldown_message']));
        }
        
        // Contact Number for Cooldown Popup
        if (isset($_POST['guardify_contact_number'])) {
            update_option('guardify_contact_number', sanitize_text_field($_POST['guardify_contact_number']));
        }

        // Order Completed By
        update_option('guardify_order_completed_by_enabled', isset($_POST['guardify_order_completed_by_enabled']) ? '1' : '0');

        // Phone History
        update_option('guardify_phone_history_enabled', isset($_POST['guardify_phone_history_enabled']) ? '1' : '0');

        // User Colors
        if (isset($_POST['guardify_user_color']) && is_array($_POST['guardify_user_color'])) {
            $user_colors = array();
            foreach ($_POST['guardify_user_color'] as $user_id => $color_index) {
                $user_colors[absint($user_id)] = absint($color_index);
            }
            update_option('guardify_user_colors', $user_colors);
        }

        // API Key
        if (isset($_POST['guardify_api_key'])) {
            update_option('guardify_api_key', sanitize_text_field($_POST['guardify_api_key']));
        }

        // VPN Block
        update_option('guardify_vpn_block_enabled', isset($_POST['guardify_vpn_block_enabled']) ? '1' : '0');
        if (isset($_POST['guardify_vpn_block_message'])) {
            update_option('guardify_vpn_block_message', sanitize_textarea_field($_POST['guardify_vpn_block_message']));
        }

        // Whitelist Feature
        update_option('guardify_whitelist_enabled', isset($_POST['guardify_whitelist_enabled']) ? '1' : '0');
        if (isset($_POST['guardify_whitelisted_phones'])) {
            update_option('guardify_whitelisted_phones', sanitize_textarea_field($_POST['guardify_whitelisted_phones']));
        }
        if (isset($_POST['guardify_whitelisted_ips'])) {
            update_option('guardify_whitelisted_ips', sanitize_textarea_field($_POST['guardify_whitelisted_ips']));
        }

        // Address Detection
        update_option('guardify_address_detection_enabled', isset($_POST['guardify_address_detection_enabled']) ? '1' : '0');
        if (isset($_POST['guardify_max_orders_per_address'])) {
            update_option('guardify_max_orders_per_address', absint($_POST['guardify_max_orders_per_address']));
        }
        if (isset($_POST['guardify_address_time_hours'])) {
            update_option('guardify_address_time_hours', absint($_POST['guardify_address_time_hours']));
        }
        if (isset($_POST['guardify_address_block_message'])) {
            update_option('guardify_address_block_message', sanitize_textarea_field($_POST['guardify_address_block_message']));
        }

        // Name Similarity Detection
        update_option('guardify_name_similarity_enabled', isset($_POST['guardify_name_similarity_enabled']) ? '1' : '0');
        if (isset($_POST['guardify_name_similarity_threshold'])) {
            update_option('guardify_name_similarity_threshold', absint($_POST['guardify_name_similarity_threshold']));
        }
        if (isset($_POST['guardify_name_check_hours'])) {
            update_option('guardify_name_check_hours', absint($_POST['guardify_name_check_hours']));
        }
        if (isset($_POST['guardify_similar_name_message'])) {
            update_option('guardify_similar_name_message', sanitize_textarea_field($_POST['guardify_similar_name_message']));
        }

        // Notification Settings
        update_option('guardify_notification_enabled', isset($_POST['guardify_notification_enabled']) ? '1' : '0');
        update_option('guardify_email_notification', isset($_POST['guardify_email_notification']) ? '1' : '0');
        if (isset($_POST['guardify_notification_email'])) {
            update_option('guardify_notification_email', sanitize_email($_POST['guardify_notification_email']));
        }

        // SteadFast Courier Settings — API keys managed via TansiqLabs console, only save local settings
        update_option('guardify_steadfast_send_notes', isset($_POST['guardify_steadfast_send_notes']) ? '1' : '0');
        if (isset($_POST['guardify_steadfast_business_name'])) {
            update_option('guardify_steadfast_business_name', sanitize_text_field($_POST['guardify_steadfast_business_name']));
        }
        if (isset($_POST['guardify_steadfast_business_address'])) {
            update_option('guardify_steadfast_business_address', sanitize_textarea_field($_POST['guardify_steadfast_business_address']));
        }
        if (isset($_POST['guardify_steadfast_business_email'])) {
            update_option('guardify_steadfast_business_email', sanitize_email($_POST['guardify_steadfast_business_email']));
        }
        if (isset($_POST['guardify_steadfast_business_phone'])) {
            update_option('guardify_steadfast_business_phone', sanitize_text_field($_POST['guardify_steadfast_business_phone']));
        }
        if (isset($_POST['guardify_steadfast_business_logo'])) {
            update_option('guardify_steadfast_business_logo', esc_url_raw($_POST['guardify_steadfast_business_logo']));
        }
        if (isset($_POST['guardify_steadfast_terms'])) {
            update_option('guardify_steadfast_terms', sanitize_textarea_field($_POST['guardify_steadfast_terms']));
        }

        // Redirect with success message
        wp_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
        exit;
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        // Get current settings
        $bd_phone_validation_enabled = get_option('guardify_bd_phone_validation_enabled', '1');
        $bd_phone_validation_message = get_option('guardify_bd_phone_validation_message', 'অনুগ্রহ করে একটি সঠিক বাংলাদেশি মোবাইল নাম্বার দিন (যেমন: 01712345678)');
        
        $phone_cooldown_enabled = get_option('guardify_phone_cooldown_enabled', '0');
        $phone_cooldown_time = get_option('guardify_phone_cooldown_time', '24');
        $phone_cooldown_message = get_option('guardify_phone_cooldown_message', 'আপনি ইতিমধ্যে এই নাম্বার থেকে অর্ডার করেছেন। অনুগ্রহ করে %d ঘন্টা পর আবার চেষ্টা করুন।');
        
        $ip_cooldown_enabled = get_option('guardify_ip_cooldown_enabled', '0');
        $ip_cooldown_time = get_option('guardify_ip_cooldown_time', '1');
        $ip_cooldown_message = get_option('guardify_ip_cooldown_message', 'আপনি ইতিমধ্যে অর্ডার করেছেন। অনুগ্রহ করে %d ঘন্টা পর আবার চেষ্টা করুন।');
        
        $contact_number = get_option('guardify_contact_number', '');
        
        $order_completed_by_enabled = get_option('guardify_order_completed_by_enabled', '1');
        $phone_history_enabled = get_option('guardify_phone_history_enabled', '1');
        
        $vpn_block_enabled = get_option('guardify_vpn_block_enabled', '0');
        $vpn_block_message = get_option('guardify_vpn_block_message', 'দুঃখিত, VPN/Proxy ব্যবহার করে অর্ডার করা যাবে না। অনুগ্রহ করে আপনার সাধারণ ইন্টারনেট সংযোগ ব্যবহার করুন।');

        // Get active tab
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dashboard';

        ?>
        <div class="wrap guardify-settings-wrap">
            <!-- Premium Header -->
            <div class="guardify-header">
                <div class="guardify-header-content">
                    <div class="guardify-logo">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#fff" width="40" height="40"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg>
                    </div>
                    <div>
                        <h1>Guardify</h1>
                        <p class="guardify-subtitle">Advanced WooCommerce Fraud Prevention</p>
                    </div>
                </div>
                <div class="guardify-version-badge">
                    <span class="version">v<?php echo GUARDIFY_VERSION; ?></span>
                    <span class="developer">by Tansiq Labs</span>
                </div>
            </div>

            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="notice notice-success is-dismissible guardify-success-notice">
                    <div class="guardify-notice-content">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <div>
                            <p><strong><?php _e('Settings saved successfully!', 'guardify'); ?></strong></p>
                            <p class="guardify-notice-subtext"><?php _e('Your configuration is now active.', 'guardify'); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Tabs Navigation -->
            <div class="guardify-tabs-nav">
                <a href="?page=guardify-settings&tab=dashboard" class="guardify-tab <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-dashboard"></span>
                    <span><?php _e('Dashboard', 'guardify'); ?></span>
                </a>
                <a href="?page=guardify-settings&tab=validation" class="guardify-tab <?php echo $active_tab === 'validation' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-phone"></span>
                    <span><?php _e('Phone Validation', 'guardify'); ?></span>
                </a>
                <a href="?page=guardify-settings&tab=cooldown" class="guardify-tab <?php echo $active_tab === 'cooldown' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-clock"></span>
                    <span><?php _e('Cooldown Settings', 'guardify'); ?></span>
                </a>
                <a href="?page=guardify-settings&tab=tracking" class="guardify-tab <?php echo $active_tab === 'tracking' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-visibility"></span>
                    <span><?php _e('Tracking', 'guardify'); ?></span>
                </a>
                <a href="?page=guardify-settings&tab=colors" class="guardify-tab <?php echo $active_tab === 'colors' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-admin-appearance"></span>
                    <span><?php _e('User Colors', 'guardify'); ?></span>
                </a>
                <a href="?page=guardify-settings&tab=advanced" class="guardify-tab <?php echo $active_tab === 'advanced' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-shield"></span>
                    <span><?php _e('Advanced', 'guardify'); ?></span>
                </a>
                <a href="?page=guardify-settings&tab=analytics" class="guardify-tab <?php echo $active_tab === 'analytics' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-chart-area"></span>
                    <span><?php _e('Analytics', 'guardify'); ?></span>
                </a>
            </div>

            <!-- Tab Content -->
            <form method="post" action="">
                <?php wp_nonce_field('guardify_settings'); ?>

                <!-- Dashboard Tab -->
                <div class="guardify-tab-content <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>" data-tab="dashboard">
                    <div class="guardify-dashboard-grid">
                        
                        <?php
                        // ─── LICENSE ACTIVATION FEEDBACK ─── //
                        $license_form_msg = get_transient('guardify_license_form_msg');
                        if ($license_form_msg) {
                            delete_transient('guardify_license_form_msg');
                            $msg_type = $license_form_msg['type'] ?? 'error';
                            $msg_text = $license_form_msg['message'] ?? '';
                            ?>
                            <div class="guardify-notice guardify-notice-<?php echo esc_attr($msg_type); ?>" style="margin-bottom:16px;">
                                <p><?php echo esc_html($msg_text); ?></p>
                            </div>
                            <?php
                        }
                        ?>

                        <!-- API License Section -->
                        <?php
                        $api_key = get_option('guardify_api_key', '');
                        $license_status = get_option('guardify_license_status', '');
                        $license_expiry = get_option('guardify_license_expiry', '');
                        $license_plan = get_option('guardify_license_plan', '');
                        $license_days = get_option('guardify_license_days_remaining', '');
                        $license_max_sites = get_option('guardify_license_max_sites', 1);
                        $license_active_sites = get_option('guardify_license_active_sites', 0);
                        $license_last_check = get_option('guardify_license_last_check', '');
                        
                        if (empty($api_key)) {
                            ?>
                            <div class="guardify-card guardify-license-card">
                                <div class="guardify-license-header">
                                    <div class="license-icon">
                                        <span class="dashicons dashicons-lock"></span>
                                    </div>
                                    <div class="license-header-text">
                                        <h2><?php _e('License Activation', 'guardify'); ?></h2>
                                        <p class="description"><?php _e('Activate your license to unlock all premium features', 'guardify'); ?></p>
                                    </div>
                                </div>
                                
                                <!-- Status message area for AJAX feedback -->
                                <div id="guardify-license-msg" style="display:none; padding:10px 24px; font-weight:500;"></div>

                                <div class="guardify-license-input-container">
                                    <div class="license-input-group">
                                        <span class="dashicons dashicons-admin-network input-icon"></span>
                                        <input type="text" id="guardify-api-key" placeholder="Enter your license key" class="guardify-license-input" autocomplete="off">
                                    </div>
                                    <button type="button" id="guardify-activate-license" class="guardify-btn-activate-modern"
                                        onclick="guardifyActivateLicense(this)">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                        <span><?php _e('Activate', 'guardify'); ?></span>
                                    </button>
                                </div>

                                <!-- Non-AJAX fallback form (hidden by default, shown if JS fails) -->
                                <noscript>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="padding: 0 24px 16px;">
                                    <?php wp_nonce_field('guardify_license_form_nonce', '_guardify_license_nonce'); ?>
                                    <input type="hidden" name="action" value="guardify_activate_license_form">
                                    <div style="display:flex; gap:8px; align-items:center;">
                                        <input type="text" name="guardify_license_key" placeholder="Enter your license key" style="flex:1; padding:8px 12px; border:1px solid #d1d5db; border-radius:8px;">
                                        <button type="submit" class="button button-primary">Activate</button>
                                    </div>
                                </form>
                                </noscript>

                                <div class="guardify-license-footer">
                                    <p class="license-note">
                                        <span class="dashicons dashicons-info"></span>
                                        <?php _e('Need a license?', 'guardify'); ?> <a href="https://tansiqlabs.com/guardify" target="_blank" class="license-link"><?php _e('Purchase from Tansiq Labs', 'guardify'); ?></a>
                                    </p>
                                </div>
                            </div>
                            <?php
                        } else {
                            $days_left = '';
                            if ($license_expiry) {
                                $expiry_time = strtotime($license_expiry);
                                $current_time = time();
                                $days_left = max(0, floor(($expiry_time - $current_time) / (60 * 60 * 24)));
                            }
                            $is_expired = $license_status === 'expired' || ($days_left !== '' && $days_left <= 0);
                            $is_disconnected = in_array($license_status, ['disconnected', 'unverified'], true);
                            $is_pending = $license_status === 'pending_verification';
                            $is_error = $license_status === 'error';
                            $is_active = $license_status === 'active' && !$is_expired;
                            $is_problem = $is_expired || $is_disconnected || $is_pending || $is_error;
                            
                            // Determine badge text
                            if ($is_pending) {
                                $badge_text = __('License Pending', 'guardify');
                            } elseif ($is_error) {
                                $badge_text = __('License Error', 'guardify');
                            } elseif ($is_disconnected) {
                                $badge_text = __('License Disconnected', 'guardify');
                            } elseif ($is_expired) {
                                $badge_text = __('License Expired', 'guardify');
                            } else {
                                $badge_text = __('License Active', 'guardify');
                            }
                            ?>
                            <div class="guardify-card guardify-license-active" style="<?php echo $is_problem ? 'border-left: 4px solid #dc2626;' : 'border-left: 4px solid #22c55e;'; ?>">
                                <div class="guardify-license-badge" style="<?php echo $is_problem ? 'background: #fef2f2; color: #dc2626;' : ''; ?>">
                                    <span class="dashicons <?php echo $is_problem ? 'dashicons-warning' : 'dashicons-yes-alt'; ?>"></span>
                                    <strong><?php echo esc_html($badge_text); ?></strong>
                                </div>
                                <?php if ($is_disconnected): ?>
                                    <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px;">
                                        <p style="color: #dc2626; margin: 0; font-weight: 500;">
                                            <span class="dashicons dashicons-warning" style="font-size: 16px; width: 16px; height: 16px; line-height: 1.5;"></span>
                                            <?php _e('Could not verify license with TansiqLabs server. All protection features are disabled. Click "Verify License" to reconnect.', 'guardify'); ?>
                                        </p>
                                    </div>
                                <?php endif; ?>
                                <div class="guardify-license-info">
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                                        <p><strong><?php _e('Status:', 'guardify'); ?></strong> 
                                            <span class="<?php echo $is_active ? 'license-status-active' : 'license-status-expired'; ?>" style="<?php echo $is_problem ? 'color: #dc2626;' : ''; ?>">
                                                <?php echo ucfirst($license_status ?: 'unknown'); ?>
                                            </span>
                                        </p>
                                        <p><strong><?php _e('Plan:', 'guardify'); ?></strong> 
                                            <span style="text-transform: capitalize; font-weight: 600;"><?php echo esc_html($license_plan ?: 'Unknown'); ?></span>
                                        </p>
                                        <?php if ($days_left !== ''): ?>
                                            <p><strong><?php _e('Expires in:', 'guardify'); ?></strong> 
                                                <span class="license-days" style="<?php echo ($days_left < 30) ? 'color: #dc2626; font-weight: 700;' : ''; ?>">
                                                    <?php echo $days_left; ?> <?php _e('days', 'guardify'); ?>
                                                </span>
                                            </p>
                                        <?php endif; ?>
                                        <p><strong><?php _e('Sites:', 'guardify'); ?></strong> 
                                            <span><?php echo esc_html($license_active_sites); ?> / <?php echo esc_html($license_max_sites); ?></span>
                                        </p>
                                    </div>
                                    <?php if ($license_last_check): ?>
                                        <p style="font-size: 12px; color: #94a3b8;">
                                            <?php _e('Last verified:', 'guardify'); ?> <?php echo esc_html($license_last_check); ?>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <div style="display: flex; gap: 10px; margin-top: 12px; flex-wrap: wrap;">
                                        <button type="button" id="guardify-check-license" class="button button-secondary" style="display: inline-flex; align-items: center; gap: 4px;"
                                            onclick="guardifyVerifyLicense(this)">
                                            <span class="dashicons dashicons-update" style="font-size: 16px; width: 16px; height: 16px; line-height: 1.4;"></span>
                                            <?php _e('Verify License', 'guardify'); ?>
                                        </button>
                                        <button type="button" id="guardify-deactivate-license" class="button" style="display: inline-flex; align-items: center; gap: 4px; color: #dc2626; border-color: #dc2626;"
                                            onclick="guardifyDeactivateLicense(this)">
                                            <span class="dashicons dashicons-dismiss" style="font-size: 16px; width: 16px; height: 16px; line-height: 1.4;"></span>
                                            <?php _e('Deactivate', 'guardify'); ?>
                                        </button>
                                        <?php if ($is_expired): ?>
                                            <a href="https://tansiqlabs.com/guardify" target="_blank" class="button button-primary" style="display: inline-flex; align-items: center; gap: 4px;">
                                                <span class="dashicons dashicons-cart" style="font-size: 16px; width: 16px; height: 16px; line-height: 1.4;"></span>
                                                <?php _e('Renew License', 'guardify'); ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php
                        }
                        ?>

                        <!-- License AJAX — Inline + onclick for maximum compatibility -->
                        <script data-no-optimize="1" data-cfasync="false" data-pagespeed-no-defer>
                        /* Guardify License Manager — zero external dependencies */
                        var guardify_ajax_url = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                        var guardify_nonce    = <?php echo wp_json_encode(wp_create_nonce('guardify_ajax_nonce')); ?>;

                        function guardifyShowMsg(msg, type) {
                            var box = document.getElementById('guardify-license-msg');
                            if (!box) {
                                /* Fallback: create a notice at the top */
                                box = document.createElement('div');
                                box.id = 'guardify-license-msg';
                                var wrap = document.querySelector('.guardify-settings-wrap') || document.body;
                                wrap.prepend(box);
                            }
                            box.style.display = 'block';
                            box.style.padding = '12px 24px';
                            box.style.fontWeight = '500';
                            box.style.borderRadius = '8px';
                            box.style.margin = '0 24px 8px';
                            if (type === 'success') {
                                box.style.background = '#f0fdf4';
                                box.style.color = '#16a34a';
                                box.style.border = '1px solid #bbf7d0';
                            } else {
                                box.style.background = '#fef2f2';
                                box.style.color = '#dc2626';
                                box.style.border = '1px solid #fecaca';
                            }
                            box.textContent = msg;
                        }

                        function guardifyActivateLicense(btn) {
                            var input = document.getElementById('guardify-api-key');
                            var key = input ? input.value.trim() : '';
                            if (!key) {
                                guardifyShowMsg('Please enter your license key.', 'error');
                                return;
                            }
                            btn.disabled = true;
                            btn.innerHTML = '<span class="dashicons dashicons-update" style="animation:rotation 1s linear infinite"></span> Activating...';

                            var xhr = new XMLHttpRequest();
                            xhr.open('POST', guardify_ajax_url, true);
                            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                            xhr.onreadystatechange = function() {
                                if (xhr.readyState !== 4) return;
                                try {
                                    var res = JSON.parse(xhr.responseText);
                                    if (res.success) {
                                        guardifyShowMsg(res.data.message || 'License activated!', 'success');
                                        setTimeout(function(){ location.reload(); }, 1200);
                                    } else {
                                        guardifyShowMsg(res.data && res.data.message ? res.data.message : 'Activation failed. Check your key.', 'error');
                                        btn.disabled = false;
                                        btn.innerHTML = '<span class="dashicons dashicons-yes-alt"></span> <span>Activate</span>';
                                    }
                                } catch(e) {
                                    guardifyShowMsg('Server error (HTTP ' + xhr.status + '). Response: ' + (xhr.responseText || '').substring(0,200), 'error');
                                    btn.disabled = false;
                                    btn.innerHTML = '<span class="dashicons dashicons-yes-alt"></span> <span>Activate</span>';
                                }
                            };
                            xhr.onerror = function() {
                                guardifyShowMsg('Network error. Check your internet connection.', 'error');
                                btn.disabled = false;
                                btn.innerHTML = '<span class="dashicons dashicons-yes-alt"></span> <span>Activate</span>';
                            };
                            var fd = new FormData();
                            fd.append('action', 'guardify_activate_license');
                            fd.append('nonce', guardify_nonce);
                            fd.append('api_key', key);
                            xhr.send(fd);
                        }

                        function guardifyDeactivateLicense(btn) {
                            if (!confirm('Are you sure you want to deactivate the license from this site?')) return;
                            btn.disabled = true;
                            btn.textContent = 'Deactivating...';
                            var xhr = new XMLHttpRequest();
                            xhr.open('POST', guardify_ajax_url, true);
                            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                            xhr.onreadystatechange = function() {
                                if (xhr.readyState !== 4) return;
                                try {
                                    var res = JSON.parse(xhr.responseText);
                                    if (res.success) {
                                        guardifyShowMsg(res.data.message || 'Deactivated.', 'success');
                                        setTimeout(function(){ location.reload(); }, 1000);
                                    } else {
                                        guardifyShowMsg(res.data && res.data.message ? res.data.message : 'Deactivation failed.', 'error');
                                        btn.disabled = false;
                                        btn.textContent = 'Deactivate';
                                    }
                                } catch(e) {
                                    guardifyShowMsg('Server error.', 'error');
                                    btn.disabled = false;
                                    btn.textContent = 'Deactivate';
                                }
                            };
                            var fd = new FormData();
                            fd.append('action', 'guardify_deactivate_license');
                            fd.append('nonce', guardify_nonce);
                            xhr.send(fd);
                        }

                        function guardifyVerifyLicense(btn) {
                            btn.disabled = true;
                            btn.innerHTML = '<span class="dashicons dashicons-update" style="animation:rotation 1s linear infinite"></span> Verifying...';
                            var xhr = new XMLHttpRequest();
                            xhr.open('POST', guardify_ajax_url, true);
                            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                            xhr.onreadystatechange = function() {
                                if (xhr.readyState !== 4) return;
                                try {
                                    var res = JSON.parse(xhr.responseText);
                                    if (res.success) {
                                        guardifyShowMsg(res.data.message || 'License verified.', 'success');
                                        setTimeout(function(){ location.reload(); }, 1500);
                                    } else {
                                        guardifyShowMsg(res.data && res.data.message ? res.data.message : 'Verification failed.', 'error');
                                        btn.disabled = false;
                                        btn.innerHTML = '<span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;line-height:1.4;"></span> Verify License';
                                    }
                                } catch(e) {
                                    guardifyShowMsg('Server error.', 'error');
                                    btn.disabled = false;
                                    btn.innerHTML = '<span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;line-height:1.4;"></span> Verify License';
                                }
                            };
                            var fd = new FormData();
                            fd.append('action', 'guardify_check_license');
                            fd.append('nonce', guardify_nonce);
                            xhr.send(fd);
                        }

                        function guardifySsoLogin(btn) {
                            btn.disabled = true;
                            btn.innerHTML = '<span class="dashicons dashicons-update" style="animation:rotation 1s linear infinite;font-size:18px;width:18px;height:18px;"></span> Connecting...';
                            var xhr = new XMLHttpRequest();
                            xhr.open('POST', guardify_ajax_url, true);
                            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                            xhr.onreadystatechange = function() {
                                if (xhr.readyState !== 4) return;
                                try {
                                    var res = JSON.parse(xhr.responseText);
                                    if (res.success && res.data.sso_url) {
                                        window.open(res.data.sso_url, '_blank');
                                        guardifyShowMsg('Console opened in a new tab.', 'success');
                                    } else {
                                        guardifyShowMsg(res.data && res.data.message ? res.data.message : 'SSO login failed.', 'error');
                                    }
                                } catch(e) {
                                    guardifyShowMsg('Server error.', 'error');
                                }
                                btn.disabled = false;
                                btn.innerHTML = '<span class="dashicons dashicons-admin-links" style="font-size:18px;width:18px;height:18px;"></span> Open Console';
                            };
                            var fd = new FormData();
                            fd.append('action', 'guardify_sso_login');
                            fd.append('nonce', guardify_nonce);
                            xhr.send(fd);
                        }

                        /* Enter key on license input triggers activate */
                        document.addEventListener('DOMContentLoaded', function() {
                            var inp = document.getElementById('guardify-api-key');
                            if (inp) {
                                inp.addEventListener('keydown', function(e) {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        e.stopPropagation();
                                        var btn = document.getElementById('guardify-activate-license');
                                        if (btn && !btn.disabled) guardifyActivateLicense(btn);
                                    }
                                });
                            }
                        });
                        </script>
                        <style data-no-optimize="1">@keyframes rotation{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}</style>

                        <!-- Quick Stats -->
                        <div class="guardify-stats-row">
                            <div class="guardify-stat-card">
                                <div class="stat-icon phone">
                                    <span class="dashicons dashicons-phone"></span>
                                </div>
                                <div class="stat-content">
                                    <h3><?php echo $bd_phone_validation_enabled === '1' ? '<span class="status-on">ON</span>' : '<span class="status-off">OFF</span>'; ?></h3>
                                    <p>Phone Validation</p>
                                </div>
                            </div>
                            <div class="guardify-stat-card">
                                <div class="stat-icon cooldown">
                                    <span class="dashicons dashicons-clock"></span>
                                </div>
                                <div class="stat-content">
                                    <h3><?php echo $phone_cooldown_enabled === '1' ? '<span class="status-on">ON</span>' : '<span class="status-off">OFF</span>'; ?></h3>
                                    <p>Phone Cooldown</p>
                                </div>
                            </div>
                            <div class="guardify-stat-card">
                                <div class="stat-icon ip">
                                    <span class="dashicons dashicons-admin-site-alt3"></span>
                                </div>
                                <div class="stat-content">
                                    <h3><?php echo $ip_cooldown_enabled === '1' ? '<span class="status-on">ON</span>' : '<span class="status-off">OFF</span>'; ?></h3>
                                    <p>IP Cooldown</p>
                                </div>
                            </div>
                            <div class="guardify-stat-card">
                                <div class="stat-icon tracking">
                                    <span class="dashicons dashicons-visibility"></span>
                                </div>
                                <div class="stat-content">
                                    <h3><?php echo $order_completed_by_enabled === '1' ? '<span class="status-on">ON</span>' : '<span class="status-off">OFF</span>'; ?></h3>
                                    <p>Order Tracking</p>
                                </div>
                            </div>
                            <div class="guardify-stat-card">
                                <div class="stat-icon vpn">
                                    <span class="dashicons dashicons-shield-alt"></span>
                                </div>
                                <div class="stat-content">
                                    <h3><?php echo $vpn_block_enabled === '1' ? '<span class="status-on">ON</span>' : '<span class="status-off">OFF</span>'; ?></h3>
                                    <p>VPN Block</p>
                                </div>
                            </div>
                            <?php
                            $whitelist_enabled = get_option('guardify_whitelist_enabled', '0');
                            $address_detection_enabled = get_option('guardify_address_detection_enabled', '0');
                            $notification_enabled = get_option('guardify_notification_enabled', '0');
                            ?>
                            <div class="guardify-stat-card">
                                <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                                    <span class="dashicons dashicons-list-view"></span>
                                </div>
                                <div class="stat-content">
                                    <h3><?php echo $whitelist_enabled === '1' ? '<span class="status-on">ON</span>' : '<span class="status-off">OFF</span>'; ?></h3>
                                    <p>Whitelist</p>
                                </div>
                            </div>
                            <div class="guardify-stat-card">
                                <div class="stat-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                                    <span class="dashicons dashicons-location"></span>
                                </div>
                                <div class="stat-content">
                                    <h3><?php echo $address_detection_enabled === '1' ? '<span class="status-on">ON</span>' : '<span class="status-off">OFF</span>'; ?></h3>
                                    <p>Address Check</p>
                                </div>
                            </div>
                            <div class="guardify-stat-card">
                                <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                                    <span class="dashicons dashicons-email-alt"></span>
                                </div>
                                <div class="stat-content">
                                    <h3><?php echo $notification_enabled === '1' ? '<span class="status-on">ON</span>' : '<span class="status-off">OFF</span>'; ?></h3>
                                    <p>Notifications</p>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="guardify-card guardify-quick-actions-modern">
                            <div class="guardify-modern-header">
                                <div class="modern-header-icon">
                                    <span class="dashicons dashicons-admin-generic"></span>
                                </div>
                                <div class="modern-header-text">
                                    <h2><?php _e('Quick Actions', 'guardify'); ?></h2>
                                    <p class="description"><?php _e('Fast access to essential settings and features', 'guardify'); ?></p>
                                </div>
                            </div>
                            <div class="guardify-actions-modern-grid">
                                <a href="?page=guardify-settings&tab=validation" class="guardify-modern-action-card">
                                    <div class="action-card-icon phone-gradient">
                                        <span class="dashicons dashicons-phone"></span>
                                    </div>
                                    <div class="action-card-content">
                                        <h3><?php _e('Phone Validation', 'guardify'); ?></h3>
                                        <p><?php _e('Configure validation rules', 'guardify'); ?></p>
                                    </div>
                                    <span class="action-arrow dashicons dashicons-arrow-right-alt2"></span>
                                </a>
                                <a href="?page=guardify-settings&tab=cooldown" class="guardify-modern-action-card">
                                    <div class="action-card-icon cooldown-gradient">
                                        <span class="dashicons dashicons-clock"></span>
                                    </div>
                                    <div class="action-card-content">
                                        <h3><?php _e('Cooldown Settings', 'guardify'); ?></h3>
                                        <p><?php _e('Setup cooldown rules', 'guardify'); ?></p>
                                    </div>
                                    <span class="action-arrow dashicons dashicons-arrow-right-alt2"></span>
                                </a>
                                <a href="?page=guardify-settings&tab=advanced" class="guardify-modern-action-card">
                                    <div class="action-card-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                                        <span class="dashicons dashicons-shield"></span>
                                    </div>
                                    <div class="action-card-content">
                                        <h3><?php _e('Advanced Protection', 'guardify'); ?></h3>
                                        <p><?php _e('Whitelist, Address, Notifications', 'guardify'); ?></p>
                                    </div>
                                    <span class="action-arrow dashicons dashicons-arrow-right-alt2"></span>
                                </a>
                                <a href="?page=guardify-settings&tab=analytics" class="guardify-modern-action-card">
                                    <div class="action-card-icon" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                                        <span class="dashicons dashicons-chart-area"></span>
                                    </div>
                                    <div class="action-card-content">
                                        <h3><?php _e('Analytics', 'guardify'); ?></h3>
                                        <p><?php _e('View blocked attempts stats', 'guardify'); ?></p>
                                    </div>
                                    <span class="action-arrow dashicons dashicons-arrow-right-alt2"></span>
                                </a>
                                <a href="?page=guardify-settings&tab=tracking" class="guardify-modern-action-card">
                                    <div class="action-card-icon tracking-gradient">
                                        <span class="dashicons dashicons-visibility"></span>
                                    </div>
                                    <div class="action-card-content">
                                        <h3><?php _e('Order Tracking', 'guardify'); ?></h3>
                                        <p><?php _e('Track status changes', 'guardify'); ?></p>
                                    </div>
                                    <span class="action-arrow dashicons dashicons-arrow-right-alt2"></span>
                                </a>
                                <a href="<?php echo admin_url('edit.php?post_type=shop_order'); ?>" class="guardify-modern-action-card">
                                    <div class="action-card-icon orders-gradient">
                                        <span class="dashicons dashicons-cart"></span>
                                    </div>
                                    <div class="action-card-content">
                                        <h3><?php _e('View Orders', 'guardify'); ?></h3>
                                        <p><?php _e('Manage all orders', 'guardify'); ?></p>
                                    </div>
                                    <span class="action-arrow dashicons dashicons-arrow-right-alt2"></span>
                                </a>
                            </div>
                        </div>

                        <!-- Open Console SSO Card -->
                        <?php if (!empty($api_key)): ?>
                        <div class="guardify-card guardify-console-card">
                            <div class="guardify-modern-header">
                                <div class="modern-header-icon" style="background: linear-gradient(135deg, #0d9488 0%, #2db7ae 100%);">
                                    <span class="dashicons dashicons-external"></span>
                                </div>
                                <div class="modern-header-text">
                                    <h2><?php _e('TansiqLabs Console', 'guardify'); ?></h2>
                                    <p class="description"><?php _e('Manage your sites, Steadfast config, and more from the console', 'guardify'); ?></p>
                                </div>
                            </div>
                            <div style="padding: 0 20px 20px;">
                                <button type="button" id="guardify-sso-login" class="guardify-btn-primary" style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; font-size: 14px; font-weight: 600; width: 100%; justify-content: center;">
                                    <span class="dashicons dashicons-admin-links" style="font-size: 18px; width: 18px; height: 18px;"></span>
                                    <?php _e('Open Console', 'guardify'); ?>
                                </button>
                                <p style="text-align: center; margin-top: 10px; font-size: 12px; color: var(--g-slate-400);">
                                    <?php _e('Secure SSO — opens in a new tab', 'guardify'); ?>
                                </p>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- About Card -->
                        <div class="guardify-card guardify-about-card">
                            <div class="guardify-card-header">
                                <h2><?php _e('About Guardify', 'guardify'); ?></h2>
                            </div>
                            <div class="guardify-about-content">
                                <p class="about-description"><strong>Guardify</strong> is a powerful WooCommerce fraud prevention plugin designed specifically for the Bangladesh e-commerce market.</p>
                                
                                <ul class="guardify-feature-list">
                                    <li><span class="dashicons dashicons-yes-alt"></span> Bangladesh phone number validation</li>
                                    <li><span class="dashicons dashicons-yes-alt"></span> Phone-based order cooldown</li>
                                    <li><span class="dashicons dashicons-yes-alt"></span> IP-based order cooldown</li>
                                    <li><span class="dashicons dashicons-yes-alt"></span> Whitelist trusted phones & IPs</li>
                                    <li><span class="dashicons dashicons-yes-alt"></span> Same address detection</li>
                                    <li><span class="dashicons dashicons-yes-alt"></span> Similar name detection</li>
                                    <li><span class="dashicons dashicons-yes-alt"></span> Email fraud notifications</li>
                                    <li><span class="dashicons dashicons-yes-alt"></span> Analytics dashboard</li>
                                </ul>
                                
                                <div class="guardify-developer-info">
                                    <p><strong><?php _e('Developed by:', 'guardify'); ?></strong> <a href="https://tansiqlabs.com" target="_blank">Tansiq Labs</a></p>
                                    <p><strong><?php _e('Version:', 'guardify'); ?></strong> <?php echo GUARDIFY_VERSION; ?></p>
                                    <p><strong><?php _e('Support:', 'guardify'); ?></strong> <a href="mailto:support@tansiqlabs.com">support@tansiqlabs.com</a></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Validation Tab -->
                <div class="guardify-tab-content <?php echo $active_tab === 'validation' ? 'active' : ''; ?>" data-tab="validation">
                    <div class="guardify-card">
                        <div class="guardify-card-header">
                            <h2><?php _e('Bangladesh Phone Number Validation', 'guardify'); ?></h2>
                            <p class="description"><?php _e('Only valid Bangladeshi mobile numbers will be accepted for orders. Supported operators: 013, 014, 015, 016, 017, 018, 019', 'guardify'); ?></p>
                        </div>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Enable Feature', 'guardify'); ?></th>
                                <td>
                                    <label class="guardify-toggle">
                                        <input type="checkbox" name="guardify_bd_phone_validation_enabled" value="1" <?php checked($bd_phone_validation_enabled, '1'); ?>>
                                        <span class="guardify-toggle-slider"></span>
                                    </label>
                                    <span class="toggle-label"><?php echo $bd_phone_validation_enabled === '1' ? '✓ Enabled' : 'Disabled'; ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Error Message', 'guardify'); ?></th>
                                <td>
                                    <textarea name="guardify_bd_phone_validation_message" rows="3" class="large-text guardify-textarea"><?php echo esc_textarea($bd_phone_validation_message); ?></textarea>
                                    <p class="description"><?php _e('Message shown when invalid number is entered.', 'guardify'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Cooldown Tab -->
                <div class="guardify-tab-content <?php echo $active_tab === 'cooldown' ? 'active' : ''; ?>" data-tab="cooldown">
                    <!-- Phone Cooldown -->
                    <div class="guardify-card">
                        <div class="guardify-card-header">
                            <h2><?php _e('Phone Number Cooldown', 'guardify'); ?></h2>
                            <p class="description"><?php _e('Prevent customers from placing multiple orders using the same phone number within a specified time period.', 'guardify'); ?></p>
                        </div>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Enable Feature', 'guardify'); ?></th>
                                <td>
                                    <label class="guardify-toggle">
                                        <input type="checkbox" name="guardify_phone_cooldown_enabled" value="1" <?php checked($phone_cooldown_enabled, '1'); ?>>
                                        <span class="guardify-toggle-slider"></span>
                                    </label>
                                    <span class="toggle-label"><?php echo $phone_cooldown_enabled === '1' ? '✓ Enabled' : 'Disabled'; ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Cooldown Time (Hours)', 'guardify'); ?></th>
                                <td>
                                    <div class="guardify-input-group">
                                        <input type="number" name="guardify_phone_cooldown_time" value="<?php echo esc_attr($phone_cooldown_time); ?>" min="1" max="720" class="small-text guardify-number-input">
                                        <span class="input-suffix">hours</span>
                                    </div>
                                    <p class="description"><?php _e('Set between 1-720 hours (Default: 24 hours)', 'guardify'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Error Message', 'guardify'); ?></th>
                                <td>
                                    <textarea name="guardify_phone_cooldown_message" rows="3" class="large-text guardify-textarea"><?php echo esc_textarea($phone_cooldown_message); ?></textarea>
                                    <p class="description"><?php _e('%d will be automatically replaced with the number of hours.', 'guardify'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- IP Cooldown -->
                    <div class="guardify-card" style="margin-top: 20px;">
                        <div class="guardify-card-header">
                            <h2><?php _e('IP Address Cooldown', 'guardify'); ?></h2>
                            <p class="description"><?php _e('Prevent customers from placing multiple orders from the same IP address within a specified time period.', 'guardify'); ?></p>
                        </div>
                        
                        <?php 
                        // Display current IP for debugging
                        $cooldown = Guardify_Order_Cooldown::get_instance();
                        $current_ip = $cooldown->get_current_ip();
                        ?>
                        <div class="guardify-info-box" style="margin: 16px 24px; padding: 16px; background: #e7f3ff; border-left: 4px solid #2196F3; border-radius: 8px;">
                            <p style="margin: 0; font-size: 13px; color: #1976D2;">
                                <span class="dashicons dashicons-info" style="font-size: 16px; vertical-align: middle;"></span>
                                <strong><?php _e('Your Current IP Address:', 'guardify'); ?></strong> 
                                <code style="background: #fff; padding: 4px 8px; border-radius: 4px; font-weight: 600;"><?php echo esc_html($current_ip); ?></code>
                            </p>
                            <p style="margin: 8px 0 0 0; font-size: 12px; color: #555;">
                                <?php _e('This is the IP address that will be tracked for cooldown. If using Cloudflare or proxy, the real visitor IP will be detected.', 'guardify'); ?>
                            </p>
                        </div>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Enable Feature', 'guardify'); ?></th>
                                <td>
                                    <label class="guardify-toggle">
                                        <input type="checkbox" name="guardify_ip_cooldown_enabled" value="1" <?php checked($ip_cooldown_enabled, '1'); ?>>
                                        <span class="guardify-toggle-slider"></span>
                                    </label>
                                    <span class="toggle-label"><?php echo $ip_cooldown_enabled === '1' ? '✓ Enabled' : 'Disabled'; ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Cooldown Time (Hours)', 'guardify'); ?></th>
                                <td>
                                    <div class="guardify-input-group">
                                        <input type="number" name="guardify_ip_cooldown_time" value="<?php echo esc_attr($ip_cooldown_time); ?>" min="1" max="720" class="small-text guardify-number-input">
                                        <span class="input-suffix">hours</span>
                                    </div>
                                    <p class="description"><?php _e('Set between 1-720 hours (Default: 1 hour)', 'guardify'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Error Message', 'guardify'); ?></th>
                                <td>
                                    <textarea name="guardify_ip_cooldown_message" rows="3" class="large-text guardify-textarea"><?php echo esc_textarea($ip_cooldown_message); ?></textarea>
                                    <p class="description"><?php _e('%d will be automatically replaced with the number of hours.', 'guardify'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Contact Number for Cooldown Popup -->
                    <div class="guardify-card" style="margin-top: 20px;">
                        <div class="guardify-card-header">
                            <h2><?php _e('Support Contact Number', 'guardify'); ?></h2>
                            <p class="description"><?php _e('Display contact number in cooldown popup for customers to call if they have questions.', 'guardify'); ?></p>
                        </div>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Contact Number', 'guardify'); ?></th>
                                <td>
                                    <div class="guardify-input-group">
                                        <span class="dashicons dashicons-phone input-icon" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #2db7ae;"></span>
                                        <input type="text" name="guardify_contact_number" value="<?php echo esc_attr($contact_number); ?>" placeholder="01712345678" class="regular-text" style="padding-left: 40px;">
                                    </div>
                                    <p class="description"><?php _e('Enter your support/customer service phone number. This will be displayed as a clickable call button in the cooldown popup.', 'guardify'); ?></p>
                                    <p class="description" style="color: #2db7ae; margin-top: 8px;">
                                        <span class="dashicons dashicons-info" style="font-size: 16px; vertical-align: middle;"></span>
                                        <?php _e('Example: When customer is blocked, they can directly call this number from the popup.', 'guardify'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- VPN/Proxy Block -->
                    <div class="guardify-card" style="margin-top: 20px;">
                        <div class="guardify-card-header">
                            <h2><?php _e('VPN/Proxy Detection & Block', 'guardify'); ?></h2>
                            <p class="description"><?php _e('Detect and block orders from VPN, Proxy, and datacenter IPs to prevent fraud.', 'guardify'); ?></p>
                        </div>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Enable Feature', 'guardify'); ?></th>
                                <td>
                                    <label class="guardify-toggle">
                                        <input type="checkbox" name="guardify_vpn_block_enabled" value="1" <?php checked($vpn_block_enabled, '1'); ?>>
                                        <span class="guardify-toggle-slider"></span>
                                    </label>
                                    <span class="toggle-label"><?php echo $vpn_block_enabled === '1' ? '✓ Enabled' : 'Disabled'; ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Block Message', 'guardify'); ?></th>
                                <td>
                                    <textarea name="guardify_vpn_block_message" rows="3" class="large-text guardify-textarea"><?php echo esc_textarea($vpn_block_message); ?></textarea>
                                    <p class="description"><?php _e('This message will be shown to users trying to order via VPN/Proxy.', 'guardify'); ?></p>
                                </td>
                            </tr>
                        </table>
                        <div class="guardify-info-box">
                            <span class="dashicons dashicons-info"></span>
                            <div>
                                <strong><?php _e('Detection Methods:', 'guardify'); ?></strong>
                                <ul style="margin: 8px 0 0 20px;">
                                    <li><?php _e('Proxy/VPN headers detection', 'guardify'); ?></li>
                                    <li><?php _e('Datacenter IP detection', 'guardify'); ?></li>
                                    <li><?php _e('CloudFlare and legitimate CDN whitelisting', 'guardify'); ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Blocked Orders (Cooldown) -->
                    <?php $this->render_recent_blocked_orders_section(); ?>
                </div>

                <!-- Tracking Tab -->
                <div class="guardify-tab-content <?php echo $active_tab === 'tracking' ? 'active' : ''; ?>" data-tab="tracking">
                    <!-- Order Completed By -->
                    <div class="guardify-card">
                        <div class="guardify-card-header">
                            <h2><?php _e('Order Status Tracking', 'guardify'); ?></h2>
                            <p class="description"><?php _e('Track who changed order statuses. Adds a "Status Changed By" column to the WooCommerce orders list.', 'guardify'); ?></p>
                        </div>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Enable Feature', 'guardify'); ?></th>
                                <td>
                                    <label class="guardify-toggle">
                                        <input type="checkbox" name="guardify_order_completed_by_enabled" value="1" <?php checked($order_completed_by_enabled, '1'); ?>>
                                        <span class="guardify-toggle-slider"></span>
                                    </label>
                                    <span class="toggle-label"><?php echo $order_completed_by_enabled === '1' ? '✓ Enabled' : 'Disabled'; ?></span>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Phone History -->
                    <div class="guardify-card" style="margin-top: 20px;">
                        <div class="guardify-card-header">
                            <h2><?php _e('Phone Number Order History', 'guardify'); ?></h2>
                            <p class="description"><?php _e('View all orders from a specific phone number. Adds an "Order History" column to the WooCommerce orders list.', 'guardify'); ?></p>
                        </div>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Enable Feature', 'guardify'); ?></th>
                                <td>
                                    <label class="guardify-toggle">
                                        <input type="checkbox" name="guardify_phone_history_enabled" value="1" <?php checked($phone_history_enabled, '1'); ?>>
                                        <span class="guardify-toggle-slider"></span>
                                    </label>
                                    <span class="toggle-label"><?php echo $phone_history_enabled === '1' ? '✓ Enabled' : 'Disabled'; ?></span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- User Colors Tab -->
                <div class="guardify-tab-content <?php echo $active_tab === 'colors' ? 'active' : ''; ?>" data-tab="colors">
                    <div class="guardify-card">
                        <div class="guardify-card-header">
                            <h2><?php _e('User Color Assignment', 'guardify'); ?></h2>
                            <p class="description"><?php _e('Assign unique colors to users who change order statuses. These colors will appear in the "Status Changed By" column.', 'guardify'); ?></p>
                        </div>
                        
                        <?php
                        // Get all users who have changed order statuses
                        $user_colors = get_option('guardify_user_colors', array());
                        $users = $this->get_users_who_changed_orders();
                        
                        if (empty($users)) {
                            echo '<div class="guardify-empty-state">';
                            echo '<span class="dashicons dashicons-admin-users"></span>';
                            echo '<p>' . __('No users have changed order statuses yet.', 'guardify') . '</p>';
                            echo '</div>';
                        } else {
                            ?>
                            <div class="guardify-color-assignment-grid">
                                <?php foreach ($users as $user_id => $user_name): 
                                    $assigned_color = isset($user_colors[$user_id]) ? $user_colors[$user_id] : 0;
                                    $color_scheme = $this->get_color_scheme($assigned_color);
                                ?>
                                    <div class="guardify-user-color-row">
                                        <div class="guardify-user-info">
                                            <span class="dashicons dashicons-admin-users"></span>
                                            <strong><?php echo esc_html($user_name); ?></strong>
                                            <span class="guardify-user-id">(ID: <?php echo $user_id; ?>)</span>
                                        </div>
                                        <div class="guardify-color-picker">
                                            <label><?php _e('Select Color:', 'guardify'); ?></label>
                                            <select name="guardify_user_color[<?php echo $user_id; ?>]" class="guardify-color-select">
                                                <?php for ($i = 0; $i < 10; $i++): 
                                                    $scheme = $this->get_color_scheme($i);
                                                ?>
                                                    <option value="<?php echo $i; ?>" <?php selected($assigned_color, $i); ?>>
                                                        <?php echo $this->get_color_name($i); ?>
                                                    </option>
                                                <?php endfor; ?>
                                            </select>
                                            <div class="guardify-color-preview" style="background: <?php echo esc_attr($color_scheme['bg']); ?>; color: <?php echo esc_attr($color_scheme['text']); ?>;">
                                                <?php echo esc_html($user_name); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Color Legend -->
                            <div class="guardify-card" style="margin-top: 30px;">
                                <div class="guardify-card-header">
                                    <h3><?php _e('Available Colors', 'guardify'); ?></h3>
                                </div>
                                <div class="guardify-color-legend">
                                    <?php for ($i = 0; $i < 10; $i++): 
                                        $scheme = $this->get_color_scheme($i);
                                    ?>
                                        <div class="guardify-legend-item" style="background: <?php echo esc_attr($scheme['bg']); ?>; color: <?php echo esc_attr($scheme['text']); ?>;">
                                            <?php echo $this->get_color_name($i); ?>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                </div>

                <!-- Advanced Tab -->
                <div class="guardify-tab-content <?php echo $active_tab === 'advanced' ? 'active' : ''; ?>" data-tab="advanced">
                    
                    <!-- Whitelist Section -->
                    <div class="guardify-card">
                        <div class="guardify-card-header">
                            <h2><?php _e('Whitelist (বিশ্বস্ত তালিকা)', 'guardify'); ?></h2>
                            <p class="description"><?php _e('বিশ্বস্ত ফোন নম্বর এবং আইপি অ্যাড্রেস যোগ করুন যেগুলো কুলডাউন থেকে মুক্ত থাকবে। অফিস, ওয়্যারহাউজ, বা বিশ্বস্ত গ্রাহকদের আইপি এখানে যোগ করতে পারবেন।', 'guardify'); ?></p>
                        </div>
                        <?php
                        $whitelist_enabled = get_option('guardify_whitelist_enabled', '0');
                        $whitelisted_phones = get_option('guardify_whitelisted_phones', '');
                        $whitelisted_ips = get_option('guardify_whitelisted_ips', '');
                        ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Enable Whitelist', 'guardify'); ?></th>
                                <td>
                                    <label class="guardify-toggle">
                                        <input type="checkbox" name="guardify_whitelist_enabled" value="1" <?php checked($whitelist_enabled, '1'); ?>>
                                        <span class="guardify-toggle-slider"></span>
                                    </label>
                                    <span class="toggle-label"><?php echo $whitelist_enabled === '1' ? '✓ Enabled' : 'Disabled'; ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Whitelisted Phones', 'guardify'); ?></th>
                                <td>
                                    <textarea name="guardify_whitelisted_phones" rows="5" class="large-text guardify-textarea" placeholder="01712345678
01812345678
01912345678"><?php echo esc_textarea($whitelisted_phones); ?></textarea>
                                    <p class="description"><?php _e('প্রতি লাইনে একটি ফোন নম্বর লিখুন। এই নম্বরগুলো কুলডাউন চেক থেকে মুক্ত থাকবে।', 'guardify'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Whitelisted IPs', 'guardify'); ?></th>
                                <td>
                                    <textarea name="guardify_whitelisted_ips" rows="5" class="large-text guardify-textarea" placeholder="103.145.12.45
192.168.1.0/24"><?php echo esc_textarea($whitelisted_ips); ?></textarea>
                                    <p class="description"><?php _e('প্রতি লাইনে একটি আইপি বা আইপি রেঞ্জ (CIDR) লিখুন। অফিস/ওয়্যারহাউজের আইপি এখানে যোগ করুন।', 'guardify'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Address Detection Section -->
                    <div class="guardify-card" style="margin-top: 20px;">
                        <div class="guardify-card-header">
                            <h2><?php _e('Same Address Detection (একই ঠিকানা সনাক্তকরণ)', 'guardify'); ?></h2>
                            <p class="description"><?php _e('একই ঠিকানা থেকে বারবার অর্ডার আটকাতে এই ফিচার ব্যবহার করুন।', 'guardify'); ?></p>
                        </div>
                        <?php
                        $address_detection_enabled = get_option('guardify_address_detection_enabled', '0');
                        $max_orders_per_address = get_option('guardify_max_orders_per_address', '5');
                        $address_time_hours = get_option('guardify_address_time_hours', '24');
                        $address_block_message = get_option('guardify_address_block_message', 'এই ঠিকানা থেকে অনেক অর্ডার হয়েছে। অনুগ্রহ করে যোগাযোগ করুন।');
                        ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Enable Feature', 'guardify'); ?></th>
                                <td>
                                    <label class="guardify-toggle">
                                        <input type="checkbox" name="guardify_address_detection_enabled" value="1" <?php checked($address_detection_enabled, '1'); ?>>
                                        <span class="guardify-toggle-slider"></span>
                                    </label>
                                    <span class="toggle-label"><?php echo $address_detection_enabled === '1' ? '✓ Enabled' : 'Disabled'; ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Max Orders per Address', 'guardify'); ?></th>
                                <td>
                                    <div class="guardify-input-group">
                                        <input type="number" name="guardify_max_orders_per_address" value="<?php echo esc_attr($max_orders_per_address); ?>" min="1" max="100" class="small-text guardify-number-input">
                                        <span class="input-suffix"><?php _e('orders', 'guardify'); ?></span>
                                    </div>
                                    <p class="description"><?php _e('একই ঠিকানা থেকে সর্বোচ্চ কতটি অর্ডার অনুমোদিত।', 'guardify'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Time Period', 'guardify'); ?></th>
                                <td>
                                    <div class="guardify-input-group">
                                        <input type="number" name="guardify_address_time_hours" value="<?php echo esc_attr($address_time_hours); ?>" min="1" max="720" class="small-text guardify-number-input">
                                        <span class="input-suffix"><?php _e('hours', 'guardify'); ?></span>
                                    </div>
                                    <p class="description"><?php _e('এই সময়ের মধ্যে একই ঠিকানা থেকে অর্ডার গণনা করা হবে।', 'guardify'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Block Message', 'guardify'); ?></th>
                                <td>
                                    <textarea name="guardify_address_block_message" rows="3" class="large-text guardify-textarea"><?php echo esc_textarea($address_block_message); ?></textarea>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Name Similarity Detection Section -->
                    <div class="guardify-card" style="margin-top: 20px;">
                        <div class="guardify-card-header">
                            <h2><?php _e('Similar Name Detection (সন্দেহজনক নাম সনাক্তকরণ)', 'guardify'); ?></h2>
                            <p class="description"><?php _e('একই রকম নাম দিয়ে বিভিন্ন নম্বর থেকে অর্ডার করলে তা সনাক্ত করুন।', 'guardify'); ?></p>
                        </div>
                        <?php
                        $name_similarity_enabled = get_option('guardify_name_similarity_enabled', '0');
                        $name_similarity_threshold = get_option('guardify_name_similarity_threshold', '80');
                        $name_check_hours = get_option('guardify_name_check_hours', '24');
                        $similar_name_message = get_option('guardify_similar_name_message', 'সন্দেহজনক অর্ডার প্যাটার্ন সনাক্ত হয়েছে।');
                        ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Enable Feature', 'guardify'); ?></th>
                                <td>
                                    <label class="guardify-toggle">
                                        <input type="checkbox" name="guardify_name_similarity_enabled" value="1" <?php checked($name_similarity_enabled, '1'); ?>>
                                        <span class="guardify-toggle-slider"></span>
                                    </label>
                                    <span class="toggle-label"><?php echo $name_similarity_enabled === '1' ? '✓ Enabled' : 'Disabled'; ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Similarity Threshold', 'guardify'); ?></th>
                                <td>
                                    <div class="guardify-input-group">
                                        <input type="number" name="guardify_name_similarity_threshold" value="<?php echo esc_attr($name_similarity_threshold); ?>" min="50" max="100" class="small-text guardify-number-input">
                                        <span class="input-suffix">%</span>
                                    </div>
                                    <p class="description"><?php _e('নামের কত শতাংশ মিললে সন্দেহজনক বলে বিবেচিত হবে (৫০-১০০%)।', 'guardify'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Check Period', 'guardify'); ?></th>
                                <td>
                                    <div class="guardify-input-group">
                                        <input type="number" name="guardify_name_check_hours" value="<?php echo esc_attr($name_check_hours); ?>" min="1" max="168" class="small-text guardify-number-input">
                                        <span class="input-suffix"><?php _e('hours', 'guardify'); ?></span>
                                    </div>
                                    <p class="description"><?php _e('গত কত ঘণ্টার অর্ডারের সাথে তুলনা করা হবে।', 'guardify'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Block Message', 'guardify'); ?></th>
                                <td>
                                    <textarea name="guardify_similar_name_message" rows="3" class="large-text guardify-textarea"><?php echo esc_textarea($similar_name_message); ?></textarea>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Notification Settings -->
                    <div class="guardify-card" style="margin-top: 20px;">
                        <div class="guardify-card-header">
                            <h2><?php _e('Notification Settings (নোটিফিকেশন সেটিংস)', 'guardify'); ?></h2>
                            <p class="description"><?php _e('সন্দেহজনক অর্ডার ব্লক হলে ইমেইল নোটিফিকেশন পান।', 'guardify'); ?></p>
                        </div>
                        <?php
                        $notification_enabled = get_option('guardify_notification_enabled', '0');
                        $email_notification = get_option('guardify_email_notification', '1');
                        $notification_email = get_option('guardify_notification_email', get_option('admin_email'));
                        ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Enable Notifications', 'guardify'); ?></th>
                                <td>
                                    <label class="guardify-toggle">
                                        <input type="checkbox" name="guardify_notification_enabled" value="1" <?php checked($notification_enabled, '1'); ?>>
                                        <span class="guardify-toggle-slider"></span>
                                    </label>
                                    <span class="toggle-label"><?php echo $notification_enabled === '1' ? '✓ Enabled' : 'Disabled'; ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Email Notification', 'guardify'); ?></th>
                                <td>
                                    <label class="guardify-toggle">
                                        <input type="checkbox" name="guardify_email_notification" value="1" <?php checked($email_notification, '1'); ?>>
                                        <span class="guardify-toggle-slider"></span>
                                    </label>
                                    <span class="toggle-label"><?php echo $email_notification === '1' ? '✓ Enabled' : 'Disabled'; ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Notification Email', 'guardify'); ?></th>
                                <td>
                                    <input type="email" name="guardify_notification_email" value="<?php echo esc_attr($notification_email); ?>" class="regular-text" placeholder="admin@example.com">
                                    <p class="description"><?php _e('যে ইমেইলে নোটিফিকেশন পাঠানো হবে।', 'guardify'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Analytics Tab -->
                <div class="guardify-tab-content <?php echo $active_tab === 'analytics' ? 'active' : ''; ?>" data-tab="analytics">
                    <?php $this->render_analytics_tab(); ?>
                </div>

                <!-- Submit Button -->
                <div class="guardify-submit">
                    <button type="submit" name="guardify_save_settings" class="guardify-btn-primary">
                        <span class="dashicons dashicons-saved"></span>
                        <?php _e('Save Settings', 'guardify'); ?>
                    </button>
                </div>
            </form>

            <!-- Footer -->
            <div class="guardify-footer">
                <p><?php _e('Developed by', 'guardify'); ?> <a href="https://tansiqlabs.com/" target="_blank">Tansiq Labs</a></p>
            </div>
        </div>
        <?php
    }

    /**
     * Get users who have changed order statuses
     */
    private function get_users_who_changed_orders() {
        global $wpdb;
        
        $users = array();
        
        // Check both HPOS and legacy tables
        $hpos_table = $wpdb->prefix . 'wc_orders_meta';
        $legacy_table = $wpdb->prefix . 'postmeta';
        
        // Check if HPOS table exists
        $hpos_exists = $wpdb->get_var("SHOW TABLES LIKE '{$hpos_table}'") === $hpos_table;
        
        if ($hpos_exists) {
            // Query HPOS table
            $results = $wpdb->get_results("
                SELECT DISTINCT meta_value as user_id
                FROM {$hpos_table}
                WHERE meta_key = '_guardify_status_changed_by'
                AND meta_value != ''
            ");
        } else {
            // Query legacy postmeta table
            $results = $wpdb->get_results("
                SELECT DISTINCT meta_value as user_id
                FROM {$legacy_table}
                WHERE meta_key = '_guardify_status_changed_by'
                AND meta_value != ''
            ");
        }
        
        if ($results) {
            foreach ($results as $result) {
                $user_id = intval($result->user_id);
                $user = get_userdata($user_id);
                if ($user) {
                    $users[$user_id] = $user->display_name;
                }
            }
        }
        
        return $users;
    }

    /**
     * Get color scheme by index
     */
    private function get_color_scheme($index) {
        $color_schemes = array(
            0 => array('bg' => '#667eea', 'text' => '#ffffff'),
            1 => array('bg' => '#f093fb', 'text' => '#ffffff'),
            2 => array('bg' => '#4facfe', 'text' => '#ffffff'),
            3 => array('bg' => '#43e97b', 'text' => '#ffffff'),
            4 => array('bg' => '#fa709a', 'text' => '#ffffff'),
            5 => array('bg' => '#feca57', 'text' => '#2c3e50'),
            6 => array('bg' => '#ff6348', 'text' => '#ffffff'),
            7 => array('bg' => '#00d2ff', 'text' => '#ffffff'),
            8 => array('bg' => '#a29bfe', 'text' => '#ffffff'),
            9 => array('bg' => '#fd79a8', 'text' => '#ffffff'),
        );
        
        return isset($color_schemes[$index]) ? $color_schemes[$index] : $color_schemes[0];
    }

    /**
     * Get color name by index
     */
    private function get_color_name($index) {
        $color_names = array(
            0 => __('Purple', 'guardify'),
            1 => __('Pink', 'guardify'),
            2 => __('Blue', 'guardify'),
            3 => __('Green', 'guardify'),
            4 => __('Rose', 'guardify'),
            5 => __('Yellow', 'guardify'),
            6 => __('Red', 'guardify'),
            7 => __('Cyan', 'guardify'),
            8 => __('Lavender', 'guardify'),
            9 => __('Flamingo', 'guardify'),
        );
        
        return isset($color_names[$index]) ? $color_names[$index] : $color_names[0];
    }
    
    /**
     * AJAX handler for license activation via TansiqLabs API
     */
    public function ajax_activate_license() {
        check_ajax_referer('guardify_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
        }
        
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        
        if (empty($api_key)) {
            wp_send_json_error(array('message' => __('License key is required', 'guardify')));
        }
        
        // Call TansiqLabs API to activate
        $response = wp_remote_post(self::TANSIQLABS_API_URL . 'activate', array(
            'timeout' => 30,
            'sslverify' => true,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'license_key' => $api_key,
                'site_url' => home_url(),
                'wp_version' => get_bloginfo('version'),
                'plugin_version' => GUARDIFY_VERSION,
                'php_version' => PHP_VERSION,
            )),
        ));
        
        if (is_wp_error($response)) {
            // Network error: Save key but mark as pending verification
            update_option('guardify_api_key', $api_key);
            update_option('guardify_license_status', 'pending_verification');
            
            $error_message = $response->get_error_message();
            wp_send_json_error(array(
                'message' => sprintf(
                    __('Could not connect to license server: %s. Key saved — click "Verify License" to try again.', 'guardify'),
                    $error_message
                ),
            ));
            return;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Handle non-JSON responses (server errors, proxy issues)
        if ($data === null) {
            update_option('guardify_api_key', $api_key);
            update_option('guardify_license_status', 'pending_verification');
            
            wp_send_json_error(array(
                'message' => sprintf(
                    __('Server returned invalid response (HTTP %d). Key saved — click "Verify License" to try again.', 'guardify'),
                    $status_code
                ),
            ));
            return;
        }
        
        if ($status_code === 200 && !empty($data['success'])) {
            // Success - save license info
            update_option('guardify_api_key', $api_key);
            update_option('guardify_license_status', $data['license']['status'] ?? 'active');
            update_option('guardify_license_expiry', $data['license']['expires_at'] ?? '');
            update_option('guardify_license_plan', $data['license']['plan'] ?? 'starter');
            update_option('guardify_license_max_sites', $data['license']['max_sites'] ?? 1);
            update_option('guardify_license_active_sites', $data['license']['active_sites'] ?? 1);
            update_option('guardify_license_days_remaining', $data['license']['days_remaining'] ?? 0);
            update_option('guardify_license_features', $data['license']['features'] ?? array());
            update_option('guardify_license_last_check', gmdate('Y-m-d H:i:s'));
            update_option('guardify_license_validation_failures', 0);
            
            // Auto-store the site API key for log ingestion (merged key system)
            if (!empty($data['site_api_key'])) {
                update_option('guardify_site_api_key', sanitize_text_field($data['site_api_key']));
            }
            
            // Ensure the daily cron is scheduled for periodic validation
            if (!wp_next_scheduled('guardify_daily_cleanup')) {
                wp_schedule_event(time(), 'daily', 'guardify_daily_cleanup');
            }
            
            wp_send_json_success(array(
                'message' => $data['message'] ?? __('License activated successfully!', 'guardify'),
                'reload' => true
            ));
        } else {
            // API returned an error — show the real error, do NOT silently activate
            $error_msg = $data['message'] ?? __('License activation failed. Please check your key and try again.', 'guardify');
            
            // Save the key so user doesn't have to re-enter it
            update_option('guardify_api_key', $api_key);
            update_option('guardify_license_status', 'error');
            
            // If server returned license info (e.g., expired), save it for display
            if (!empty($data['license'])) {
                update_option('guardify_license_status', $data['license']['status'] ?? 'error');
                update_option('guardify_license_expiry', $data['license']['expires_at'] ?? '');
                update_option('guardify_license_plan', $data['license']['plan'] ?? '');
            }
            
            wp_send_json_error(array(
                'message' => $error_msg,
            ));
        }
    }
    
    /**
     * AJAX handler for license deactivation
     */
    public function ajax_deactivate_license() {
        check_ajax_referer('guardify_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
        }
        
        $api_key = get_option('guardify_api_key', '');
        
        if (empty($api_key)) {
            wp_send_json_error(array('message' => __('No license to deactivate', 'guardify')));
        }
        
        // Call TansiqLabs API to deactivate
        $response = wp_remote_post(self::TANSIQLABS_API_URL . 'deactivate', array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'license_key' => $api_key,
                'site_url' => home_url(),
            )),
        ));
        
        // Clear local license data regardless of API response
        delete_option('guardify_api_key');
        delete_option('guardify_site_api_key');
        delete_option('guardify_license_status');
        delete_option('guardify_license_expiry');
        delete_option('guardify_license_plan');
        delete_option('guardify_license_max_sites');
        delete_option('guardify_license_active_sites');
        delete_option('guardify_license_days_remaining');
        delete_option('guardify_license_features');
        delete_option('guardify_license_last_check');
        delete_option('guardify_license_validation_failures');
        
        wp_send_json_success(array(
            'message' => __('License deactivated successfully. This site slot is now free.', 'guardify'),
            'reload' => true
        ));
    }

    /**
     * Non-AJAX fallback: form-based license activation (for <noscript> / JS-blocked environments)
     */
    public function handle_activate_license_form() {
        check_admin_referer('guardify_license_form_nonce', '_guardify_license_nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied', 'guardify'));
        }

        $api_key = isset($_POST['guardify_license_key']) ? sanitize_text_field($_POST['guardify_license_key']) : '';

        if (empty($api_key)) {
            set_transient('guardify_license_form_msg', array('type' => 'error', 'message' => __('License key is required.', 'guardify')), 60);
            wp_safe_redirect(admin_url('admin.php?page=guardify-settings'));
            exit;
        }

        $response = wp_remote_post(self::TANSIQLABS_API_URL . 'activate', array(
            'timeout'   => 30,
            'sslverify' => true,
            'headers'   => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
            'body'      => wp_json_encode(array(
                'license_key'    => $api_key,
                'site_url'       => home_url(),
                'wp_version'     => get_bloginfo('version'),
                'plugin_version' => GUARDIFY_VERSION,
                'php_version'    => PHP_VERSION,
            )),
        ));

        if (is_wp_error($response)) {
            update_option('guardify_api_key', $api_key);
            update_option('guardify_license_status', 'pending_verification');
            set_transient('guardify_license_form_msg', array('type' => 'error', 'message' => sprintf(__('Could not connect to license server: %s. Key saved — click "Verify License" to try again.', 'guardify'), $response->get_error_message())), 60);
            wp_safe_redirect(admin_url('admin.php?page=guardify-settings'));
            exit;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 200 && !empty($data['success'])) {
            update_option('guardify_api_key', $api_key);
            update_option('guardify_license_status', $data['license']['status'] ?? 'active');
            update_option('guardify_license_expiry', $data['license']['expires_at'] ?? '');
            update_option('guardify_license_plan', $data['license']['plan'] ?? 'starter');
            update_option('guardify_license_max_sites', $data['license']['max_sites'] ?? 1);
            update_option('guardify_license_active_sites', $data['license']['active_sites'] ?? 1);
            update_option('guardify_license_days_remaining', $data['license']['days_remaining'] ?? 0);
            update_option('guardify_license_features', $data['license']['features'] ?? array());
            update_option('guardify_license_last_check', gmdate('Y-m-d H:i:s'));
            update_option('guardify_license_validation_failures', 0);
            if (!empty($data['site_api_key'])) {
                update_option('guardify_site_api_key', sanitize_text_field($data['site_api_key']));
            }
            if (!wp_next_scheduled('guardify_daily_cleanup')) {
                wp_schedule_event(time(), 'daily', 'guardify_daily_cleanup');
            }
            set_transient('guardify_license_form_msg', array('type' => 'success', 'message' => $data['message'] ?? __('License activated successfully!', 'guardify')), 60);
        } else {
            update_option('guardify_api_key', $api_key);
            update_option('guardify_license_status', 'error');
            $error_msg = $data['message'] ?? __('License activation failed. Please check your key.', 'guardify');
            set_transient('guardify_license_form_msg', array('type' => 'error', 'message' => $error_msg), 60);
        }

        wp_safe_redirect(admin_url('admin.php?page=guardify-settings'));
        exit;
    }
    
    /**
     * AJAX handler for checking license status
     */
    public function ajax_check_license() {
        check_ajax_referer('guardify_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
        }
        
        $result = $this->validate_license_with_server();
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => __('License is valid and active!', 'guardify'),
                'license' => $result['license']
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message'] ?? __('License validation failed', 'guardify'),
                'license' => $result['license'] ?? null
            ));
        }
    }

    /**
     * AJAX: Generate SSO token and return console URL
     */
    public function ajax_sso_login() {
        check_ajax_referer('guardify_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'guardify')));
        }
        
        $api_key = get_option('guardify_api_key', '');
        if (empty($api_key)) {
            wp_send_json_error(array('message' => __('No license key configured. Please activate your license first.', 'guardify')));
            return;
        }
        
        $response = wp_remote_post(self::TANSIQLABS_API_URL . 'sso', array(
            'timeout' => 15,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode(array(
                'license_key' => $api_key,
                'site_url' => home_url(),
            )),
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => __('Could not connect to TansiqLabs. Please try again.', 'guardify')));
            return;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code === 200 && !empty($data['success']) && !empty($data['sso_url'])) {
            wp_send_json_success(array('sso_url' => $data['sso_url']));
        } else {
            wp_send_json_error(array(
                'message' => $data['message'] ?? __('SSO login failed. Please try logging in manually.', 'guardify'),
            ));
        }
    }

    /**
     * Handle SSO login from TansiqLabs Console/Ops → WP Admin.
     *
     * The token is an HMAC-signed payload generated by the TansiqLabs server
     * using the site's API key as the shared secret.
     *
     * URL: https://example.com/?guardify_sso=TOKEN
     */
    public function handle_wp_sso_login(): void {
        if (empty($_GET['guardify_sso'])) {
            return;
        }

        // Already logged in — just redirect to wp-admin
        if (is_user_logged_in()) {
            wp_safe_redirect(admin_url('admin.php?page=guardify-settings'));
            exit;
        }

        $token = sanitize_text_field(wp_unslash($_GET['guardify_sso']));
        $api_key = get_option('guardify_site_api_key', '');

        if (empty($api_key)) {
            wp_die(
                __('Guardify SSO: Site API key not configured. Please activate your license first.', 'guardify'),
                __('SSO Error', 'guardify'),
                array('response' => 403)
            );
        }

        // Split token: base64url(payload).hex_signature
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            wp_die(__('Guardify SSO: Invalid token format.', 'guardify'), __('SSO Error', 'guardify'), array('response' => 403));
        }

        [$data, $signature] = $parts;

        // Verify HMAC signature
        $expected_sig = hash_hmac('sha256', $data, $api_key);
        if (!hash_equals($expected_sig, $signature)) {
            wp_die(__('Guardify SSO: Invalid token signature.', 'guardify'), __('SSO Error', 'guardify'), array('response' => 403));
        }

        // Decode payload (base64url → JSON)
        $json = base64_decode(strtr($data, '-_', '+/'));
        $payload = json_decode($json, true);

        if (!$payload || empty($payload['exp']) || empty($payload['iss'])) {
            wp_die(__('Guardify SSO: Malformed token payload.', 'guardify'), __('SSO Error', 'guardify'), array('response' => 403));
        }

        // Check issuer
        if ($payload['iss'] !== 'tansiqlabs-wp-sso') {
            wp_die(__('Guardify SSO: Invalid token issuer.', 'guardify'), __('SSO Error', 'guardify'), array('response' => 403));
        }

        // Check expiry (payload timestamps are in milliseconds)
        if ($payload['exp'] < (time() * 1000)) {
            wp_die(__('Guardify SSO: Token has expired. Please try again from the Console.', 'guardify'), __('SSO Error', 'guardify'), array('response' => 403));
        }

        // Find the first administrator to log in as
        $admins = get_users(array('role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC'));
        if (empty($admins)) {
            wp_die(__('Guardify SSO: No administrator account found on this site.', 'guardify'), __('SSO Error', 'guardify'), array('response' => 500));
        }

        $admin_user = $admins[0];

        // Set auth cookie and redirect to wp-admin
        wp_set_auth_cookie($admin_user->ID, false);
        wp_set_current_user($admin_user->ID);
        do_action('wp_login', $admin_user->user_login, $admin_user);

        wp_safe_redirect(admin_url('admin.php?page=guardify-settings'));
        exit;
    }
    
    /**
     * Validate license with TansiqLabs server
     */
    public function validate_license_with_server(): array {
        $api_key = get_option('guardify_api_key', '');
        
        if (empty($api_key)) {
            return array('success' => false, 'message' => 'No license key configured');
        }
        
        $response = wp_remote_post(self::TANSIQLABS_API_URL . 'validate', array(
            'timeout' => 15,
            'sslverify' => true,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'license_key' => $api_key,
                'site_url' => home_url(),
                'wp_version' => get_bloginfo('version'),
                'plugin_version' => GUARDIFY_VERSION,
                'php_version' => PHP_VERSION,
            )),
        ));
        
        if (is_wp_error($response)) {
            // Track consecutive validation failures
            $failures = (int) get_option('guardify_license_validation_failures', 0);
            $failures++;
            update_option('guardify_license_validation_failures', $failures);
            
            // After 3 consecutive failures, mark license as disconnected
            if ($failures >= 3) {
                update_option('guardify_license_status', 'disconnected');
            }
            
            return array('success' => false, 'message' => 'Could not connect to license server: ' . $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Handle non-JSON responses
        if ($data === null) {
            $failures = (int) get_option('guardify_license_validation_failures', 0);
            $failures++;
            update_option('guardify_license_validation_failures', $failures);
            
            if ($failures >= 3) {
                update_option('guardify_license_status', 'disconnected');
            }
            
            return array('success' => false, 'message' => 'Invalid response from license server');
        }
        
        if (!empty($data['success']) && !empty($data['license'])) {
            // Reset failure counter on successful validation
            update_option('guardify_license_validation_failures', 0);
            
            // Update local cache — use gmdate for consistent UTC timestamps
            update_option('guardify_license_status', $data['license']['status']);
            update_option('guardify_license_expiry', $data['license']['expires_at'] ?? '');
            update_option('guardify_license_plan', $data['license']['plan'] ?? '');
            update_option('guardify_license_days_remaining', $data['license']['days_remaining'] ?? 0);
            update_option('guardify_license_features', $data['license']['features'] ?? array());
            update_option('guardify_license_last_check', gmdate('Y-m-d H:i:s'));
            
            // Sync site API key (merged key system)
            if (!empty($data['site_api_key'])) {
                update_option('guardify_site_api_key', sanitize_text_field($data['site_api_key']));
            }
            
            // Sync Steadfast config from server — credentials managed on TansiqLabs console
            if (!empty($data['steadfast']) && !empty($data['steadfast']['api_key'])) {
                update_option('guardify_steadfast_api_key', sanitize_text_field($data['steadfast']['api_key']));
                update_option('guardify_steadfast_secret_key', sanitize_text_field($data['steadfast']['secret_key']));
                update_option('guardify_steadfast_enabled', '1');
            } else {
                // Steadfast disabled or removed on console — clear local config
                update_option('guardify_steadfast_enabled', '0');
                delete_option('guardify_steadfast_api_key');
                delete_option('guardify_steadfast_secret_key');
            }
            
            return array('success' => true, 'license' => $data['license']);
        }
        
        // Update status on failure
        if (!empty($data['license'])) {
            update_option('guardify_license_status', $data['license']['status'] ?? 'invalid');
        }
        
        return array(
            'success' => false, 
            'message' => $data['message'] ?? 'Validation failed',
            'license' => $data['license'] ?? null
        );
    }
    
    /**
     * Periodic license check (called by daily cron)
     */
    public function periodic_license_check(): void {
        $api_key = get_option('guardify_api_key', '');
        if (empty($api_key)) {
            return;
        }
        
        $this->validate_license_with_server();
    }

    /**
     * Render SteadFast Page (Submenu)
     */
    public function render_steadfast_page() {
        // Get SteadFast settings — API keys come from TansiqLabs console, not local input
        $sf_enabled = get_option('guardify_steadfast_enabled', '0');
        $sf_api_key = get_option('guardify_steadfast_api_key', '');
        $sf_secret_key = get_option('guardify_steadfast_secret_key', '');
        $sf_send_notes = get_option('guardify_steadfast_send_notes', '0');
        $sf_business_name = get_option('guardify_steadfast_business_name', get_bloginfo('name'));
        $sf_business_address = get_option('guardify_steadfast_business_address', '');
        $sf_business_email = get_option('guardify_steadfast_business_email', get_option('admin_email'));
        $sf_business_phone = get_option('guardify_steadfast_business_phone', '');
        $sf_business_logo = get_option('guardify_steadfast_business_logo', '');
        $sf_terms = get_option('guardify_steadfast_terms', '');
        ?>
        <div class="wrap guardify-settings-wrap">
            <!-- Premium Header -->
            <div class="guardify-header">
                <div class="guardify-header-content">
                    <div class="guardify-logo">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#fff" width="40" height="40"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg>
                    </div>
                    <div>
                        <h1>SteadFast Courier</h1>
                        <p class="guardify-subtitle"><?php _e('Courier Integration & Invoice System', 'guardify'); ?></p>
                    </div>
                </div>
                <div class="guardify-version-badge">
                    <span class="version">v<?php echo GUARDIFY_VERSION; ?></span>
                    <span class="developer">by Tansiq Labs</span>
                </div>
            </div>

            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="notice notice-success is-dismissible guardify-success-notice">
                    <div class="guardify-notice-content">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <div>
                            <p><strong><?php _e('Settings saved successfully!', 'guardify'); ?></strong></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('guardify_settings'); ?>
        
                <!-- API Settings Card — Credentials managed via TansiqLabs Console -->
                <div class="guardify-card">
                    <div class="guardify-card-header">
                        <h2>
                            <span class="dashicons dashicons-rest-api"></span>
                            <?php _e('SteadFast Courier Status', 'guardify'); ?>
                        </h2>
                    </div>
                    <div class="guardify-card-content">
                        <?php if (!empty($sf_api_key)): ?>
                            <div class="guardify-info-box guardify-info-success" style="margin-bottom: 20px;">
                                <span class="dashicons dashicons-yes-alt" style="color: #059669;"></span>
                                <div>
                                    <strong><?php _e('SteadFast Connected', 'guardify'); ?></strong>
                                    <p style="margin: 4px 0 0; color: #666; font-size: 13px;">
                                        <?php _e('API credentials are synced from your TansiqLabs console. Manage them at', 'guardify'); ?>
                                        <a href="https://tansiqlabs.com/console/apps/guardify/sites" target="_blank" style="color: #0d9488; font-weight: 600;">tansiqlabs.com/console</a>
                                    </p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="guardify-info-box guardify-info-warning" style="margin-bottom: 20px;">
                                <span class="dashicons dashicons-warning" style="color: #d97706;"></span>
                                <div>
                                    <strong><?php _e('SteadFast Not Connected', 'guardify'); ?></strong>
                                    <p style="margin: 4px 0 0; color: #666; font-size: 13px;">
                                        <?php _e('Add your SteadFast API credentials in your', 'guardify'); ?>
                                        <a href="https://tansiqlabs.com/console/apps/guardify/sites" target="_blank" style="color: #0d9488; font-weight: 600;">TansiqLabs Console</a>
                                        <?php _e('to enable courier integration. Credentials sync automatically.', 'guardify'); ?>
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Status', 'guardify'); ?></th>
                                <td>
                                    <?php if (!empty($sf_api_key)): ?>
                                        <span style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; background: #ecfdf5; color: #059669; border-radius: 6px; font-size: 13px; font-weight: 600;">
                                            <span class="dashicons dashicons-yes-alt" style="font-size: 16px; width: 16px; height: 16px;"></span>
                                            <?php _e('Active & Synced', 'guardify'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; background: #fef3c7; color: #d97706; border-radius: 6px; font-size: 13px; font-weight: 600;">
                                            <span class="dashicons dashicons-minus" style="font-size: 16px; width: 16px; height: 16px;"></span>
                                            <?php _e('Not Configured', 'guardify'); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if (!empty($sf_api_key)): ?>
                            <tr>
                                <th scope="row"><?php _e('Balance Check', 'guardify'); ?></th>
                                <td>
                                    <button type="button" id="guardify-check-sf-balance" class="button button-secondary">
                                        <span class="dashicons dashicons-update" style="line-height: 1.3;"></span>
                                        <?php _e('Check Balance', 'guardify'); ?>
                                    </button>
                                    <span id="guardify-sf-balance-result" style="margin-left: 10px;"></span>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th scope="row"><?php _e('Send Order Notes', 'guardify'); ?></th>
                                <td>
                                    <label class="guardify-toggle">
                                        <input type="checkbox" name="guardify_steadfast_send_notes" value="1" <?php checked($sf_send_notes, '1'); ?>>
                                        <span class="guardify-toggle-slider"></span>
                                    </label>
                                    <span class="toggle-label"><?php echo $sf_send_notes === '1' ? '✓ Enabled' : 'Disabled'; ?></span>
                                    <p class="description"><?php _e('Include customer order notes when sending to SteadFast.', 'guardify'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Business Info Card -->
                <div class="guardify-card">
                    <div class="guardify-card-header">
                        <h2>
                            <span class="dashicons dashicons-building"></span>
                            <?php _e('Business Information (for Invoice)', 'guardify'); ?>
                        </h2>
                    </div>
                    <div class="guardify-card-content">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Business Name', 'guardify'); ?></th>
                                <td>
                                    <input type="text" name="guardify_steadfast_business_name" value="<?php echo esc_attr($sf_business_name); ?>" class="regular-text" placeholder="<?php _e('Your Business Name', 'guardify'); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Business Address', 'guardify'); ?></th>
                                <td>
                                    <textarea name="guardify_steadfast_business_address" rows="3" class="large-text" placeholder="<?php _e('Your Business Address', 'guardify'); ?>"><?php echo esc_textarea($sf_business_address); ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Business Email', 'guardify'); ?></th>
                                <td>
                                    <input type="email" name="guardify_steadfast_business_email" value="<?php echo esc_attr($sf_business_email); ?>" class="regular-text" placeholder="<?php _e('email@example.com', 'guardify'); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Business Phone', 'guardify'); ?></th>
                                <td>
                                    <input type="text" name="guardify_steadfast_business_phone" value="<?php echo esc_attr($sf_business_phone); ?>" class="regular-text" placeholder="<?php _e('+880 1XXX XXXXXX', 'guardify'); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Business Logo URL', 'guardify'); ?></th>
                                <td>
                                    <input type="url" name="guardify_steadfast_business_logo" value="<?php echo esc_attr($sf_business_logo); ?>" class="large-text" placeholder="<?php _e('https://example.com/logo.png', 'guardify'); ?>">
                                    <p class="description"><?php _e('Logo image URL for invoice header.', 'guardify'); ?></p>
                                    <?php if ($sf_business_logo): ?>
                                        <div style="margin-top: 10px;">
                                            <img src="<?php echo esc_url($sf_business_logo); ?>" alt="Logo Preview" style="max-height: 50px;">
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Terms & Conditions', 'guardify'); ?></th>
                                <td>
                                    <textarea name="guardify_steadfast_terms" rows="4" class="large-text" placeholder="<?php _e('Enter terms and conditions for invoice...', 'guardify'); ?>"><?php echo esc_textarea($sf_terms); ?></textarea>
                                    <p class="description"><?php _e('This will appear at the bottom of the invoice.', 'guardify'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Features Info -->
                <div class="guardify-card">
                    <div class="guardify-card-header">
                        <h2>
                            <span class="dashicons dashicons-info-outline"></span>
                            <?php _e('SteadFast Features', 'guardify'); ?>
                        </h2>
                    </div>
                    <div class="guardify-card-content">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                            <div style="padding: 15px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #667eea;">
                                <h4 style="margin: 0 0 8px; display: flex; align-items: center; gap: 8px;">
                                    <span class="dashicons dashicons-upload"></span>
                                    <?php _e('Send Orders', 'guardify'); ?>
                                </h4>
                                <p style="margin: 0; color: #666; font-size: 13px;"><?php _e('Send individual or bulk orders to SteadFast courier directly from WooCommerce.', 'guardify'); ?></p>
                            </div>
                            <div style="padding: 15px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #43e97b;">
                                <h4 style="margin: 0 0 8px; display: flex; align-items: center; gap: 8px;">
                                    <span class="dashicons dashicons-media-text"></span>
                                    <?php _e('Print Invoice', 'guardify'); ?>
                                </h4>
                                <p style="margin: 0; color: #666; font-size: 13px;"><?php _e('Generate and print professional invoices with your business branding.', 'guardify'); ?></p>
                            </div>
                            <div style="padding: 15px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #f093fb;">
                                <h4 style="margin: 0 0 8px; display: flex; align-items: center; gap: 8px;">
                                    <span class="dashicons dashicons-location"></span>
                                    <?php _e('Track Delivery', 'guardify'); ?>
                                </h4>
                                <p style="margin: 0; color: #666; font-size: 13px;"><?php _e('Check real-time delivery status for all your consignments.', 'guardify'); ?></p>
                            </div>
                            <div style="padding: 15px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #4facfe;">
                                <h4 style="margin: 0 0 8px; display: flex; align-items: center; gap: 8px;">
                                    <span class="dashicons dashicons-chart-pie"></span>
                                    <?php _e('Check Balance', 'guardify'); ?>
                                </h4>
                                <p style="margin: 0; color: #666; font-size: 13px;"><?php _e('View your SteadFast account balance directly from WordPress.', 'guardify'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="guardify-submit">
                    <button type="submit" name="guardify_save_settings" class="guardify-btn-primary">
                        <span class="dashicons dashicons-saved"></span>
                        <?php _e('Save Settings', 'guardify'); ?>
                    </button>
                </div>
            </form>

            <!-- Footer -->
            <div class="guardify-footer">
                <p><?php _e('Developed by', 'guardify'); ?> <a href="https://tansiqlabs.com/" target="_blank">Tansiq Labs</a></p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render Recent Blocked Orders Section (for Cooldown tab)
     */
    private function render_recent_blocked_orders_section() {
        // Get advanced protection instance
        if (!class_exists('Guardify_Advanced_Protection')) {
            require_once GUARDIFY_PLUGIN_DIR . 'includes/class-guardify-advanced-protection.php';
        }
        $advanced = Guardify_Advanced_Protection::get_instance();
        
        $recent_blocked = $advanced->get_recent_blocked(10);
        
        $type_labels = array(
            'phone' => __('ফোন কুলডাউন', 'guardify'),
            'ip' => __('আইপি কুলডাউন', 'guardify'),
            'address' => __('একই ঠিকানা', 'guardify'),
            'similar_name' => __('সন্দেহজনক নাম', 'guardify'),
            'vpn' => __('VPN সনাক্ত', 'guardify'),
        );
        
        // Get blocked lists for checking
        $blocked_phones = get_option('guardify_blocked_phones', array());
        $blocked_ips = get_option('guardify_blocked_ips', array());
        $blocked_devices = get_option('guardify_blocked_devices', array());
        if (!is_array($blocked_phones)) $blocked_phones = array();
        if (!is_array($blocked_ips)) $blocked_ips = array();
        if (!is_array($blocked_devices)) $blocked_devices = array();
        ?>
        
        <div class="guardify-card" style="margin-top: 20px;">
            <div class="guardify-card-header">
                <h2><?php _e('সাম্প্রতিক ব্লক করা অর্ডার', 'guardify'); ?></h2>
                <p class="description"><?php _e('কুলডাউন বা অন্যান্য কারণে ব্লক হওয়া সাম্প্রতিক অর্ডার। এখান থেকে সরাসরি ফোন/আইপি/ডিভাইস ব্লক করতে পারবেন।', 'guardify'); ?></p>
            </div>
            <div style="padding: 0;">
                <?php if (!empty($recent_blocked)): ?>
                    <table class="wp-list-table widefat fixed striped" style="border: none;">
                        <thead>
                            <tr>
                                <th style="width: 10%;"><?php _e('টাইপ', 'guardify'); ?></th>
                                <th style="width: 15%;"><?php _e('নাম', 'guardify'); ?></th>
                                <th style="width: 13%;"><?php _e('ফোন', 'guardify'); ?></th>
                                <th style="width: 10%;"><?php _e('আইপি', 'guardify'); ?></th>
                                <th style="width: 12%;"><?php _e('সময়', 'guardify'); ?></th>
                                <th style="width: 40%;"><?php _e('অ্যাকশন', 'guardify'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_blocked as $entry): 
                                $label = isset($type_labels[$entry['type']]) ? $type_labels[$entry['type']] : $entry['type'];
                                $phone = isset($entry['phone']) ? preg_replace('/[\s\-\(\)]+/', '', $entry['phone']) : '';
                                $ip = isset($entry['ip']) ? $entry['ip'] : '';
                                $phone_blocked = !empty($phone) && in_array($phone, $blocked_phones);
                                $ip_blocked = !empty($ip) && in_array($ip, $blocked_ips);
                                
                                // Generate device ID from IP + user_agent (if available) or just IP
                                $user_agent = isset($entry['user_agent']) ? $entry['user_agent'] : '';
                                $device_id = !empty($ip) ? md5($user_agent . $ip) : '';
                                $device_blocked = !empty($device_id) && in_array($device_id, $blocked_devices);
                            ?>
                                <tr>
                                    <td>
                                        <span style="background: #667eea; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                                            <?php echo esc_html($label); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html($entry['name'] ?: '-'); ?></td>
                                    <td><code style="font-size: 11px;"><?php echo esc_html($entry['phone'] ?: '-'); ?></code></td>
                                    <td><code style="font-size: 11px;"><?php echo esc_html($entry['ip'] ?: '-'); ?></code></td>
                                    <td style="font-size: 12px;"><?php echo esc_html(human_time_diff($entry['time'], time()) . ' আগে'); ?></td>
                                    <td>
                                        <div class="guardify-block-actions" style="display: flex; gap: 6px; flex-wrap: wrap;">
                                            <?php if (!empty($phone)): ?>
                                                <?php if ($phone_blocked): ?>
                                                    <button type="button" class="guardify-btn-blocked guardify-btn-phone-blocked" 
                                                            data-phone="<?php echo esc_attr($phone); ?>" 
                                                            title="<?php echo esc_attr(sprintf(__('Click to unblock %s', 'guardify'), $phone)); ?>"
                                                            style="font-size: 11px; padding: 4px 8px;">
                                                        <?php _e('Phone Blocked', 'guardify'); ?>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="guardify-btn-block guardify-btn-block-phone" 
                                                            data-phone="<?php echo esc_attr($phone); ?>"
                                                            title="<?php echo esc_attr(sprintf(__('Block phone: %s', 'guardify'), $phone)); ?>"
                                                            style="font-size: 11px; padding: 4px 8px;">
                                                        <?php _e('Block Phone', 'guardify'); ?>
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($ip) && $ip !== '0.0.0.0'): ?>
                                                <?php if ($ip_blocked): ?>
                                                    <button type="button" class="guardify-btn-blocked guardify-btn-ip-blocked" 
                                                            data-ip="<?php echo esc_attr($ip); ?>"
                                                            title="<?php echo esc_attr(sprintf(__('Click to unblock IP: %s', 'guardify'), $ip)); ?>"
                                                            style="font-size: 11px; padding: 4px 8px;">
                                                        <?php _e('IP Blocked', 'guardify'); ?>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="guardify-btn-block guardify-btn-block-ip" 
                                                            data-ip="<?php echo esc_attr($ip); ?>"
                                                            title="<?php echo esc_attr(sprintf(__('Block IP: %s', 'guardify'), $ip)); ?>"
                                                            style="font-size: 11px; padding: 4px 8px;">
                                                        <?php _e('Block IP', 'guardify'); ?>
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($device_id)): ?>
                                                <?php if ($device_blocked): ?>
                                                    <button type="button" class="guardify-btn-blocked guardify-btn-device-blocked" 
                                                            data-device="<?php echo esc_attr($device_id); ?>"
                                                            title="<?php esc_attr_e('Click to unblock this device', 'guardify'); ?>"
                                                            style="font-size: 11px; padding: 4px 8px;">
                                                        <?php _e('Device Blocked', 'guardify'); ?>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="guardify-btn-block guardify-btn-block-device-direct" 
                                                            data-device="<?php echo esc_attr($device_id); ?>"
                                                            title="<?php esc_attr_e('Block this device (fingerprint)', 'guardify'); ?>"
                                                            style="font-size: 11px; padding: 4px 8px;">
                                                        <?php _e('Block Device', 'guardify'); ?>
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="guardify-empty-state" style="text-align: center; padding: 40px;">
                        <span class="dashicons dashicons-yes-alt" style="font-size: 48px; color: #43e97b;"></span>
                        <p style="color: #666; margin-top: 12px;"><?php _e('কোনো সন্দেহজনক অর্ডার ব্লক হয়নি। সবকিছু ঠিক আছে!', 'guardify'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render Analytics Tab
     */
    private function render_analytics_tab() {
        // Get advanced protection instance
        if (!class_exists('Guardify_Advanced_Protection')) {
            require_once GUARDIFY_PLUGIN_DIR . 'includes/class-guardify-advanced-protection.php';
        }
        $advanced = Guardify_Advanced_Protection::get_instance();
        
        $weekly_stats = $advanced->get_blocked_stats('week');
        $monthly_stats = $advanced->get_blocked_stats('month');
        $recent_blocked = $advanced->get_recent_blocked(15);
        
        $type_labels = array(
            'phone' => __('ফোন কুলডাউন', 'guardify'),
            'ip' => __('আইপি কুলডাউন', 'guardify'),
            'address' => __('একই ঠিকানা', 'guardify'),
            'similar_name' => __('সন্দেহজনক নাম', 'guardify'),
            'vpn' => __('VPN সনাক্ত', 'guardify'),
        );
        ?>
        
        <div class="guardify-analytics-dashboard">
            <!-- Stats Overview -->
            <div class="guardify-stats-row" style="margin-bottom: 24px;">
                <div class="guardify-stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <span class="dashicons dashicons-shield-alt"></span>
                    </div>
                    <div class="stat-content">
                        <h3 style="font-size: 28px; margin: 0;"><?php echo number_format($weekly_stats['total']); ?></h3>
                        <p style="margin: 4px 0 0; color: #666;"><?php _e('গত ৭ দিনে ব্লক', 'guardify'); ?></p>
                    </div>
                </div>
                <div class="guardify-stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <span class="dashicons dashicons-chart-bar"></span>
                    </div>
                    <div class="stat-content">
                        <h3 style="font-size: 28px; margin: 0;"><?php echo number_format($monthly_stats['total']); ?></h3>
                        <p style="margin: 4px 0 0; color: #666;"><?php _e('গত ৩০ দিনে ব্লক', 'guardify'); ?></p>
                    </div>
                </div>
                <div class="guardify-stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <span class="dashicons dashicons-calendar-alt"></span>
                    </div>
                    <div class="stat-content">
                        <h3 style="font-size: 28px; margin: 0;"><?php echo $weekly_stats['total'] > 0 ? round($weekly_stats['total'] / 7, 1) : 0; ?></h3>
                        <p style="margin: 4px 0 0; color: #666;"><?php _e('প্রতিদিন গড়', 'guardify'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Block Types Breakdown -->
            <div class="guardify-card" style="margin-bottom: 24px;">
                <div class="guardify-card-header">
                    <h2><?php _e('ব্লক টাইপ অনুযায়ী (গত ৩০ দিন)', 'guardify'); ?></h2>
                </div>
                <div style="padding: 20px;">
                    <?php if (!empty($monthly_stats['by_type'])): ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px;">
                            <?php foreach ($monthly_stats['by_type'] as $type => $count): 
                                $label = isset($type_labels[$type]) ? $type_labels[$type] : $type;
                                $percentage = $monthly_stats['total'] > 0 ? round(($count / $monthly_stats['total']) * 100, 1) : 0;
                            ?>
                                <div style="background: #f8f9fa; padding: 16px; border-radius: 8px;">
                                    <div style="font-size: 24px; font-weight: 600; color: #333;"><?php echo number_format($count); ?></div>
                                    <div style="font-size: 14px; color: #666; margin-top: 4px;"><?php echo esc_html($label); ?></div>
                                    <div style="background: #e9ecef; height: 6px; border-radius: 3px; margin-top: 12px; overflow: hidden;">
                                        <div style="background: linear-gradient(90deg, #667eea, #764ba2); height: 100%; width: <?php echo $percentage; ?>%; transition: width 0.5s;"></div>
                                    </div>
                                    <div style="font-size: 12px; color: #999; margin-top: 4px;"><?php echo $percentage; ?>%</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="guardify-empty-state" style="text-align: center; padding: 40px;">
                            <span class="dashicons dashicons-chart-pie" style="font-size: 48px; color: #ccc;"></span>
                            <p style="color: #666; margin-top: 12px;"><?php _e('এখনো কোনো ডেটা নেই।', 'guardify'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Daily Chart -->
            <div class="guardify-card" style="margin-bottom: 24px;">
                <div class="guardify-card-header">
                    <h2><?php _e('দৈনিক ব্লক চার্ট (গত ৭ দিন)', 'guardify'); ?></h2>
                </div>
                <div style="padding: 20px;">
                    <?php 
                    $max_value = max(array_values($weekly_stats['by_day'])) ?: 1;
                    ?>
                    <div style="display: flex; align-items: flex-end; justify-content: space-between; height: 150px; gap: 8px;">
                        <?php foreach ($weekly_stats['by_day'] as $date => $count): 
                            $height = ($count / $max_value) * 100;
                            $day_name = date('D', strtotime($date));
                            $day_bn = array('Sat' => 'শনি', 'Sun' => 'রবি', 'Mon' => 'সোম', 'Tue' => 'মঙ্গল', 'Wed' => 'বুধ', 'Thu' => 'বৃহ', 'Fri' => 'শুক্র');
                        ?>
                            <div style="flex: 1; text-align: center;">
                                <div style="background: linear-gradient(180deg, #667eea 0%, #764ba2 100%); height: <?php echo max($height, 5); ?>%; border-radius: 4px 4px 0 0; transition: height 0.3s;" title="<?php echo $count; ?>"></div>
                                <div style="font-size: 11px; color: #666; margin-top: 8px;"><?php echo isset($day_bn[$day_name]) ? $day_bn[$day_name] : $day_name; ?></div>
                                <div style="font-size: 13px; font-weight: 600; color: #333;"><?php echo $count; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Blocked -->
            <div class="guardify-card">
                <div class="guardify-card-header">
                    <h2><?php _e('সাম্প্রতিক ব্লক করা অর্ডার', 'guardify'); ?></h2>
                </div>
                <div style="padding: 0;">
                    <?php if (!empty($recent_blocked)): ?>
                        <?php 
                        // Get blocked lists for checking
                        $blocked_phones = get_option('guardify_blocked_phones', array());
                        $blocked_ips = get_option('guardify_blocked_ips', array());
                        $blocked_devices = get_option('guardify_blocked_devices', array());
                        if (!is_array($blocked_phones)) $blocked_phones = array();
                        if (!is_array($blocked_ips)) $blocked_ips = array();
                        if (!is_array($blocked_devices)) $blocked_devices = array();
                        ?>
                        <table class="wp-list-table widefat fixed striped" style="border: none;">
                            <thead>
                                <tr>
                                    <th style="width: 10%;"><?php _e('টাইপ', 'guardify'); ?></th>
                                    <th style="width: 15%;"><?php _e('নাম', 'guardify'); ?></th>
                                    <th style="width: 13%;"><?php _e('ফোন', 'guardify'); ?></th>
                                    <th style="width: 10%;"><?php _e('আইপি', 'guardify'); ?></th>
                                    <th style="width: 12%;"><?php _e('সময়', 'guardify'); ?></th>
                                    <th style="width: 40%;"><?php _e('অ্যাকশন', 'guardify'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_blocked as $entry): 
                                    $label = isset($type_labels[$entry['type']]) ? $type_labels[$entry['type']] : $entry['type'];
                                    $phone = isset($entry['phone']) ? preg_replace('/[\s\-\(\)]+/', '', $entry['phone']) : '';
                                    $ip = isset($entry['ip']) ? $entry['ip'] : '';
                                    $phone_blocked = !empty($phone) && in_array($phone, $blocked_phones);
                                    $ip_blocked = !empty($ip) && in_array($ip, $blocked_ips);
                                    
                                    // Generate device ID from IP + user_agent (if available)
                                    $user_agent = isset($entry['user_agent']) ? $entry['user_agent'] : '';
                                    $device_id = !empty($ip) ? md5($user_agent . $ip) : '';
                                    $device_blocked = !empty($device_id) && in_array($device_id, $blocked_devices);
                                ?>
                                    <tr>
                                        <td>
                                            <span style="background: #667eea; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                                                <?php echo esc_html($label); ?>
                                            </span>
                                        </td>
                                        <td><?php echo esc_html($entry['name'] ?: '-'); ?></td>
                                        <td><code style="font-size: 11px;"><?php echo esc_html($entry['phone'] ?: '-'); ?></code></td>
                                        <td><code style="font-size: 11px;"><?php echo esc_html($entry['ip'] ?: '-'); ?></code></td>
                                        <td style="font-size: 12px;"><?php echo esc_html(human_time_diff($entry['time'], time()) . ' আগে'); ?></td>
                                        <td>
                                            <div class="guardify-block-actions" style="display: flex; gap: 6px; flex-wrap: wrap;">
                                                <?php if (!empty($phone)): ?>
                                                    <?php if ($phone_blocked): ?>
                                                        <button type="button" class="guardify-btn-blocked guardify-btn-phone-blocked" 
                                                                data-phone="<?php echo esc_attr($phone); ?>" 
                                                                title="<?php echo esc_attr(sprintf(__('Click to unblock %s', 'guardify'), $phone)); ?>"
                                                                style="font-size: 11px; padding: 4px 8px;">
                                                            <?php _e('Phone Blocked', 'guardify'); ?>
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" class="guardify-btn-block guardify-btn-block-phone" 
                                                                data-phone="<?php echo esc_attr($phone); ?>"
                                                                title="<?php echo esc_attr(sprintf(__('Block phone: %s', 'guardify'), $phone)); ?>"
                                                                style="font-size: 11px; padding: 4px 8px;">
                                                            <?php _e('Block Phone', 'guardify'); ?>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                
                                                <?php if (!empty($ip) && $ip !== '0.0.0.0'): ?>
                                                    <?php if ($ip_blocked): ?>
                                                        <button type="button" class="guardify-btn-blocked guardify-btn-ip-blocked" 
                                                                data-ip="<?php echo esc_attr($ip); ?>"
                                                                title="<?php echo esc_attr(sprintf(__('Click to unblock IP: %s', 'guardify'), $ip)); ?>"
                                                                style="font-size: 11px; padding: 4px 8px;">
                                                            <?php _e('IP Blocked', 'guardify'); ?>
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" class="guardify-btn-block guardify-btn-block-ip" 
                                                                data-ip="<?php echo esc_attr($ip); ?>"
                                                                title="<?php echo esc_attr(sprintf(__('Block IP: %s', 'guardify'), $ip)); ?>"
                                                                style="font-size: 11px; padding: 4px 8px;">
                                                            <?php _e('Block IP', 'guardify'); ?>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                
                                                <?php if (!empty($device_id)): ?>
                                                    <?php if ($device_blocked): ?>
                                                        <button type="button" class="guardify-btn-blocked guardify-btn-device-blocked" 
                                                                data-device="<?php echo esc_attr($device_id); ?>"
                                                                title="<?php esc_attr_e('Click to unblock this device', 'guardify'); ?>"
                                                                style="font-size: 11px; padding: 4px 8px;">
                                                            <?php _e('Device Blocked', 'guardify'); ?>
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" class="guardify-btn-block guardify-btn-block-device-direct" 
                                                                data-device="<?php echo esc_attr($device_id); ?>"
                                                                title="<?php esc_attr_e('Block this device (fingerprint)', 'guardify'); ?>"
                                                                style="font-size: 11px; padding: 4px 8px;">
                                                            <?php _e('Block Device', 'guardify'); ?>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="guardify-empty-state" style="text-align: center; padding: 40px;">
                            <span class="dashicons dashicons-yes-alt" style="font-size: 48px; color: #43e97b;"></span>
                            <p style="color: #666; margin-top: 12px;"><?php _e('কোনো সন্দেহজনক অর্ডার ব্লক হয়নি। সবকিছু ঠিক আছে!', 'guardify'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
