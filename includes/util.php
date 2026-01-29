<?php

/**
 * PRODUCT PAGE SETTINGS TAB
 **/
function wk_custom_product_tab($default_tabs)
{
    $default_tabs['flexiown_settings'] = array(
        'label'   =>  __('Flexiown Settings', 'domain'),
        'target'  =>  'wk_flexiown_tab_data',
        'priority' => 60,
        'class'   => array()
    );
    return $default_tabs;
}

add_filter('woocommerce_product_data_tabs', 'wk_custom_product_tab', 10, 1);

/**
 * 	: PRODUCT PAGE SETTINGS TAB CONTENT
 **/
add_action('woocommerce_product_data_panels', 'wk_flexiown_tab_data');
function wk_flexiown_tab_data()
{
    global $product_object;
?>
    <div id="wk_flexiown_tab_data" class="panel woocommerce_options_panel">
        <div class="options_group">
            <p class="form-field dimensions_field">
                <?php
                woocommerce_wp_text_input(
                    array(
                        'id'          => 'flexiown_barcode',
                        'value'       => get_post_meta(get_the_ID(), 'flexiown_barcode', true),
                        'label'       => __('Flexiown Barcode', 'woocommerce'),
                        'placeholder' => 'product barcode',
                        'desc_tip'    => true,
                        'description' => __('barcode used for flexiown', 'woocommerce'),
                        'type'        => 'text',
                        'data_type'   => 'decimal',
                    )
                );
                ?>
            </p>
        </div>
    </div>
<?php
}

//TODO: notify flexiown api when status changes
function woo_order_status_change_flexiown()
{
    $gateway = new WC_Gateway_Flexiown();

    if (!current_user_can('manage_options'))
        return false;
    if (!is_admin())
        return false;
    if ($_REQUEST['post_type'] != 'shop_order')
        return false;
    if ($_REQUEST['post_ID'] != '') {
        $orderId = $_REQUEST['post_ID'];
        $order = new WC_Order($orderId);
        if ($order->payment_method == 'flexiown') {
            $gateway->order_status_change_update($order);
        }
    }
}

add_action('woocommerce_order_status_changed', 'woo_order_status_change_flexiown', 10, 3);


/**
 * 	: Handing the saving of barcodes
 **/
add_action('woocommerce_process_product_meta', 'flexiown_save_fields', 10, 2);
function flexiown_save_fields($id, $post)
{

    if (!empty($_POST['flexiown_barcode'])) {
        update_post_meta($id, 'flexiown_barcode', $_POST['flexiown_barcode']);
    }
    // } else {
    // 	delete_post_meta( $id, 'flexiown_barcode' );
    // }

}


/**
 * ADD FLEXIOWN GATEWAY TO WOOCOMMERCE
 **/
function add_flexiown_gateway($methods)
{
    $methods[] = 'WC_Gateway_Flexiown';
    return $methods;
}
add_filter('woocommerce_payment_gateways', 'add_flexiown_gateway');

$options = get_option('woocommerce_flexiown_settings', 'gets the option');


if (isset($options['flexiown_on_cart'])) {
    $flexiown_on_cart = $options['flexiown_on_cart'];
}

if (isset($flexiown_on_cart) && $flexiown_on_cart == 'yes') {
    /* Display on Cart Page */
    function display_flexiown_cart()
    {

        global $woocommerce;
        $gateway = new WC_Gateway_Flexiown();
        $minPrice_cut = 0;
        $message = 'Or, From only ';
        $options = get_option('woocommerce_flexiown_settings', 'gets the option');
        $img = plugins_url('/assets/media/flexiown_pay_logo.png', __FILE__);
        if (isset($woocommerce->cart->cart_contents) && count($woocommerce->cart->cart_contents) >= 1) {

            $showFlexiown = true;
            $response = $gateway->api_bulk_product_lookup($woocommerce->cart->cart_contents);
            
            if (!$response || (isset($response->statusCode) && $response->statusCode == 500)) {
                return false;
            }

            foreach ($response as $item) {
                if ($item->accepted == false) {
                    $showFlexiown = false;
                } else {

                    if ($options['flexiown_cart_as_combined'] && $options['flexiown_cart_as_combined'] == 'yes') {
                        $minPrice_cut = ($minPrice_cut + $item->price);
                        $message = 'Or, From only ';
                    } else {
                        if ($item->price < $minPrice_cut || $minPrice_cut == 0) {
                            $minPrice_cut = $item->price;
                        }
                    }
                }
            }

            if ($showFlexiown) {
                echo wp_kses_post('<div id="float-on-cart" style="color:black !important;margin-top:4px;">' . $message . '<strong class="flexiown-highlight" style="color: #ff003e !important;font-size: inherit;font-weight: inherit;">' . wc_price($minPrice_cut) . ' per month</strong> , try it, love it, own it. <br/>Apply with <img src="' . $img . '" alt="Flexiown" class="float-logo" style="width: 70px;vertical-align: baseline;"/> <a target="_blank" href="https://www.flexiown.co.za/">Learn more</a></div>');
            }
        }
    }

    add_action('woocommerce_after_cart_totals', 'display_flexiown_cart', 9, 0);
}

// Always add support for WooCommerce Blocks cart (check setting inside the function)
add_action('wp_footer', 'display_flexiown_cart_blocks');

// Function specifically for blocks cart
function display_flexiown_cart_blocks() {
    // Check if cart display is enabled in settings
    $options = get_option('woocommerce_flexiown_settings', 'gets the option');
    if (!isset($options['flexiown_on_cart']) || $options['flexiown_on_cart'] != 'yes') {
        return;
    }
    
    // Only run on cart page
    if (!is_cart()) {
        return;
    }
    
    // Check if this is a blocks cart by looking for block elements
    global $post;
    if (!$post || !has_block('woocommerce/cart', $post)) {
        return; // Not a blocks cart
    }
    
    global $woocommerce;
    $gateway = new WC_Gateway_Flexiown();
    $minPrice_cut = 0;
    $message = 'Or, From only ';
    $options = get_option('woocommerce_flexiown_settings', 'gets the option');
    $img = FLEXIOWN_PLUGIN_URL . 'assets/media/flexiown_pay_logo.png';
    
    ?>
    <script>
        jQuery(document).ready(function($) {
            var flexiownCartInitialized = false;
            
            function initFlexiownCartDisplay() {
                console.log('Trying to initialize Flexiown cart display...');
                
                // Prevent multiple initializations
                if (flexiownCartInitialized) {
                    console.log('Already initialized, skipping...');
                    return;
                }
                
                // Check if cart block exists and legacy display doesn't exist
                if ($('.wp-block-woocommerce-cart').length > 0 && $('#float-on-cart').length === 0 && $('#float-on-cart-blocks').length === 0) {
                    console.log('Cart block found, initializing...');
                    flexiownCartInitialized = true;
                    
                    // Get cart data via AJAX
                    $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                        action: 'get_flexiown_cart_info'
                    }, function(response) {
                        console.log('AJAX response:', response);
                        if (response.success && response.data.show) {
                            // Try to find the checkout button area to place Flexiown display after it
                            var checkoutButton = $('.wp-block-woocommerce-proceed-to-checkout-block');
                            var checkoutButtonAlt = $('.wc-block-cart__submit-container');
                            var checkoutButtonGeneric = $('.wp-block-woocommerce-cart').find('button[type="submit"], .wc-block-components-button');
                            var cartTotalsBlock = $('.wp-block-woocommerce-cart .wc-block-components-totals');
                            var cartBlock = $('.wp-block-woocommerce-cart');
                            
                            console.log('Checkout button found:', checkoutButton.length);
                            console.log('Checkout button alt found:', checkoutButtonAlt.length);
                            console.log('Checkout button generic found:', checkoutButtonGeneric.length);
                            console.log('Cart totals block found:', cartTotalsBlock.length);
                            console.log('Cart block found:', cartBlock.length);
                            
                            // Try to find the best place to insert (prioritize checkout button area)
                            var insertTarget = null;
                            var insertMethod = 'after'; // Default insertion method
                            
                            if (checkoutButton.length > 0) {
                                insertTarget = checkoutButton;
                                console.log('Using checkout button block');
                            } else if (checkoutButtonAlt.length > 0) {
                                insertTarget = checkoutButtonAlt;
                                console.log('Using checkout button alt');
                            } else if (checkoutButtonGeneric.length > 0) {
                                insertTarget = checkoutButtonGeneric.last(); // Use the last button (likely checkout)
                                console.log('Using generic checkout button');
                            } else if (cartTotalsBlock.length > 0) {
                                insertTarget = cartTotalsBlock;
                                console.log('Using cart totals block as fallback');
                            } else if (cartBlock.length > 0) {
                                insertTarget = cartBlock;
                                insertMethod = 'append'; // Append to cart block if no better target
                                console.log('Using cart block as final fallback');
                            }
                            
                            if (insertTarget && $('#float-on-cart-blocks').length === 0) {
                                console.log('Adding Flexiown cart display...');
                                var flexiownHtml = '<div id="float-on-cart-blocks" style="color:black !important;margin-top:15px;padding:15px;border:1px solid #ddd;border-radius:5px;background:#f9f9f9;">' + 
                                    response.data.message + 
                                    '<strong class="flexiown-highlight" style="color: #ff003e !important;font-size: inherit;font-weight: inherit;">' + 
                                    response.data.price + ' per month</strong>, try it, love it, own it. <br/>' +
                                    'Apply with <img src="<?php echo $img; ?>" alt="Flexiown" class="flexi-cart-logo" style="width: 70px;vertical-align: baseline;"/> ' +
                                    '<a target="_blank" href="https://www.flexiown.co.za/">Learn more</a></div>';
                                
                                if (insertMethod === 'append') {
                                    insertTarget.append(flexiownHtml);
                                } else {
                                    insertTarget.after(flexiownHtml);
                                }
                                console.log('Flexiown cart display added successfully using method:', insertMethod);
                            } else {
                                console.log('Insert target not found or element already exists');
                            }
                        } else {
                            console.log('Flexiown cart display not shown:', response.data);
                        }
                    }).fail(function(xhr, status, error) {
                        console.error('AJAX failed:', status, error);
                    });
                } else {
                    console.log('Cart block conditions not met:', {
                        'cartBlock': $('.wp-block-woocommerce-cart').length,
                        'floatOnCart': $('#float-on-cart').length,
                        'floatOnCartBlocks': $('#float-on-cart-blocks').length
                    });
                }
            }
            
            // Try immediately
            initFlexiownCartDisplay();
            
            // Also try after a delay in case blocks are still loading
            setTimeout(function() {
                console.log('Timeout check - initialized?', flexiownCartInitialized);
                if (!flexiownCartInitialized) {
                    initFlexiownCartDisplay();
                }
            }, 2000);
        });
    </script>
    <?php
}

// AJAX handler for blocks cart info
function get_flexiown_cart_info_ajax() {
    global $woocommerce;
    $gateway = new WC_Gateway_Flexiown();
    $minPrice_cut = 0;
    $message = 'Or, From only ';
    $options = get_option('woocommerce_flexiown_settings', 'gets the option');
    
    if (isset($woocommerce->cart->cart_contents) && count($woocommerce->cart->cart_contents) >= 1) {
        $showFlexiown = true;
        $response = $gateway->api_bulk_product_lookup($woocommerce->cart->cart_contents);
        
        if (!$response || (isset($response->statusCode) && $response->statusCode == 500)) {
            wp_send_json_error();
            return;
        }

        foreach ($response as $item) {
            if ($item->accepted == false) {
                $showFlexiown = false;
            } else {
                if ($options['flexiown_cart_as_combined'] && $options['flexiown_cart_as_combined'] == 'yes') {
                    $minPrice_cut = ($minPrice_cut + $item->price);
                    $message = 'Or, From only ';
                } else {
                    if ($item->price < $minPrice_cut || $minPrice_cut == 0) {
                        $minPrice_cut = $item->price;
                    }
                }
            }
        }

        if ($showFlexiown) {
            wp_send_json_success(array(
                'show' => true,
                'message' => $message,
                'price' => wc_price($minPrice_cut)
            ));
        } else {
            wp_send_json_success(array('show' => false));
        }
    } else {
        wp_send_json_success(array('show' => false));
    }
}
add_action('wp_ajax_get_flexiown_cart_info', 'get_flexiown_cart_info_ajax');
add_action('wp_ajax_nopriv_get_flexiown_cart_info', 'get_flexiown_cart_info_ajax');

// AJAX handler for blocks cart warnings
function get_flexiown_cart_warnings_ajax() {
    $options = get_option('woocommerce_flexiown_settings', 'gets the option');
    if ($options['enable_cart_warnings'] != 'yes') {
        wp_send_json_success(array('has_warnings' => false));
        return;
    }
    
    $gateway = new WC_Gateway_Flexiown();
    $flexiown_items = array();
    
    // issue the cart to the api so we can get back a list of what is accepted and what is not
    $items = $gateway->api_bulk_product_lookup(WC()->cart->get_cart());
    //ex!$items || (isset($items->statusCode) && $items->statusCode == 500)
    if ($items->statusCode !== null && $items->statusCode == 500) {
        wp_send_json_error();
        return;
    }
    
    $position = 0;
    $rejected_keys = array();
    
    //this is a fallback for demonstration till the api is working again
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        // Check if the product is not a valid "flexiown" product
        if (!$items[$position]->accepted) {
            $flexiown_items[] = $cart_item_key;
            $rejected_keys[] = $cart_item_key;
            // also collect permalink for fallback matching in blocks
            $product_id = isset($cart_item['product_id']) ? $cart_item['product_id'] : ($cart_item['data'] && method_exists($cart_item['data'], 'get_id') ? $cart_item['data']->get_id() : 0);
            $rejected_map[$cart_item_key] = $product_id ? get_permalink($product_id) : '';
        }
        $position++;
    }
    
    wp_send_json_success(array(
        'has_warnings' => !empty($flexiown_items),
        'rejected_keys' => $rejected_keys,
        'rejected_map' => isset($rejected_map) ? $rejected_map : array()
    ));
}
add_action('wp_ajax_get_flexiown_cart_warnings', 'get_flexiown_cart_warnings_ajax');
add_action('wp_ajax_nopriv_get_flexiown_cart_warnings', 'get_flexiown_cart_warnings_ajax');



/**
 * Enable the product summary page widget.
 **/

// FUNCTION - Frontend show on single product page widget
function flexiown_widget_content()
{
    $flexiown_settings = get_option('woocommerce_flexiown_settings');
    if (isset($flexiown_settings['enable_product_widget']) && $flexiown_settings['enable_product_widget'] == 'yes') {
        echo woo_flexiown_frontend_widget();
    }
}

/**
 * Enable the product summary page widget as shortcode.
 **/
function flexiown_widget_shortcode_content()
{
    $flexiown_settings = get_option('woocommerce_flexiown_settings');
    if (isset($flexiown_settings['is_using_page_builder']) && $flexiown_settings['is_using_page_builder'] == 'yes') {
        echo woo_flexiown_frontend_widget();
    }
}

add_shortcode('flexiown_widget', 'flexiown_widget_shortcode_content');

function woo_flexiown_frontend_widget_legacy()
{
    $gateway = new WC_Gateway_Flexiown();
    global $product;
    if ( ! $product || ! method_exists( $product, 'get_id' ) ) {
        return '';
    }


    // TODO:: add or check for a flag against the product so we can skip this step if its already been checked
    try {
        $flexiown_price = $gateway->api_product_lookup($product, $product->get_id());
    } catch (Exception $e) {
        $flexiown_price = false;
    }
    if ($flexiown_price) {
        return '<div class="flexiown"><div id="flexiowntext"><img id="flexiownCalculatorWidgetLogo" width="100px" height="auto" src="' . FLEXIOWN_PLUGIN_URL . "assets/media/flexiown_logo.svg" . '"/></div><p class="flexiown-copy">Or, From only <b>R' . $flexiown_price->price . ',00 per month</b>, try it, love it, own it. Apply with Flexiown.
		<br><a target="_blank" href="https://www.flexiown.co.za/">Learn more</a></p></div>';
    } else {
        return '';
    }
}

function woo_flexiown_frontend_widget()
{
    $gateway = new WC_Gateway_Flexiown();
    global $product;
    if ( ! $product || ! method_exists( $product, 'get_id' ) ) {
        return '';
    }

    try {
        $flexiown_price = $gateway->api_product_lookup($product, $product->get_id());
    } catch (Exception $e) {
        $flexiown_price = false;
    }
    if ($flexiown_price) {
        return '
        <div class="flexiown">
            <div id="flexiowntext">
                <img id="flexiownCalculatorWidgetLogo" width="100px" height="auto" src="' . FLEXIOWN_PLUGIN_URL . 'assets/media/flexiown_logo.svg"/>
            </div>
            <p class="flexiown-copy">Or, From only <b>R' . $flexiown_price->price . ',00 per month</b>, try it, love it, own it. Apply with Flexiown.
            <br><a href="#" id="openModal">Learn more</a></p>
        </div>
        <div id="flexiownModal" class="modal">
            <div class="modal-content">
                <span id="closeModal">&times;</span>
                <iframe src="' . FLEXIOWN_PLUGIN_URL . "popup/index.html" . '"  frameborder="0" style="width:100%;height:100%;"></iframe>
            </div>
        </div>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                var modal = document.getElementById("flexiownModal");
                var btn = document.getElementById("openModal");
                var span = document.getElementById("closeModal");

                btn.onclick = function() {
                    modal.style.display = "block";
                }

                span.onclick = function() {
                    modal.style.display = "none";
                }

                window.onclick = function(event) {
                    if (event.target == modal) {
                        modal.style.display = "none";
                    }
                }
            });
        </script>
        <style>
            .modal {
                display: none;
                position: fixed;
                z-index: 1000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                overflow: auto;
                background-color: rgba(0,0,0,0.8);
            }

			@media screen and (min-width: 769px) {
            .modal-content {
				top: 3% !important;
				position: relative;
				width: 850px !important;
				height: 90vh !important;
				margin: 0 auto;
				background-color: #fff;
				border-radius: 4px;
				padding: 16px 0px;
			}
		}

            @media screen and (max-width: 768px) {
                .modal-content {
                    width: 100vw;
                    height: 100vh;
                }
            }

            #closeModal {
				position: absolute;
				top: 0px;
				right: 1px;
				cursor: pointer;
				font-size: 18px;
				background-color: #f0f0f0;
				padding: 0px 4px;
				color: black;
				font-weight: bold;
			}
        </style>';
    } else {
        return '';
    }
}


add_action('woocommerce_single_product_summary', 'flexiown_widget_content', 25);


/**
 * Loop over the cart and highlight any items not supported by flexiown.
 * also add a function to remove any items not supported by flexiown
 **/
function highlight_flexiown_items_in_cart()
{
    // Check if we are on the cart page
    $options = get_option('woocommerce_flexiown_settings', 'gets the option');
    if (is_cart() && $options['enable_cart_warnings'] == 'yes') {
        $gateway = new WC_Gateway_Flexiown();
        $flexiown_items = array();

        // issue the cart to the api so we can get back a list of what is accepted and what is not
        $items = $gateway->api_bulk_product_lookup(WC()->cart->get_cart());

        
        //exit if api fails
        if (!$items || (isset($items->statusCode) && $items->statusCode == 500)) {
            return false;
        }
        $position = 0;
        //this is a fallback for demonstration till the api is working again
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            // Check if the product is not a valid "flexiown" product
            if (!$items[$position]->accepted) {
                $cart_item['data']->update_meta_data('data-flexiown-item', 'rejected');
                $flexiown_items[] = $cart_item_key;
            }
            $position++;
        }
        // If there are flexiown items, display a notification at the top of the cart
        if (!empty($flexiown_items)) {
            // Store rejected keys for the JavaScript
            $rejected_keys_js = json_encode($flexiown_items);
            
            $notice_text = __('To allow Flexiown as a payment option, remove the items below highlighted in <span class="rejected_flexiown_item">grey</span><br> or click the "Checkout with Flexiown" button.', 'your-text-domain');
            $notice_text .= '<br><button id="flexiown-remove-rejected-btn-legacy" class="button remove-flexiown-items-button">' . __('Checkout with Flexiown', 'your-text-domain') . '</button>';
            // Print the notice without inline scripts. Attach the click handler in the footer so the script executes rather than being output as text.
            wc_print_notice($notice_text, 'notice');

            // Add footer script to bind click handler for legacy cart remove button. This prevents the JS from being injected into the notice body.
            add_action('wp_footer', function() use ($rejected_keys_js) {
                // Only output on cart page
                if (!is_cart()) return;
                ?>
                <script>
                jQuery(document).ready(function($) {
                    var rejectedKeys = <?php echo $rejected_keys_js; ?>;
                    $("#flexiown-remove-rejected-btn-legacy").off('click.flexiown').on("click.flexiown", function(e) {
                        e.preventDefault();
                        var button = $(this);
                        button.text("Removing items...").prop("disabled", true);

                        $.post("<?php echo admin_url('admin-ajax.php'); ?>", {
                            action: "remove_flexiown_rejected_items",
                            rejected_keys: rejectedKeys
                        }, function(response) {
                            if (response.success) {
                                button.text("Items removed! Refreshing...");
                                setTimeout(function() {
                                    window.location.reload();
                                }, 1000);
                            } else {
                                button.text("Error removing items").prop("disabled", false);
                                console.error("Failed to remove items:", response);
                            }
                        }).fail(function(xhr, status, error) {
                            button.text("Error removing items").prop("disabled", false);
                            console.error("AJAX failed:", status, error);
                        });
                    });
                });
                </script>
                <?php
            }, 99);
        }
    }
}

add_action('woocommerce_before_cart', 'highlight_flexiown_items_in_cart');

// Also add support for WooCommerce Blocks cart highlighting
add_action('wp_footer', 'highlight_flexiown_items_in_cart_blocks');

// Function specifically for blocks cart highlighting
function highlight_flexiown_items_in_cart_blocks() {
    // Only run on cart page
    if (!is_cart()) {
        return;
    }
    
    // Check if this is a blocks cart by looking for block elements
    global $post;
    if (!$post || !has_block('woocommerce/cart', $post)) {
        return; // Not a blocks cart
    }
    
    $options = get_option('woocommerce_flexiown_settings', 'gets the option');
    if ($options['enable_cart_warnings'] != 'yes') {
        return;
    }
    
    ?>
    <style>
        .rejected_flexiown_item_blocks {
            opacity: 0.5;
            background-color: #f5f5f5;
        }
    </style>
    <script>
        jQuery(document).ready(function($) {
            var flexiownHighlightInitialized = false;
            
            function initFlexiownCartHighlight() {
                // Prevent multiple initializations
                if (flexiownHighlightInitialized) {
                    return;
                }
                
                // Check if cart block exists and we haven't already added warnings
                if ($('.wp-block-woocommerce-cart').length > 0 && $('.wc-block-components-notice-banner.flexiown-warning').length === 0) {
                    flexiownHighlightInitialized = true;
                    
                    // Get cart warnings via AJAX
                    $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                        action: 'get_flexiown_cart_warnings'
                    }, function(response) {
                        if (response.success && response.data.has_warnings) {
                            // Show warning notice only if it doesn't exist
                            var cartBlock = $('.wp-block-woocommerce-cart');
                            if (cartBlock.length > 0 && $('.flexiown-warning').length === 0) {
                                var warningHtml = '<div class="wc-block-components-notice-banner is-warning flexiown-warning" style="margin-bottom: 15px;">' +
                                    '<div class="wc-block-components-notice-banner__content">' +
                                    'To allow Flexiown as a payment option, remove the items below highlighted in <span style="color: #666;">grey</span><br>' +
                                    'or click the "Checkout with Flexiown" button.<br>' +
                                    '<button id="flexiown-remove-rejected-btn" class="wc-block-components-button wp-element-button" style="margin-top: 10px;">Checkout with Flexiown</button>' +
                                    '</div></div>';
                                cartBlock.prepend(warningHtml);
                                
                                // Add click handler for the new button
                                $('#flexiown-remove-rejected-btn').on('click', function(e) {
                                    e.preventDefault();
                                    var button = $(this);
                                    button.text('Removing items...').prop('disabled', true);
                                    
                                    // Pass the rejected keys we already know from the warning check
                                    $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                                        action: 'remove_flexiown_rejected_items',
                                        rejected_keys: response.data.rejected_keys
                                    }, function(response) {
                                        if (response.success) {
                                            button.text('Items removed! Refreshing...');
                                            // Reload the page to show updated cart
                                            setTimeout(function() {
                                                window.location.reload();
                                            }, 1000);
                                        } else {
                                            button.text('Error removing items').prop('disabled', false);
                                            console.error('Failed to remove items:', response);
                                        }
                                    }).fail(function(xhr, status, error) {
                                        button.text('Error removing items').prop('disabled', false);
                                        console.error('AJAX failed:', status, error);
                                    });
                                });
                            }
                            
                            // Highlight rejected items
                            response.data.rejected_keys.forEach(function(cartKey) {
                                // Try to find cart item by data-key first
                                var $row = $('.wc-block-cart-item[data-key="' + cartKey + '"]');
                                if ($row.length) {
                                    $row.addClass('rejected_flexiown_item_blocks');
                                    return;
                                }

                                // Fallback: try permalink matching from rejected_map
                                var permalink = (response.data.rejected_map && response.data.rejected_map[cartKey]) ? response.data.rejected_map[cartKey] : '';
                                if (permalink) {
                                    // Exact href match
                                    var $a = $('a[href="' + permalink + '"]');
                                    if ($a.length) {
                                        $a.closest('tr, .wc-block-cart-items_row, .wc-block-cart-item').addClass('rejected_flexiown_item_blocks');
                                        return;
                                    }

                                    // Partial match (URL slug) as last resort
                                    try {
                                        var slug = permalink.replace(/(^.*\/)|([\/?].*$)/g, '');
                                        if (slug) {
                                            var $a2 = $('a[href*="' + slug + '"]');
                                            if ($a2.length) {
                                                $a2.closest('tr, .wc-block-cart-items_row, .wc-block-cart-item').addClass('rejected_flexiown_item_blocks');
                                                return;
                                            }
                                        }
                                    } catch (e) {
                                        // ignore
                                    }
                                }
                            });
                        }
                    });
                }
            }
            
            // Try immediately
            initFlexiownCartHighlight();
            
            // Also try after a delay in case blocks are still loading
            setTimeout(function() {
                if (!flexiownHighlightInitialized) {
                    initFlexiownCartHighlight();
                }
            }, 2000);
        });
    </script>
    <?php
}

// AJAX handler for removing rejected items from cart
function remove_flexiown_rejected_items_ajax() {
    // file_put_contents(WP_CONTENT_DIR . '/flexiown-debug.log', date('Y-m-d H:i:s') . ' - AJAX remove_flexiown_rejected_items_ajax called' . "\n", FILE_APPEND);
    
    // Debug what we received
    // file_put_contents(WP_CONTENT_DIR . '/flexiown-debug.log', date('Y-m-d H:i:s') . ' - POST data: ' . print_r($_POST, true) . "\n", FILE_APPEND);
    
    // Get the rejected keys from the request (passed from the warning system)
    $rejected_keys = isset($_POST['rejected_keys']) ? $_POST['rejected_keys'] : array();
    
    // Debug the rejected keys
    // file_put_contents(WP_CONTENT_DIR . '/flexiown-debug.log', date('Y-m-d H:i:s') . ' - Rejected keys received: ' . print_r($rejected_keys, true) . "\n", FILE_APPEND);
    // file_put_contents(WP_CONTENT_DIR . '/flexiown-debug.log', date('Y-m-d H:i:s') . ' - Rejected keys type: ' . gettype($rejected_keys) . "\n", FILE_APPEND);
    // file_put_contents(WP_CONTENT_DIR . '/flexiown-debug.log', date('Y-m-d H:i:s') . ' - Rejected keys count: ' . (is_array($rejected_keys) ? count($rejected_keys) : 'not array') . "\n", FILE_APPEND);
    
    if (empty($rejected_keys)) {
        // file_put_contents(WP_CONTENT_DIR . '/flexiown-debug.log', date('Y-m-d H:i:s') . ' - No rejected keys provided, doing fresh API call' . "\n", FILE_APPEND);
        
        // Fallback: Do the API call if no keys provided
        $gateway = new WC_Gateway_Flexiown();
        $items = $gateway->api_bulk_product_lookup(WC()->cart->get_cart());
        
        if (!$items || (isset($items->statusCode) && $items->statusCode == 500)) {
            // file_put_contents(WP_CONTENT_DIR . '/flexiown-debug.log', date('Y-m-d H:i:s') . ' - API call failed' . "\n", FILE_APPEND);
            wp_send_json_error('API call failed');
            return;
        }
        
        // Extract rejected keys from API response
        $position = 0;
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($items[$position]) && !$items[$position]->accepted) {
                $rejected_keys[] = $cart_item_key;
            }
            $position++;
        }
    }
    
    // file_put_contents(WP_CONTENT_DIR . '/flexiown-debug.log', date('Y-m-d H:i:s') . ' - Have ' . count($rejected_keys) . ' rejected keys to remove: ' . implode(', ', $rejected_keys) . "\n", FILE_APPEND);
    
    $removed_items = array();
    
    // Remove the rejected items
    foreach ($rejected_keys as $cart_item_key) {
        // file_put_contents(WP_CONTENT_DIR . '/flexiown-debug.log', date('Y-m-d H:i:s') . ' - Attempting to remove item: ' . $cart_item_key . "\n", FILE_APPEND);
        
        $removed = WC()->cart->remove_cart_item($cart_item_key);
        if ($removed) {
            $removed_items[] = $cart_item_key;
            // file_put_contents(WP_CONTENT_DIR . '/flexiown-debug.log', date('Y-m-d H:i:s') . ' - Successfully removed: ' . $cart_item_key . "\n", FILE_APPEND);
        } else {
            // file_put_contents(WP_CONTENT_DIR . '/flexiown-debug.log', date('Y-m-d H:i:s') . ' - Failed to remove: ' . $cart_item_key . "\n", FILE_APPEND);
        }
    }
    
    // Save the cart
    WC()->cart->set_session();
    
    // file_put_contents(WP_CONTENT_DIR . '/flexiown-debug.log', date('Y-m-d H:i:s') . ' - Cart saved. Removed ' . count($removed_items) . ' items' . "\n", FILE_APPEND);
    
    wp_send_json_success(array(
        'removed_count' => count($removed_items),
        'removed_items' => $removed_items,
        'final_cart_count' => count(WC()->cart->get_cart())
    ));
}
add_action('wp_ajax_remove_flexiown_rejected_items', 'remove_flexiown_rejected_items_ajax');
add_action('wp_ajax_nopriv_remove_flexiown_rejected_items', 'remove_flexiown_rejected_items_ajax');


//style the row

add_filter('woocommerce_cart_item_class', 'add_flexiown_item_class', 10, 3);
function add_flexiown_item_class($class, $cart_item, $cart_item_key)
{
    //$class = array();
    if ($cart_item['data']->get_meta('data-flexiown-item') === 'rejected') {
        $class = 'rejected_flexiown_item';
    }
    return $class;
}



function add_flexiown_style()
{
    wp_enqueue_style('flexiown-style', FLEXIOWN_PLUGIN_URL . 'assets/css/flexiown-style.css');
}
add_action('wp_enqueue_scripts', 'add_flexiown_style');

/**
 * Verify order status and offer paths to continue with process if required
 * in WC 3.0.
 *
 * @since 1.4.1
 *
 * @param WC_Order $order Order object.
 * @param string   $prop  Property name.
 *
 * @return mixed Property value
 */
function account_area_order_status_checks($actions, $order)
{
    // Get the order status from your payment processor
    //$my_order_status = account_area_order_status_checks( $order->get_id() );
    $gateway = new WC_Gateway_Flexiown();

    $flexiown_url = $order->get_meta('flexiown_redirect_url', true);

    // If the order has been processed by your payment processor, add a new button with a link to the status page

    if ($flexiown_url && $gateway->validate_transaction_status($order)->status == 'pending') {
        $actions['flexiown_status'] = array(
            'url'  => $flexiown_url,
            'name' => __('Flexiown Status', 'flexiown_status')
        );
    }

    return $actions;
}

add_filter('woocommerce_my_account_my_orders_actions', 'account_area_order_status_checks', 10, 2);
