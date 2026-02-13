<?php
/**
 * Guardify Invoice Template
 *
 * @package Guardify
 * @since 2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get order ID from query parameter
$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

if ( ! $order_id ) {
    wp_die( esc_html__( 'Invalid order ID.', 'guardify' ) );
}

// Note: Nonce is already verified in Guardify_SteadFast::render_invoice_template()
// using 'guardify_print_invoice' action before including this template

// Get order object
$order = wc_get_order( $order_id );

if ( ! $order ) {
    wp_die( esc_html__( 'Order not found.', 'guardify' ) );
}

// Get business info from settings
$business_name    = get_option( 'guardify_steadfast_business_name', get_bloginfo( 'name' ) );
$business_address = get_option( 'guardify_steadfast_business_address', '' );
$business_email   = get_option( 'guardify_steadfast_business_email', get_option( 'admin_email' ) );
$business_phone   = get_option( 'guardify_steadfast_business_phone', '' );
$business_logo    = get_option( 'guardify_steadfast_business_logo', '' );
$terms_conditions = get_option( 'guardify_steadfast_terms', '' );

// Get SteadFast meta
$consignment_id = $order->get_meta( '_guardify_steadfast_consignment_id' );

// Get order details
$order_date     = $order->get_date_created();
$order_number   = $order->get_order_number();
$payment_method = $order->get_payment_method_title();

// Get customer details
$billing_name    = $order->get_formatted_billing_full_name();
$billing_address = $order->get_formatted_billing_address();
$billing_phone   = $order->get_billing_phone();
$billing_email   = $order->get_billing_email();

// Currency
$currency = $order->get_currency();

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php printf( esc_html__( 'Invoice #%s', 'guardify' ), esc_html( $order_number ) ); ?></title>
    <link rel="stylesheet" href="<?php echo esc_url( GUARDIFY_PLUGIN_URL . 'assets/css/invoice.css' ); ?>">
    <style>
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body class="guardify-invoice">
    <div class="guardify-invoice-container">
        <div class="guardify-invoice-wrap">
            <div class="guardify-invoice-inner">
                
                <!-- Header -->
                <div class="guardify-invoice-header">
                    <div class="guardify-invoice-logo">
                        <?php if ( $business_logo ) : ?>
                            <img src="<?php echo esc_url( $business_logo ); ?>" alt="<?php echo esc_attr( $business_name ); ?>">
                        <?php else : ?>
                            <h2 class="guardify-primary-color"><?php echo esc_html( $business_name ); ?></h2>
                        <?php endif; ?>
                    </div>
                    <div class="guardify-invoice-title">
                        <?php esc_html_e( 'INVOICE', 'guardify' ); ?>
                    </div>
                </div>
                
                <!-- Invoice Info -->
                <div class="guardify-invoice-info">
                    <div class="guardify-invoice-separator"></div>
                    <div class="guardify-invoice-meta">
                        <p>
                            <strong><?php esc_html_e( 'Invoice:', 'guardify' ); ?></strong>
                            #<?php echo esc_html( $order_number ); ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e( 'Date:', 'guardify' ); ?></strong>
                            <?php echo esc_html( $order_date ? $order_date->date_i18n( get_option( 'date_format' ) ) : '' ); ?>
                        </p>
                    </div>
                </div>
                
                <hr class="guardify-mb-15">
                
                <!-- Addresses -->
                <div class="guardify-invoice-addresses guardify-mb-15">
                    <div class="guardify-invoice-to">
                        <p class="guardify-invoice-label"><?php esc_html_e( 'Invoice To:', 'guardify' ); ?></p>
                        <p class="guardify-primary-color guardify-font-semi-bold"><?php echo esc_html( $billing_name ); ?></p>
                        <?php if ( $billing_address ) : ?>
                            <p><?php echo wp_kses_post( $billing_address ); ?></p>
                        <?php endif; ?>
                        <?php if ( $billing_phone ) : ?>
                            <p><?php esc_html_e( 'Phone:', 'guardify' ); ?> <?php echo esc_html( $billing_phone ); ?></p>
                        <?php endif; ?>
                        <?php if ( $billing_email ) : ?>
                            <p><?php esc_html_e( 'Email:', 'guardify' ); ?> <?php echo esc_html( $billing_email ); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="guardify-invoice-from guardify-text-right">
                        <p class="guardify-invoice-label"><?php esc_html_e( 'Invoice From:', 'guardify' ); ?></p>
                        <p class="guardify-primary-color guardify-font-semi-bold"><?php echo esc_html( $business_name ); ?></p>
                        <?php if ( $business_address ) : ?>
                            <p><?php echo esc_html( $business_address ); ?></p>
                        <?php endif; ?>
                        <?php if ( $business_phone ) : ?>
                            <p><?php esc_html_e( 'Phone:', 'guardify' ); ?> <?php echo esc_html( $business_phone ); ?></p>
                        <?php endif; ?>
                        <?php if ( $business_email ) : ?>
                            <p><?php esc_html_e( 'Email:', 'guardify' ); ?> <?php echo esc_html( $business_email ); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Consignment Box (if sent to SteadFast) -->
                <?php if ( $consignment_id ) : ?>
                <div class="guardify-consignment-box guardify-mb-15">
                    <div class="guardify-consignment-header">
                        <h4><?php esc_html_e( 'Courier Information', 'guardify' ); ?></h4>
                        <div class="guardify-consignment-logo">
                            <span style="font-weight: 600; color: #dc2626; font-size: 16px;">🚚 SteadFast</span>
                        </div>
                    </div>
                    <hr>
                    <div class="guardify-consignment-details">
                        <div>
                            <p class="guardify-text-muted guardify-mb-5"><?php esc_html_e( 'Consignment ID', 'guardify' ); ?></p>
                            <h5><?php echo esc_html( $consignment_id ); ?></h5>
                        </div>
                        <div>
                            <p class="guardify-text-muted guardify-mb-5"><?php esc_html_e( 'Courier', 'guardify' ); ?></p>
                            <h5>SteadFast</h5>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Products Table -->
                <div class="guardify-invoice-table guardify-mb-15">
                    <div class="guardify-table-wrap">
                        <div class="guardify-table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th class="guardify-col-item"><?php esc_html_e( 'Item', 'guardify' ); ?></th>
                                        <th class="guardify-col-desc"><?php esc_html_e( 'Description', 'guardify' ); ?></th>
                                        <th class="guardify-col-price"><?php esc_html_e( 'Price', 'guardify' ); ?></th>
                                        <th class="guardify-col-qty"><?php esc_html_e( 'Qty', 'guardify' ); ?></th>
                                        <th class="guardify-col-total"><?php esc_html_e( 'Total', 'guardify' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $order->get_items() as $item_id => $item ) : 
                                        $product = $item->get_product();
                                        $product_name = $item->get_name();
                                        $quantity = $item->get_quantity();
                                        $subtotal = $item->get_subtotal();
                                        $total = $item->get_total();
                                        $price = $quantity > 0 ? $subtotal / $quantity : 0;
                                        
                                        // Get product description/short description
                                        $description = '';
                                        if ( $product ) {
                                            $description = $product->get_short_description();
                                            if ( empty( $description ) ) {
                                                $description = wp_trim_words( $product->get_description(), 15 );
                                            }
                                        }
                                        
                                        // Get item meta (variations, etc.)
                                        $item_meta = array();
                                        $meta_data = $item->get_meta_data();
                                        foreach ( $meta_data as $meta ) {
                                            $display_key = wc_attribute_label( $meta->key, $product );
                                            if ( ! empty( $meta->value ) && ! is_serialized( $meta->value ) && strpos( $meta->key, '_' ) !== 0 ) {
                                                $item_meta[] = $display_key . ': ' . $meta->value;
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td class="guardify-col-item">
                                            <span class="guardify-primary-color guardify-font-semi-bold"><?php echo esc_html( $product_name ); ?></span>
                                            <?php if ( $product && $product->get_sku() ) : ?>
                                                <br><small class="guardify-text-muted">SKU: <?php echo esc_html( $product->get_sku() ); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="guardify-col-desc">
                                            <?php 
                                            if ( ! empty( $item_meta ) ) {
                                                echo '<small>' . esc_html( implode( ', ', $item_meta ) ) . '</small>';
                                            } elseif ( $description ) {
                                                echo '<small>' . esc_html( wp_trim_words( $description, 10 ) ) . '</small>';
                                            }
                                            ?>
                                        </td>
                                        <td class="guardify-col-price">
                                            <?php echo wp_kses_post( wc_price( $price, array( 'currency' => $currency ) ) ); ?>
                                        </td>
                                        <td class="guardify-col-qty" style="text-align: center;">
                                            <?php echo esc_html( $quantity ); ?>
                                        </td>
                                        <td class="guardify-col-total" style="text-align: right;">
                                            <?php echo wp_kses_post( wc_price( $total, array( 'currency' => $currency ) ) ); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Footer / Totals -->
                <div class="guardify-invoice-footer">
                    <div class="guardify-payment-info">
                        <p class="guardify-invoice-label"><?php esc_html_e( 'Payment Information', 'guardify' ); ?></p>
                        <p>
                            <strong><?php esc_html_e( 'Payment Method:', 'guardify' ); ?></strong>
                            <?php echo esc_html( $payment_method ); ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e( 'Status:', 'guardify' ); ?></strong>
                            <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
                        </p>
                    </div>
                    <div class="guardify-totals">
                        <div class="guardify-table-wrap">
                            <table>
                                <tbody>
                                    <tr>
                                        <td class="guardify-total-label"><?php esc_html_e( 'Subtotal', 'guardify' ); ?></td>
                                        <td class="guardify-total-value">
                                            <?php echo wp_kses_post( wc_price( $order->get_subtotal(), array( 'currency' => $currency ) ) ); ?>
                                        </td>
                                    </tr>
                                    
                                    <?php if ( $order->get_shipping_total() > 0 ) : ?>
                                    <tr>
                                        <td class="guardify-total-label"><?php esc_html_e( 'Shipping', 'guardify' ); ?></td>
                                        <td class="guardify-total-value">
                                            <?php echo wp_kses_post( wc_price( $order->get_shipping_total(), array( 'currency' => $currency ) ) ); ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    
                                    <?php if ( $order->get_total_discount() > 0 ) : ?>
                                    <tr>
                                        <td class="guardify-total-label"><?php esc_html_e( 'Discount', 'guardify' ); ?></td>
                                        <td class="guardify-total-value">
                                            -<?php echo wp_kses_post( wc_price( $order->get_total_discount(), array( 'currency' => $currency ) ) ); ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    
                                    <?php if ( wc_tax_enabled() && $order->get_total_tax() > 0 ) : ?>
                                    <tr>
                                        <td class="guardify-total-label"><?php esc_html_e( 'Tax', 'guardify' ); ?></td>
                                        <td class="guardify-total-value">
                                            <?php echo wp_kses_post( wc_price( $order->get_total_tax(), array( 'currency' => $currency ) ) ); ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    
                                    <tr class="guardify-grand-total">
                                        <td class="guardify-total-label"><?php esc_html_e( 'Total', 'guardify' ); ?></td>
                                        <td class="guardify-total-value">
                                            <?php echo wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $currency ) ) ); ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Terms & Conditions -->
                <?php if ( $terms_conditions ) : ?>
                <div class="guardify-terms">
                    <h4><?php esc_html_e( 'Terms & Conditions', 'guardify' ); ?></h4>
                    <p><?php echo wp_kses_post( nl2br( $terms_conditions ) ); ?></p>
                </div>
                <?php endif; ?>
                
            </div>
        </div>
    </div>
    
    <script>
        // Auto print on load
        window.onload = function() {
            window.print();
        };
    </script>
</body>
</html>
