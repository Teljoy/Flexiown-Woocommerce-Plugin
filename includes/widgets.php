<?php


/**
 * GENERAL SERVICE WIDGETS AND HOOKS
 **/


$options = get_option('woocommerce_flexiown_settings', 'gets the option');

if (isset($options['enable_product_barcode'])) {

    if ('yes' === $options['enable_product_barcode']) {

        // function wk_custom_product_tab($default_tabs)
        // {
        //     $default_tabs['flexiown_settings'] = array(
        //         'label'   =>  __('Flexiown Settings', 'domain'),
        //         'target'  =>  'wk_flexiown_tab_data',
        //         'priority' => 60,
        //         'class'   => array()
        //     );
        //     return $default_tabs;
        // }

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

        add_action('woocommerce_process_product_meta', 'flexiown_save_fields', 10, 2);
        function flexiown_save_fields($id, $post)
        {
            if (!empty($_POST['flexiown_barcode'])) {
                update_post_meta($id, 'flexiown_barcode', $_POST['flexiown_barcode']);
            }
        }
    }
}


if (isset($options['disable_guest_order_persistence'])) {

    if ('yes' === $options['disable_guest_order_persistence']) {

        // first option
        add_filter('woocommerce_persistent_cart_enabled', '__return_false');

        // second option
        add_action('woocommerce_before_checkout_form', function () {
            WC()->session->__unset('order_awaiting_payment'); // or set(null)
        });

        //third option
        add_action('woocommerce_cart_updated', 'wc_delete_persistent_cart');

        function wc_delete_persistent_cart()
        {
            if (!get_current_user_id()) {  //only need to do this when a user is logged in
                $wc = WooCommerce::instance();
                $wc->cart->persistent_cart_destroy();
            }
        };
    }
}
