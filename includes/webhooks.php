<?php

/**
 * Register a custom REST API endpoint to retrieve logs and handle responses from api
 * URL: /wp-json/flexiown/v1/logbook
 * Method: GET
 * Purpose: Retrieve latest logs
 */
//ENDPOINTS
function flexiown_register_stores_endpoint()
{
    register_rest_route('fo/v1', '/stores', array(
        'methods' => 'GET',
        'callback' => 'flexiown_get_stores',
        'permission_callback' => '__return_true', // Allow public access for demonstration
        'args' => array(
            'per_page' => array(
                'description' => 'Number of stores to retrieve',
                'type' => 'integer',
                'default' => 10,
                'minimum' => 1,
                'maximum' => 100,
                'validate_callback' => function ($param, $request, $key) {
                    return is_numeric($param) && $param > 0 && $param <= 100;
                },
                'sanitize_callback' => 'absint'
            ),
            'page' => array(
                'description' => 'Page number for pagination',
                'type' => 'integer',
                'default' => 1,
                'minimum' => 1,
                'validate_callback' => function ($param, $request, $key) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint'
            ),
            'search' => array(
                'description' => 'Search term to filter books',
                'type' => 'string',
                'validate_callback' => function ($param, $request, $key) {
                    return is_string($param);
                },
                'sanitize_callback' => 'sanitize_text_field'
            ),
        )
    ));
}
add_action('rest_api_init', 'flexiown_register_stores_endpoint');

function flexiown_register_logbook_webhook_endpoint()
{
    register_rest_route('fo/v1', '/logbook', array(
        'methods' => 'GET',
        'callback' => 'flexiown_fetch_logbook',
        'permission_callback' => 'flexiown_webhook_permissions',
        'args' => array()
    ));
}
add_action('rest_api_init', 'flexiown_register_logbook_webhook_endpoint');

function flexiown_register_orders_endpoint()
{
    register_rest_route('fo/v1', '/orders', array(
        'methods' => 'GET',
        'callback' => 'flexiown_get_orders',
        'permission_callback' => 'flexiown_webhook_permissions',
        'args' => array(
            'status' => array(
                'description' => 'Filter orders by status',
                'type' => 'string',
                'default' => '',
                'validate_callback' => function ($param, $request, $key) {
                    // Valid WooCommerce order statuses:
                    // pending, processing, on-hold, completed, cancelled, refunded, failed
                    $valid_statuses = array('pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed');
                    return empty($param) || in_array($param, $valid_statuses);
                },
                'sanitize_callback' => 'sanitize_text_field'
            ),
            'per_page' => array(
                'description' => 'Number of orders to retrieve',
                'type' => 'integer',
                'default' => 10,
                'minimum' => 1,
                'maximum' => 100,
                'validate_callback' => function ($param, $request, $key) {
                    return is_numeric($param) && $param > 0 && $param <= 100;
                },
                'sanitize_callback' => 'absint'
            ),
            'page' => array(
                'description' => 'Page number for pagination',
                'type' => 'integer',
                'default' => 1,
                'minimum' => 1,
                'validate_callback' => function ($param, $request, $key) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint'
            ),
        )
    ));
}
add_action('rest_api_init', 'flexiown_register_orders_endpoint');


// CALLBACKS


function flexiown_get_stores(WP_REST_Request $request)
{
    // Extract parameters from request
    $per_page = $request->get_param('per_page');
    $page = $request->get_param('page');
    $search = $request->get_param('search');

    // Build query arguments
    $args = array(
        'post_type' => 'store_location',
        'posts_per_page' => $per_page,
        'paged' => $page,
        'post_status' => 'publish'
    );

    // Add search functionality
    if (!empty($search)) {
        $args['s'] = $search;
    }


    // Execute the query
    $store_query = new WP_Query($args);
    $stores = $store_query->posts;

    // Handle empty results
    if (empty($stores)) {
        return new WP_Error('no_store_locations', 'No store locations found', array('status' => 404));
    }

    // Format the response data
    $data = array();
    foreach ($stores as $store) {
        // Get custom meta fields

        $data[] = array(
            'id' => $store->ID,
            'title' => $store->post_title,
            'content' => apply_filters('the_content', $store->post_content),
            'date' => $store->post_date,
            'modified' => $store->post_modified,
            'status' => $store->post_status,
            'permalink' => get_permalink($store->ID)
        );
    }

    // Build response with pagination info
    $response = rest_ensure_response($data);

    // Add pagination headers
    $response->header('X-WP-Total', $store_query->found_posts);
    $response->header('X-WP-TotalPages', $store_query->max_num_pages);

    return $response;
}

function flexiown_get_orders(WP_REST_Request $request)
{
    // Extract parameters from request
    $status = $request->get_param('status');
    $per_page = $request->get_param('per_page');
    $page = $request->get_param('page');

    // Build query arguments
    $args = array(
        'limit' => $per_page,
        'page' => $page,
        'orderby' => 'date',
        'order' => 'DESC',
        'payment_method' => 'flexiown', // Only return orders paid with Flexiown
    );

    // Add status filter if provided
    // Valid statuses: pending, processing, on-hold, completed, cancelled, refunded, failed
    if (!empty($status)) {
        $args['status'] = 'wc-' . $status; // WooCommerce prefixes statuses with 'wc-'
    }

    // Get orders using WooCommerce function
    $orders = wc_get_orders($args);

    // Handle empty results
    if (empty($orders)) {
        return new WP_Error('no_orders', 'No Flexiown orders found', array('status' => 404));
    }

    // Format the response data
    $data = array();
    foreach ($orders as $order) {
        $order_data = array(
            'id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'status' => $order->get_status(),
            'currency' => $order->get_currency(),
            'total' => $order->get_total(),
            'subtotal' => $order->get_subtotal(),
            'tax_total' => $order->get_total_tax(),
            'shipping_total' => $order->get_shipping_total(),
            'payment_method' => $order->get_payment_method(),
            'payment_method_title' => $order->get_payment_method_title(),
            'date_created' => $order->get_date_created()->date('Y-m-d H:i:s'),
            'date_modified' => $order->get_date_modified()->date('Y-m-d H:i:s'),
            'customer_id' => $order->get_customer_id(),
            'billing' => array(
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
                'address_1' => $order->get_billing_address_1(),
                'address_2' => $order->get_billing_address_2(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'postcode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
            ),
            'shipping' => array(
                'first_name' => $order->get_shipping_first_name(),
                'last_name' => $order->get_shipping_last_name(),
                'address_1' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
                'city' => $order->get_shipping_city(),
                'state' => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'country' => $order->get_shipping_country(),
            ),
            'flexiown_custom_fields' => array(
                'flexiown_redirect_url' => $order->get_meta('flexiown_redirect_url', true),
                'flexiown_store_location' => $order->get_meta('flexiown_store_location', true),
                'flexiown_transaction_id' => $order->get_meta('flexiown_transaction_id', true),
                'flexiown_trust_seed' => $order->get_meta('flexiown_trust_seed', true),
                'is_vat_exempt' => $order->get_meta('is_vat_exempt', true),
            ),
            'line_items' => array()
        );

        // Add line items
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $order_data['line_items'][] = array(
                'id' => $item_id,
                'name' => $item->get_name(),
                'product_id' => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'quantity' => $item->get_quantity(),
                'subtotal' => $item->get_subtotal(),
                'total' => $item->get_total(),
                'sku' => $product ? $product->get_sku() : '',
            );
        }

        $data[] = $order_data;
    }

    // Build response
    $response = rest_ensure_response($data);

    // Add pagination info (approximate since wc_get_orders doesn't return total count easily)
    $response->header('X-WP-Total-Returned', count($orders));

    return $response;
}

function flexiown_fetch_logbook(WP_REST_Request $request)
{
    // Get timestamp parameter (optional)
    $timestamp = $request->get_param('timestamp');
    
    // CALL THE FUNCTION TO GET LOG FILES
    $log_files = get_log_files();
    if (empty($log_files)) {
        return new WP_Error('no_logs', 'No log files found', array('status' => 404));
    }
    // We'll parse all matching files (files with "flexiown" in the filename) and
    // combine their entries. Files returned by get_log_files() are sorted oldest->newest.
    $all_entries = array();

    foreach ($log_files as $file_path) {
        if (!file_exists($file_path)) continue;

        $log_contents = file_get_contents($file_path);
        if ($log_contents === false) continue;

        $lines = preg_split('/\r?\n/', $log_contents);

        // Group multiline entries: lines starting with timestamp begin a new entry
        $groups = array();
        $current = null;
        foreach ($lines as $line) {
            if ($line === null) continue;
            $trim = trim($line);
            if ($trim === '') continue;

            if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+(\w+)\s+(.*)$/', $line, $m)) {
                // start a new group
                if ($current !== null) $groups[] = $current;
                $current = array(
                    'date' => $m[1],
                    'level' => $m[2],
                    'message' => $m[3],
                );
            } else {
                // continuation line: append to previous message or create a fallback entry
                if ($current === null) {
                    $current = array(
                        'date' => '',
                        'level' => 'UNKNOWN',
                        'message' => $line,
                    );
                } else {
                    $current['message'] .= '\n' . $line;
                }
            }
        }
        if ($current !== null) $groups[] = $current;

        // Normalize and attempt to decode JSON fragments in messages
        foreach ($groups as $entry) {
            // If timestamp filter is provided, check it
            if ($timestamp && !empty($entry['date'])) {
                $log_date = strtotime($entry['date']);
                if ($log_date < strtotime($timestamp)) {
                    continue; // Skip logs older than timestamp
                }
            }

            $msg = trim($entry['message']);

            // If message is a quoted string like "..." or contains escaped JSON, try to json_decode
            $decoded = json_decode($msg, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // Use decoded value (could be string, array, object)
                $msg_val = $decoded;
            } else {
                // Try stripping enclosing quotes and unescaping
                if (preg_match('/^"(.*)"$/s', $msg, $m2)) {
                    $stripped = stripcslashes($m2[1]);
                    $decoded2 = json_decode($stripped, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $msg_val = $decoded2;
                    } else {
                        $msg_val = $stripped;
                    }
                } else {
                    $msg_val = $msg;
                }
            }

            // If date is empty, fall back to file modification time
            $date_val = $entry['date'];
            if (empty($date_val)) {
                $date_val = date('Y-m-d H:i:s', filemtime($file_path));
            }

            $all_entries[] = array(
                'date' => $date_val,
                'level' => !empty($entry['level']) ? $entry['level'] : 'UNKNOWN',
                'message' => $msg_val,
                'source_file' => basename($file_path),
            );
        }
    }

    // Sort by date desc (newest first). If date parsing fails, treat as oldest.
    usort($all_entries, function ($a, $b) {
        $ta = strtotime($a['date']) ?: 0;
        $tb = strtotime($b['date']) ?: 0;
        return $tb <=> $ta;
    });

    return rest_ensure_response(array_values($all_entries));
}


function get_log_files()
{
    $log_files = array();

    // Get the log directory from WooCommerce
    $upload_dir = wp_upload_dir();
    $log_dir = trailingslashit($upload_dir['basedir']) . 'wc-logs/';

    // Alternative method if above doesn't work
    if (!is_dir($log_dir)) {
        if (defined('WC_LOG_DIR')) {
            $log_dir = trailingslashit(WC_LOG_DIR);
        }
    }

    if (!is_dir($log_dir)) return array();

    // Get all flexiown log files in the directory
    $files = glob($log_dir . '*flexiown*.log');
    if (empty($files)) return array();

    // Sort files by modification time (oldest -> newest)
    usort($files, function ($a, $b) {
        return filemtime($a) <=> filemtime($b);
    });

    // Return full paths (index-based array)
    return $files;
}


//HEADERS


function flexiown_webhook_permissions(WP_REST_Request $request)
{
    $options = get_option('woocommerce_flexiown_settings', 'gets the option');
    $api_key = $request->get_header('X-API-Key');
    if (isset($options['merchant_api_key']) && $options['merchant_api_key'] === $api_key) {
        return true;
    } else {
        // return true;
        // enable after demo
        return new WP_Error('forbidden', 'API key not configured or invalid', array('status' => 403));
    }
}
