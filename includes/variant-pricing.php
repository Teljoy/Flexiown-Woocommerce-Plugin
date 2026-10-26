<?php
/**
 * FlexiOwn Variant Pricing
 *
 * Adds monthly/upfront pricing fields at variation level.
 * Handles frontend price display updates when variants are selected.
 * This file is part of the Flexiown plugin for portability.
 *
 * @package FlexiOwn_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * ============================================================
 * Variation-level Monthly / Upfront pricing fields
 * ============================================================
 */

/**
 * Add Monthly/Upfront fields to each variation row in admin
 */
add_action( 'woocommerce_variation_options_pricing', 'flexiown_add_variation_price_fields', 10, 3 );

function flexiown_add_variation_price_fields( $loop, $variation_data, $variation ) {
    woocommerce_wp_text_input( [
        'id'            => "variable_monthly_price{$loop}",
        'name'          => "variable_monthly_price[{$loop}]",
        'value'         => get_post_meta( $variation->ID, '_monthly_product_price', true ),
        'label'         => __( 'Monthly Product Price', 'woocommerce' ),
        'desc_tip'      => true,
        'description'   => __( "Leave blank to fall back to the parent product's Monthly Product Price.", 'woocommerce' ),
        'data_type'     => 'price',
        'wrapper_class' => 'form-row form-row-first',
    ] );

    woocommerce_wp_text_input( [
        'id'            => "variable_upfront_price{$loop}",
        'name'          => "variable_upfront_price[{$loop}]",
        'value'         => get_post_meta( $variation->ID, '_upfront_product_price', true ),
        'label'         => __( 'Upfront Price', 'woocommerce' ),
        'desc_tip'      => true,
        'description'   => __( "Leave blank to fall back to the parent product's Upfront Price.", 'woocommerce' ),
        'data_type'     => 'price',
        'wrapper_class' => 'form-row form-row-last',
    ] );
}

/**
 * Save the variation price fields
 */
add_action( 'woocommerce_save_product_variation', 'flexiown_save_variation_price_fields', 10, 2 );

function flexiown_save_variation_price_fields( $variation_id, $i ) {
    if ( isset( $_POST['variable_monthly_price'][ $i ] ) ) {
        update_post_meta( $variation_id, '_monthly_product_price', sanitize_text_field( $_POST['variable_monthly_price'][ $i ] ) );
    }
    if ( isset( $_POST['variable_upfront_price'][ $i ] ) ) {
        update_post_meta( $variation_id, '_upfront_product_price', sanitize_text_field( $_POST['variable_upfront_price'][ $i ] ) );
    }
}

/**
 * Push each variation's prices into the frontend variation JSON
 * so JS can update the display instantly without an extra request
 */
add_filter( 'woocommerce_available_variation', 'flexiown_add_variation_prices_to_json', 10, 3 );

function flexiown_add_variation_prices_to_json( $data, $product, $variation ) {
    $data['flexi_monthly_price'] = get_post_meta( $variation->get_id(), '_monthly_product_price', true );
    $data['flexi_upfront_price'] = get_post_meta( $variation->get_id(), '_upfront_product_price', true );
    return $data;
}

/**
 * ============================================================
 * Frontend price display shortcode and JS
 * ============================================================
 */

/**
 * Shared HTML builder — used by both the PHP shortcode render and
 * kept in sync with the JS renderer below so output matches exactly
 */
function flexiown_render_price_html( $upfront, $monthly ) {
    if ( ! $upfront && ! $monthly ) {
        return '';
    }

    $output = '';

    if ( $upfront ) {
        $output .= '<span class="flexi-upfront-price"><strong>R' . esc_html( number_format( (float) $upfront, 2 ) ) . '</strong> upfront</span>';
    }

    if ( $upfront && $monthly ) {
        $output .= ' <strong>+</strong> ';
    }

    if ( $monthly ) {
        $output .= '<span class="flexi-monthly-price"><strong>R' . esc_html( number_format( (float) $monthly, 2 ) ) . '</strong> per month</span>';
    }

    return $output;
}

/**
 * [flexi_price_display] shortcode — renders parent-level prices by default,
 * and carries them as data attributes so JS can revert to them
 */
add_shortcode( 'flexi_price_display', 'flexiown_price_display_shortcode' );

function flexiown_price_display_shortcode( $atts ) {
    $atts = shortcode_atts( [ 'id' => null ], $atts );
    $product_id = $atts['id'] ? intval( $atts['id'] ) : get_the_ID();
    $product = wc_get_product( $product_id );

    if ( ! $product ) {
        return '';
    }

    $upfront = get_post_meta( $product_id, '_upfront_product_price', true );
    $monthly = get_post_meta( $product_id, '_monthly_product_price', true );

    return sprintf(
        '<span class="flexi-price-display" data-parent-upfront="%s" data-parent-monthly="%s">%s</span>',
        esc_attr( $upfront ),
        esc_attr( $monthly ),
        flexiown_render_price_html( $upfront, $monthly )
    );
}

/**
 * Frontend: update .flexi-price-display live when a variation changes
 */
add_action( 'wp_footer', 'flexiown_variation_price_js' );

function flexiown_variation_price_js() {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) {
        return;
    }

    global $product;

    if ( ! $product || ! $product->is_type( 'variable' ) ) {
        return;
    }

    ?>
    <script>
    jQuery(function($) {
        var $form = $('form.cart.variations_form');
        if (!$form.length) return;

        function formatPrice(value) {
            var num = parseFloat(value);
            if (isNaN(num)) return '';
            return num.toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function renderInner(upfront, monthly) {
            if (!upfront && !monthly) return '';
            var html = '';
            if (upfront) {
                html += '<span class="flexi-upfront-price"><strong>R' + formatPrice(upfront) + '</strong> upfront</span>';
            }
            if (upfront && monthly) {
                html += ' <strong>+</strong> ';
            }
            if (monthly) {
                html += '<span class="flexi-monthly-price"><strong>R' + formatPrice(monthly) + '</strong> per month</span>';
            }
            return html;
        }

        function updateDisplays(variationUpfront, variationMonthly) {
            $('.flexi-price-display').each(function() {
                var $el = $(this);
                var upfront = variationUpfront || $el.data('parent-upfront') || '';
                var monthly = variationMonthly || $el.data('parent-monthly') || '';
                $el.html(renderInner(upfront, monthly));
            });
        }

        function resetDisplays() {
            $('.flexi-price-display').each(function() {
                var $el = $(this);
                $el.html(renderInner($el.data('parent-upfront') || '', $el.data('parent-monthly') || ''));
            });
        }

        $form.on('found_variation', function(event, variation) {
            if (!variation) return;
            updateDisplays(variation.flexi_upfront_price, variation.flexi_monthly_price);
        });

        $form.on('reset_data hide_variation', function() {
            resetDisplays();
        });
    });
    </script>
    <?php
}