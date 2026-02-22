<?php
/**
 * Guardify VPN Block Class
 * VPN/Proxy detection এবং blocking
 * 
 * @package Guardify
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Guardify_VPN_Block {
    private static ?self $instance = null;

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ($this->is_enabled()) {
            add_action('woocommerce_after_checkout_validation', array($this, 'validate_vpn_on_checkout'), 10, 2);
            add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
            
            // Cartflows specific hooks
            add_action('cartflows_checkout_before_process_checkout', array($this, 'validate_vpn_cartflows'), 1);
            add_action('wcf_checkout_before_process_checkout', array($this, 'validate_vpn_cartflows'), 1);
        }
    }

    /**
     * Check if feature is enabled
     */
    public function is_enabled(): bool {
        return get_option('guardify_vpn_block_enabled', '1') === '1';
    }

    /**
     * Validate VPN/Proxy on Cartflows checkout
     */
    public function validate_vpn_cartflows(): void {
        try {
            // Skip for logged in users with previous successful orders
            if (is_user_logged_in()) {
                $user_id = get_current_user_id();
                $order_count = wc_get_customer_order_count($user_id);
                if ($order_count > 0) {
                    return;
                }
            }

            // Check if IP is whitelisted
            if (class_exists('Guardify_Advanced_Protection')) {
                $advanced = Guardify_Advanced_Protection::get_instance();
                $ip = $this->get_real_ip();
                if ($advanced->is_ip_whitelisted($ip)) {
                    return;
                }
            }

            if ($this->is_vpn_detected()) {
                $message = get_option('guardify_vpn_block_message', 'দুঃখিত, VPN/Proxy ব্যবহার করে অর্ডার করা যাবে না।');
                wc_add_notice($message, 'error');
                $this->increment_blocked_count();
                do_action('guardify_order_blocked', 'vpn', $_POST, null);
            }
        } catch (Exception $e) {
            // Don't block on error
        }
    }

    /**
     * Validate VPN/Proxy on checkout
     */
    public function validate_vpn_on_checkout(array $data, \WP_Error $errors): void {
        try {
            // Skip for logged in users with previous successful orders (trusted customers)
            if (is_user_logged_in()) {
                $user_id = get_current_user_id();
                $order_count = wc_get_customer_order_count($user_id);
                if ($order_count > 0) {
                    return; // Trusted customer, skip VPN check
                }
            }

            // Check if IP is whitelisted
            if (class_exists('Guardify_Advanced_Protection')) {
                $advanced = Guardify_Advanced_Protection::get_instance();
                $ip = $this->get_real_ip();
                if ($advanced->is_ip_whitelisted($ip)) {
                    return;
                }
            }

            if ($this->is_vpn_detected()) {
                $message = get_option('guardify_vpn_block_message', 'দুঃখিত, VPN/Proxy ব্যবহার করে অর্ডার করা যাবে না। অনুগ্রহ করে আপনার সাধারণ ইন্টারনেট সংযোগ ব্যবহার করুন।');
                $errors->add('vpn_detected', $message);
                $this->increment_blocked_count();
                
                // Log to analytics
                do_action('guardify_order_blocked', 'vpn', $data, null);
            }
        } catch (Exception $e) {
            // Log error but don't block the order (only in debug mode)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Guardify VPN Block Error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Detect VPN/Proxy
     */
    private function is_vpn_detected() {
        // Check common VPN/Proxy headers
        if ($this->check_proxy_headers()) {
            return true;
        }

        // DISABLED: IP reputation check causes issues with legitimate traffic
        // including Facebook Ads, Google Ads, and many Bangladesh ISPs
        // The hostname-based detection is too aggressive and unreliable
        // if ($this->check_ip_reputation()) {
        //     return true;
        // }

        return false;
    }

    /**
     * Check proxy headers
     */
    private function check_proxy_headers() {
        // Skip proxy header check if using CloudFlare or known CDN
        if ($this->is_cloudflare_or_legit_cdn()) {
            return false;
        }

        // These headers are more reliable indicators of actual proxies/VPNs
        $proxy_headers = array(
            'HTTP_VIA',
            'HTTP_PROXY_CONNECTION',
            'HTTP_XPROXY_CONNECTION',
            'HTTP_X_PROXY_ID',
            'HTTP_PROXY_AUTHORIZATION'
        );

        foreach ($proxy_headers as $header) {
            if (!empty($_SERVER[$header])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if request is from CloudFlare or legitimate CDN/Proxy
     */
    private function is_cloudflare_or_legit_cdn() {
        // Check for CloudFlare headers
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) || isset($_SERVER['HTTP_CF_RAY'])) {
            return true;
        }
        
        // Check for Fastly CDN
        if (isset($_SERVER['HTTP_FASTLY_CLIENT_IP'])) {
            return true;
        }
        
        // Check for AWS CloudFront
        if (isset($_SERVER['HTTP_CLOUDFRONT_FORWARDED_PROTO'])) {
            return true;
        }
        
        // Check for Akamai
        if (isset($_SERVER['HTTP_TRUE_CLIENT_IP'])) {
            return true;
        }
        
        // Check for common reverse proxy/load balancer setups
        if (isset($_SERVER['HTTP_X_REAL_IP'])) {
            return true;
        }

        // Check for Sucuri firewall
        if (isset($_SERVER['HTTP_X_SUCURI_CLIENTIP'])) {
            return true;
        }
        
        return false;
    }

    /**
     * Check IP reputation (basic datacenter detection)
     * 
     * WARNING: This function is DISABLED because:
     * 1. gethostbyaddr() is slow and can timeout
     * 2. Many Bangladesh ISPs have 'cloud', 'server' in their hostname
     * 3. Facebook/Google Ads traffic may come through CDN with datacenter hostnames
     * 4. This causes massive false positives blocking legitimate orders
     * 
     * @return bool Always returns false - function is disabled
     */
    private function check_ip_reputation() {
        // DISABLED - Too many false positives
        // This was blocking legitimate traffic from:
        // - Facebook Ads users
        // - Google Ads users  
        // - Users behind corporate firewalls
        // - Users on certain Bangladesh ISPs
        return false;
        
        /* Original code preserved for reference:
        $ip = $this->get_real_ip();
        
        if (empty($ip) || $ip === '127.0.0.1' || $ip === '::1') {
            return false;
        }

        // Check for known datacenter/hosting IP patterns
        $hostname = gethostbyaddr($ip);
        
        $datacenter_keywords = array(
            'amazon', 'aws', 'google', 'digitalocean', 'linode', 
            'vultr', 'ovh', 'hetzner', 'cloudflare', 'hosting',
            'server', 'datacenter', 'vps', 'cloud', 'azure'
        );

        foreach ($datacenter_keywords as $keyword) {
            if (stripos($hostname, $keyword) !== false) {
                return true;
            }
        }

        return false;
        */
    }

    /**
     * Get real IP address
     */
    private function get_real_ip() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }

        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }

        return '';
    }

    /**
     * Enqueue frontend scripts
     */
    public function enqueue_frontend_scripts() {
        // Check for standard WooCommerce checkout or Cartflows checkout
        $is_checkout_page = is_checkout();
        
        // Also check for Cartflows step pages
        if (!$is_checkout_page && function_exists('wcf_get_current_step_type')) {
            $step_type = wcf_get_current_step_type();
            if ($step_type === 'checkout') {
                $is_checkout_page = true;
            }
        }
        
        if (!$is_checkout_page) {
            return;
        }

        wp_add_inline_style('woocommerce-general', $this->get_frontend_styles());
    }

    /**
     * Get frontend styles
     */
    private function get_frontend_styles() {
        return "
            .woocommerce-error li[data-id*='vpn'] {
                background: #fee;
                border-left: 4px solid #e74c3c;
                padding: 15px;
                border-radius: 8px;
            }
        ";
    }

    /**
     * Get VPN detection stats
     */
    public function get_blocked_count() {
        return intval(get_option('guardify_vpn_blocked_count', 0));
    }

    /**
     * Increment blocked count
     */
    public function increment_blocked_count() {
        $count = $this->get_blocked_count();
        update_option('guardify_vpn_blocked_count', $count + 1);
    }
}
