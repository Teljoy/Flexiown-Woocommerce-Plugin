<?php

defined( 'ABSPATH' ) || exit;

/**
 * Variant Selector — [flexi_variant_selector] shortcode
 *
 * WooCommerce locks its variant dropdowns (e.g. "Choose a storage size") to a fixed spot in the page.
 * This shortcode lets us place those dropdowns anywhere in the Elementor layout instead.
 */

// Hide the WooCommerce variant dropdowns from view on product pages.
// They stay in the page (just invisible) so WooCommerce's logic still runs.
// Also applies basic styling to the replacement dropdowns rendered by the shortcode.
add_action( 'wp_head', function() {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) return;
    echo '<style>
        form.cart .variations { display: none !important; }
        .flexi-variant-field { margin-bottom: 12px; }
        .flexi-variant-field label { display: block; margin-bottom: 10px; font-weight: 600; }
        .flexi-proxy-select { width: 100%; padding: 8px; }
    </style>';
});

// [flexi_variant_selector] shortcode
// Place this in the Elementor template wherever the variant dropdowns should appear.
add_shortcode( 'flexi_variant_selector', function() {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) return '';
    global $product;
    return ( $product && $product->is_type( 'variable' ) ) ? '<div class="flexi-variant-proxy"></div>' : '';
});

// All variant-related JavaScript — only loaded on variable product pages to avoid unnecessary overhead
add_action( 'wp_footer', function() {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) return;
    global $product;
    if ( ! $product || ! $product->is_type( 'variable' ) ) return;
    $product_id = intval( $product->get_id() );
    $ajax_url   = esc_url( admin_url( 'admin-ajax.php' ) );
    ?>
    <script>
    (function($) {

        // Fills the [flexi_variant_selector] container with a copy of each WooCommerce variant dropdown.
        // When the customer picks an option here, it updates the hidden WooCommerce dropdown and triggers
        // WooCommerce's own price/stock update — so everything behaves as normal.
        // If WooCommerce resets a dropdown (e.g. customer clicks "Clear"), this resets to match.
        // The data-built flag stops this running twice if Elementor re-renders the page.
        function buildProxy() {
            var proxy = document.querySelector('.flexi-variant-proxy');
            var realTable = document.querySelector('form.cart .variations');
            if (!proxy || !realTable || proxy.dataset.built) return;
            proxy.dataset.built = '1';

            realTable.querySelectorAll('select').forEach(function(real) {
                var row = real.closest('tr');
                var labelText = row ? (row.querySelector('label')?.textContent || '').trim() : '';
                var wrap = document.createElement('div');
                wrap.className = 'flexi-variant-field';
                if (labelText) {
                    var lbl = document.createElement('label');
                    lbl.textContent = labelText;
                    wrap.appendChild(lbl);
                }
                var clone = real.cloneNode(true);
                clone.removeAttribute('name');
                clone.removeAttribute('id');
                clone.classList.add('flexi-proxy-select');
                wrap.appendChild(clone);
                proxy.appendChild(wrap);

                clone.addEventListener('change', function() {
                    real.value = clone.value;
                    real.dispatchEvent(new Event('change', { bubbles: true }));
                });
                real.addEventListener('change', function() {
                    if (clone.value !== real.value) clone.value = real.value;
                });
            });
        }

        // Automatically picks the first available option in each dropdown when the page loads,
        // so the customer sees a price straight away instead of a blank "Choose an option" state.
        function autoSelectFirst() {
            var reals = document.querySelectorAll('form.cart .variations select');
            reals.forEach(function(sel) {
                var first = Array.prototype.find.call(sel.options, function(o) { return o.value !== ''; });
                if (first && sel.value === '') {
                    sel.value = first.value;
                    sel.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            document.querySelectorAll('.flexi-proxy-select').forEach(function(p, i) {
                if (reals[i]) p.value = reals[i].value;
            });
        }

        // Run buildProxy as soon as the page is ready.
        // The 800ms timeout is a fallback for Elementor, which sometimes finishes rendering after the normal ready event.
        document.readyState === 'loading'
            ? document.addEventListener('DOMContentLoaded', buildProxy)
            : buildProxy();
        setTimeout(buildProxy, 800);
        setTimeout(autoSelectFirst, 1000); // runs after buildProxy at ~800ms

        // When a customer selects a variant, notify the Flexiown payment widget on the same page
        // so it can update its display. This avoids a separate server request.
        var $form = $('form.cart.variations_form');
        if ($form.length) {
            function broadcast(detail) {
                window.flexiOwnSelectedVariant = detail;
                document.dispatchEvent(new CustomEvent('flexiown:variant_changed', { detail: detail }));
                console.log('[Flexi Own] variant_changed', detail);
            }
            // Fires when the customer has picked a complete set of options and WooCommerce found a matching variant
            $form.on('found_variation', function(e, v) {
                if (!v) return;
                broadcast({ sku: v.sku, product_id: <?php echo $product_id; ?>, variation_id: v.variation_id,
                    monthly_price: v.flexi_monthly_price || null, upfront_price: v.flexi_upfront_price || null });
            });
            // Fires when the customer clears their selection or picks an option combination that has no matching variant
            $form.on('reset_data hide_variation', function() { broadcast(null); });
        }

        // When a customer selects a variant, fetch the Flexiown monthly price for that specific variant.
        // Each variant can have its own price, so this needs to be looked up fresh each time.
        // The Apply Now buttons are disabled while the price loads to prevent the customer
        // from applying before the correct price is confirmed.
        // If no price comes back (e.g. the product isn't available on Flexiown), the price section is hidden.
        var flexiownBound = false;
        function bindPrice() {
            var $vform = $('form.variations_form');
            if (!$vform.length || flexiownBound) return;
            flexiownBound = true;
            var $priceEl = $('#flexiown-monthly-price');
            var $copy    = $('.flexiown-copy');
            var $btns    = $('.single_add_to_cart_button, #mobile-apply-btn .elementor-button');

            $vform.on('found_variation.flexiown', function(e, v) {
                if (!v.variation_id) return;
                $priceEl.text('...');
                $copy.show();
                $btns.prop('disabled', true).css('opacity', '0.5');
                $.post('<?php echo $ajax_url; ?>', { action: 'flexiown_variation_price', variation_id: v.variation_id })
                    .done(function(r) { r.success ? $priceEl.text('R' + r.data.price + ',00 per month') : $copy.hide(); })
                    .fail(function() { $copy.hide(); })
                    .always(function() { $btns.prop('disabled', false).css('opacity', '1'); });
            });
            // Clear the price display when the customer changes or clears their selection
            $vform.on('reset_data.flexiown', function() { $copy.hide(); $priceEl.text(''); });

            // Fetch the price for the first variant automatically on page load
            // so the customer sees a price without needing to interact with the dropdowns first
            var vars = $vform.data('product_variations');
            if (vars && vars.length) {
                $.each(vars[0].attributes, function(attr, val) {
                    $vform.find('select[name=' + attr + ']').val(val).trigger('change');
                });
            }
        }

        $(document).ready(bindPrice);
        $(window).on('load', bindPrice);
    })(jQuery);
    </script>
    <?php
});

// Called by the browser when a customer selects a variant.
// Looks up the Flexiown monthly price for that variant via the payment gateway API
// and sends it back to the browser. If anything goes wrong, returns an error gracefully
// so the page doesn't break. Works for both guest shoppers and logged-in customers.
function flexiown_variation_price_ajax() {
    $vid = isset( $_POST['variation_id'] ) ? intval( $_POST['variation_id'] ) : 0;
    if ( ! $vid ) { wp_send_json_error(); return; }
    $product = wc_get_product( $vid );
    if ( ! $product ) { wp_send_json_error(); return; }
    try {
        $result = ( new WC_Gateway_Flexiown() )->api_product_lookup( $product, $vid );
    } catch ( Exception $e ) {
        wp_send_json_error(); return;
    }
    isset( $result->price ) ? wp_send_json_success( [ 'price' => $result->price ] ) : wp_send_json_error();
}
add_action( 'wp_ajax_flexiown_variation_price',        'flexiown_variation_price_ajax' );
add_action( 'wp_ajax_nopriv_flexiown_variation_price', 'flexiown_variation_price_ajax' );
