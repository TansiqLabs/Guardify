<?php
/**
 * Guardify Phone Validation Class
 * বাংলাদেশি মোবাইল নাম্বার ভ্যালিডেশন
 * Cartflows এবং Block Checkout সাপোর্ট
 * 
 * @package Guardify
 * @since 1.0.0
 * @updated 2.1.0 - Improved error handling
 */

if (!defined('ABSPATH')) {
    exit;
}

class Guardify_Phone_Validation {
    private static ?self $instance = null;

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Initialize hooks if enabled
        if ($this->is_enabled()) {
            add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
            // Use only woocommerce_after_checkout_validation for server-side validation
            // This is the proper WooCommerce way and prevents double validation
            add_action('woocommerce_after_checkout_validation', array($this, 'validate_phone_after_checkout'), 10, 2);
            
            // Support for WooCommerce Block Checkout
            add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'validate_block_checkout_phone'), 10, 2);
            
            // Cartflows specific hooks
            add_action('cartflows_checkout_before_process_checkout', array($this, 'validate_checkout_phone'), 1);
            add_action('wcf_checkout_before_process_checkout', array($this, 'validate_checkout_phone'), 1);
        }
    }

    /**
     * Check if BD phone validation is enabled
     */
    public function is_enabled(): bool {
        return get_option('guardify_bd_phone_validation_enabled', '1') === '1';
    }

    /**
     * Enqueue phone validation scripts
     */
    public function enqueue_scripts(): void {
        // Check for standard WooCommerce checkout or Cartflows checkout
        $is_checkout_page = is_checkout();
        
        // Also check for Cartflows step pages
        if (!$is_checkout_page && function_exists('wcf_get_current_step_type')) {
            $step_type = wcf_get_current_step_type();
            if ($step_type === 'checkout') {
                $is_checkout_page = true;
            }
        }
        
        // Fallback: Check for Cartflows shortcode or common landing page patterns
        if (!$is_checkout_page) {
            global $post;
            if ($post && (
                has_shortcode($post->post_content, 'cartflows_checkout') ||
                has_shortcode($post->post_content, 'woocommerce_checkout') ||
                strpos($post->post_content, 'wcf-checkout') !== false
            )) {
                $is_checkout_page = true;
            }
        }
        
        if (!$is_checkout_page) {
            return;
        }

        wp_enqueue_script(
            'guardify-phone-validation',
            GUARDIFY_PLUGIN_URL . 'assets/js/phone-validation.js',
            array('jquery'),
            GUARDIFY_VERSION,
            array(
                'in_footer' => true,
                'strategy'  => 'defer', // Defer script loading for performance
            )
        );

        wp_enqueue_style(
            'guardify-phone-validation-styles',
            GUARDIFY_PLUGIN_URL . 'assets/css/phone-validation.css',
            array(),
            GUARDIFY_VERSION
        );

        // Localize script with settings
        wp_localize_script(
            'guardify-phone-validation',
            'guardifyPhoneValidation',
            array(
                'enabled' => $this->is_enabled(),
                'message' => $this->get_validation_message(),
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('guardify-phone-validation')
            )
        );
    }

    /**
     * Get validation message
     */
    public function get_validation_message(): string {
        return get_option('guardify_bd_phone_validation_message', 'অনুগ্রহ করে একটি সঠিক বাংলাদেশি মোবাইল নাম্বার দিন (যেমন: 01712345678)');
    }

    /**
     * Validate Bangladeshi phone number format
     * Valid formats: 
     * - 01XXXXXXXXX (11 digits starting with 01)
     * - +8801XXXXXXXXX (14 characters with +880 prefix)
     * - 8801XXXXXXXXX (13 digits with 880 prefix)
     */
    public function validate_bd_phone_number(string $phone): bool {
        if (empty($phone)) {
            return false;
        }

        // Remove spaces, dashes, and parentheses
        $phone = preg_replace('/[\s\-\(\)]+/', '', $phone);

        // Valid Bangladeshi mobile number patterns
        // BD mobile operators: 013, 014, 015, 016, 017, 018, 019
        
        // Local format: 01XXXXXXXXX
        if (preg_match('/^01[3-9]\d{8}$/', $phone)) {
            return true;
        }
        
        // International format with +880
        if (preg_match('/^\+8801[3-9]\d{8}$/', $phone)) {
            return true;
        }
        
        // International format without +
        if (preg_match('/^8801[3-9]\d{8}$/', $phone)) {
            return true;
        }

        return false;
    }

    /**
     * Validate phone number during checkout (Classic Checkout)
     */
    public function validate_checkout_phone(): void {
        try {
            if (!$this->is_enabled()) {
                return;
            }

            if (!isset($_POST['billing_phone'])) {
                return;
            }

            $phone = sanitize_text_field(wp_unslash($_POST['billing_phone']));

            // Empty phone is handled by WooCommerce's required field validation
            if (empty($phone)) {
                return;
            }

            if (!$this->validate_bd_phone_number($phone)) {
                wc_add_notice($this->get_validation_message(), 'error');
            }
        } catch (Exception $e) {
            // Don't block on error
        }
    }

    /**
     * Validate phone after checkout form submission (Classic Checkout)
     */
    public function validate_phone_after_checkout(array $data, \WP_Error $errors): void {
        try {
            if (!$this->is_enabled()) {
                return;
            }

            $phone = $data['billing_phone'] ?? '';

            // Empty phone is handled by WooCommerce's required field validation
            if (empty($phone)) {
                return;
            }

            // Check if error already exists to avoid duplicates
            $existing_errors = $errors->get_error_codes();
            if (in_array('validation', $existing_errors, true)) {
                return;
            }

            if (!$this->validate_bd_phone_number($phone)) {
                $errors->add('validation', $this->get_validation_message());
            }
        } catch (Exception $e) {
            // Don't block on error
        }
    }

    /**
     * Validate phone for WooCommerce Block Checkout
     * 
     * @param \WC_Order $order The order object
     * @param \WP_REST_Request $request The REST request
     */
    public function validate_block_checkout_phone($order, $request): void {
        try {
            if (!$this->is_enabled()) {
                return;
            }

            $phone = $order->get_billing_phone();

            // Empty phone is handled by WooCommerce's required field validation
            if (empty($phone)) {
                return;
            }

            if (!$this->validate_bd_phone_number($phone)) {
                throw new \Exception($this->get_validation_message());
            }
        } catch (\Exception $e) {
            // For block checkout, we need to throw an exception to stop the order
            if (strpos($e->getMessage(), 'বাংলাদেশি মোবাইল') !== false) {
                throw $e;
            }
            // Don't block on other errors
        }
    }
}
