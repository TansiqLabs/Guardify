<?php
/**
 * Guardify Order Completed By Class
 * অর্ডার কে কমপ্লিট করেছে তার ইউজারনেম ট্র্যাক করা
 * 
 * @package Guardify
 * @since 1.0.0
 * @updated 2.1.0 - Improved error handling, WP_DEBUG conditional logging
 */

if (!defined('ABSPATH')) {
    exit;
}

class Guardify_Order_Completed_By {
    private static ?Guardify_Order_Completed_By $instance = null;
    
    /**
     * Flag to prevent recursion during order save
     */
    private bool $is_saving = false;

    public static function get_instance(): Guardify_Order_Completed_By {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ($this->is_enabled()) {
            // Track who changes order status - use lower priority to ensure we run after other hooks
            add_action('woocommerce_order_status_changed', array($this, 'track_status_change'), 99, 4);
            
            // Add column to order list (Legacy)
            add_filter('manage_edit-shop_order_columns', array($this, 'add_completed_by_column'));
            add_action('manage_shop_order_posts_custom_column', array($this, 'display_completed_by_column'), 10, 2);
            
            // Add column to WooCommerce Orders page (HPOS)
            add_filter('woocommerce_shop_order_list_table_columns', array($this, 'add_completed_by_column'));
            add_action('woocommerce_shop_order_list_table_custom_column', array($this, 'display_completed_by_column_hpos'), 10, 2);
            
            // Add to order preview modal - use the correct hook
            add_action('woocommerce_admin_order_preview_start', array($this, 'add_to_order_preview'), 10);
            
            // Also add to preview end for better compatibility
            add_action('woocommerce_admin_order_preview_end', array($this, 'add_to_order_preview_end'), 10);
            
            // Add styles (enqueue CSS file; inline styles are included in the file itself)
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
        }
    }

    /**
     * Check if feature is enabled
     */
    public function is_enabled(): bool {
        return get_option('guardify_order_completed_by_enabled', '1') === '1';
    }

    /**
     * Track who changes the order status
     * 
     * Fixed in v2.0.0: Added recursion prevention to avoid infinite loops
     */
    public function track_status_change(int $order_id, string $old_status, string $new_status, $order): void {
        // Prevent recursion when saving order
        if ($this->is_saving) {
            return;
        }
        
        $current_user = wp_get_current_user();
        
        if ($current_user->ID && $order) {
            // Set flag to prevent recursion
            $this->is_saving = true;
            
            try {
                // Store the user who made the status change
                $order->update_meta_data('_guardify_status_changed_by', $current_user->ID);
                $order->update_meta_data('_guardify_status_changed_by_name', $current_user->display_name);
                $order->update_meta_data('_guardify_status_changed_time', current_time('mysql'));
                $order->update_meta_data('_guardify_status_changed_to', $new_status);
                
                // If order is completed, store specifically
                if ($new_status === 'completed') {
                    $order->update_meta_data('_guardify_completed_by', $current_user->ID);
                    $order->update_meta_data('_guardify_completed_by_name', $current_user->display_name);
                    $order->update_meta_data('_guardify_completed_time', current_time('mysql'));
                }
                
                // Remove the action temporarily to prevent recursive calls
                remove_action('woocommerce_order_status_changed', array($this, 'track_status_change'), 99);
                
                $order->save();
                
                // Re-add the action
                add_action('woocommerce_order_status_changed', array($this, 'track_status_change'), 99, 4);
                
            } catch (Exception $e) {
                // Log error but don't block (only in debug mode)
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Guardify: Error tracking status change - ' . $e->getMessage());
                }
            } finally {
                // Always reset the flag
                $this->is_saving = false;
            }
        }
    }

    /**
     * Add column to order list
     */
    public function add_completed_by_column(array $columns): array {
        $new_columns = array();
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            // Add after order_status column
            if ($key === 'order_status') {
                $new_columns['guardify_completed_by'] = __('Status Changed By', 'guardify');
            }
        }
        
        return $new_columns;
    }

    /**
     * Display column content (Legacy)
     */
    public function display_completed_by_column(string $column, int $post_id): void {
        if ($column !== 'guardify_completed_by') {
            return;
        }

        $order = wc_get_order($post_id);
        if (!$order) {
            return;
        }

        $this->render_completed_by_info($order);
    }

    /**
     * Display column content (HPOS)
     */
    public function display_completed_by_column_hpos(string $column, $order): void {
        if ($column !== 'guardify_completed_by') {
            return;
        }

        $this->render_completed_by_info($order);
    }

    /**
     * Render completed by info
     */
    private function render_completed_by_info($order): void {
        if (!$order) {
            return;
        }
        
        $changed_by_id = $order->get_meta('_guardify_status_changed_by');
        $changed_by_name = $order->get_meta('_guardify_status_changed_by_name');
        $changed_time = $order->get_meta('_guardify_status_changed_time');
        $changed_to = $order->get_meta('_guardify_status_changed_to');
        
        if ($changed_by_name) {
            $status_label = wc_get_order_status_name($changed_to);
            $time_formatted = '';
            
            if ($changed_time) {
                $time_formatted = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($changed_time));
            }
            
            // Get user color
            $user_colors = $this->get_user_color($changed_by_id);
            
            echo '<div class="guardify-status-changed">';
            echo '<span class="guardify-user-badge" style="background: ' . esc_attr($user_colors['bg']) . '; color: ' . esc_attr($user_colors['text']) . ';">';
            echo esc_html($changed_by_name);
            echo '</span>';
            if ($time_formatted) {
                echo '<br><small class="guardify-time">' . esc_html($time_formatted) . '</small>';
            }
            echo '</div>';
        } else {
            echo '<span class="guardify-no-data">—</span>';
        }
    }

    /**
     * Get user color based on user ID
     */
    private function get_user_color($user_id): array {
        $user_colors = get_option('guardify_user_colors', array());
        
        // If user has assigned color
        if (isset($user_colors[$user_id])) {
            $color_index = $user_colors[$user_id];
            return $this->get_color_scheme($color_index);
        }
        
        // Default color
        return $this->get_color_scheme(0);
    }

    /**
     * Get color scheme by index
     */
    private function get_color_scheme(int $index): array {
        $color_schemes = array(
            0 => array('bg' => '#667eea', 'text' => '#ffffff'), // Purple
            1 => array('bg' => '#f093fb', 'text' => '#ffffff'), // Pink
            2 => array('bg' => '#4facfe', 'text' => '#ffffff'), // Blue
            3 => array('bg' => '#43e97b', 'text' => '#ffffff'), // Green
            4 => array('bg' => '#fa709a', 'text' => '#ffffff'), // Rose
            5 => array('bg' => '#feca57', 'text' => '#2c3e50'), // Yellow
            6 => array('bg' => '#ff6348', 'text' => '#ffffff'), // Red
            7 => array('bg' => '#00d2ff', 'text' => '#ffffff'), // Cyan
            8 => array('bg' => '#a29bfe', 'text' => '#ffffff'), // Lavender
            9 => array('bg' => '#fd79a8', 'text' => '#ffffff'), // Flamingo
        );
        
        return isset($color_schemes[$index]) ? $color_schemes[$index] : $color_schemes[0];
    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles(string $hook): void {
        // Check if we're on orders page (HPOS or legacy)
        $screen = get_current_screen();
        $is_orders_page = false;
        
        if ($screen) {
            $is_orders_page = (
                $screen->id === 'shop_order' || 
                $screen->id === 'edit-shop_order' || 
                $screen->id === 'woocommerce_page_wc-orders' ||
                $hook === 'woocommerce_page_wc-orders'
            );
        }
        
        if (!$is_orders_page) {
            return;
        }

        // Enqueue custom CSS file
        wp_enqueue_style(
            'guardify-order-tracking',
            GUARDIFY_PLUGIN_URL . 'assets/css/order-tracking.css',
            array(),
            GUARDIFY_VERSION
        );
        
        // Also add inline styles as fallback
        wp_add_inline_style('guardify-order-tracking', $this->get_admin_styles());
    }

    /**
     * Get admin styles
     */
    private function get_admin_styles(): string {
        return "
            .guardify-status-changed {
                font-size: 12px;
            }
            .guardify-user-badge {
                display: inline-block;
                padding: 8px 16px;
                border-radius: 8px;
                font-size: 12px;
                font-weight: 600;
                box-shadow: 0 2px 8px rgba(0,0,0,0.12);
                transition: all 0.3s ease;
                border: 2px solid rgba(255,255,255,0.3);
                line-height: 1.5;
                min-width: 80px;
                text-align: center;
            }
            .guardify-user-badge:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 16px rgba(0,0,0,0.2);
            }
            .guardify-time {
                color: #666;
                font-size: 11px;
                margin-top: 4px;
                display: inline-block;
            }
            .guardify-no-data {
                color: #999;
                font-style: italic;
            }
            .column-guardify_completed_by {
                width: 150px;
            }
            
            /* Order Preview Modal Styles */
            .wc-order-preview-guardify-info {
                margin: 16px 0;
                padding: 16px;
                background: #f8f9fa;
                border-radius: 8px;
                border-left: 4px solid #2db7ae;
            }
            .wc-order-preview-guardify-info h3 {
                margin: 0 0 12px 0;
                font-size: 14px;
                font-weight: 600;
                color: #2c3e50;
            }
            .wc-order-preview-guardify-info .guardify-user-badge {
                margin-bottom: 8px;
            }
        ";
    }
    
    /**
     * Add inline styles directly to admin head
     */
    public function add_inline_styles(): void {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }
        
        $is_orders_page = (
            $screen->id === 'shop_order' || 
            $screen->id === 'edit-shop_order' || 
            $screen->id === 'woocommerce_page_wc-orders'
        );
        
        if ($is_orders_page) {
            echo '<style type="text/css">' . $this->get_admin_styles() . '</style>';
        }
    }
    
    /**
     * Add status changed by info to order preview modal (start)
     * 
     * @param WC_Order $order The order object
     */
    public function add_to_order_preview($order): void {
        // Ensure we have a valid order object
        if (!$order || !is_object($order) || !method_exists($order, 'get_meta')) {
            return;
        }
        
        $this->render_preview_info($order);
    }
    
    /**
     * Add status changed by info to order preview modal (end)
     * Alternative hook for better compatibility
     * 
     * @param WC_Order $order The order object
     */
    public function add_to_order_preview_end($order): void {
        // Don't output twice - check if already rendered
        static $rendered_orders = array();
        
        if (!$order || !is_object($order) || !method_exists($order, 'get_id')) {
            return;
        }
        
        $order_id = $order->get_id();
        
        if (isset($rendered_orders[$order_id])) {
            return;
        }
        
        $rendered_orders[$order_id] = true;
        
        // Only render if not already rendered by start hook
        // This acts as a fallback
    }
    
    /**
     * Render preview info HTML
     * 
     * @param WC_Order $order The order object
     */
    private function render_preview_info($order): void {
        $changed_by_id = $order->get_meta('_guardify_status_changed_by');
        $changed_by_name = $order->get_meta('_guardify_status_changed_by_name');
        $changed_time = $order->get_meta('_guardify_status_changed_time');
        $changed_to = $order->get_meta('_guardify_status_changed_to');
        
        if ($changed_by_name) {
            $status_label = wc_get_order_status_name($changed_to);
            $time_formatted = '';
            
            if ($changed_time) {
                $time_formatted = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($changed_time));
            }
            
            // Get user color
            $user_colors = $this->get_user_color($changed_by_id);
            
            echo '<div class="wc-order-preview-guardify-info">';
            echo '<h3>' . esc_html__('Status Changed By', 'guardify') . '</h3>';
            echo '<div class="guardify-status-changed">';
            echo '<span class="guardify-user-badge" style="background: ' . esc_attr($user_colors['bg']) . '; color: ' . esc_attr($user_colors['text']) . ';">';
            echo esc_html($changed_by_name);
            echo '</span>';
            if ($time_formatted) {
                echo '<br><small class="guardify-time">' . esc_html($time_formatted) . '</small>';
            }
            if ($status_label) {
                echo '<br><small class="guardify-time">' . sprintf(esc_html__('Changed to: %s', 'guardify'), esc_html($status_label)) . '</small>';
            }
            echo '</div>';
            echo '</div>';
        }
    }
}