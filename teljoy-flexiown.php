<?php
/*
 * Plugin Name: Flexiown Woocommerce Payment Gateway
 * Description: Use Flexiown as a payment processor for WooCommerce.
 * Plugin URI: https://flexiown.co.za/
 * Author URI: https://flexiown.co.za/
 * Version: 2.1.11
 * Author: Flexiown
 * Requires at least: 4.4
 * Tested up to: 6.8.3
 * WC tested up to: 10.1.1
 * WC requires at least: 8.0
*/

/**
 * Check if WooCommerce is activated
 */

defined('ABSPATH') || exit;

define('FLEXIOWN_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FLEXIOWN_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FLEXIOWN_VERSION', '2.1.11');
define('FLEXIOWN_DB_VERSION', '1.0.0');
define('FLEXIOWN_MIN_PHP_VERSION', '7.4');
define('FLEXIOWN_MIN_WP_VERSION', '5.0');
define('FLEXIOWN_MIN_WC_VERSION', '8.0');

add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

class FLEXIOWN_PLUGIN
{

    /**
     * Plugin instance
     * 
     * @var FLEXIOWN_PLUGIN
     */
    private static $instance = null;

    public function __construct()
    {

        // Check requirements before initialization
        if (!$this->check_requirements()) {
            return;
        }
    }

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }


    /**
     * Check plugin requirements
     * 
     * @return bool
     */
    private function check_requirements()
    {
        global $wp_version;

        // Check PHP version
        if (version_compare(PHP_VERSION, FLEXIOWN_MIN_PHP_VERSION, '<')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>';
                printf(
                    __('FlexiOwn requires PHP version %s or higher. You are running version %s.', 'flexiown'),
                    FLEXIOWN_MIN_PHP_VERSION,
                    PHP_VERSION
                );
                echo '</p></div>';
            });
            return false;
        }

        // Check WordPress version
        if (version_compare($wp_version, FLEXIOWN_MIN_WP_VERSION, '<')) {
            add_action('admin_notices', function () use ($wp_version) {
                echo '<div class="notice notice-error"><p>';
                printf(
                    __('FlexiOwn requires WordPress version %s or higher. You are running version %s.', 'flexiown'),
                    FLEXIOWN_MIN_WP_VERSION,
                    $wp_version
                );
                echo '</p></div>';
            });
            return false;
        }

        // Check if WooCommerce is loaded
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>';
                _e('FlexiOwn requires WooCommerce to be installed and activated.', 'flexiown');
                echo '</p></div>';
            });
            return false;
        }

        // initialize the plugin
        $this->initialize_plugin();
        return true;
    }

    private function initialize_plugin()
    {
        if (!class_exists('WC_Payment_Gateway')) return;

        load_plugin_textdomain('woocommerce-flexiown-teljoy', false, trailingslashit(dirname(plugin_basename(__FILE__))));
        // Include required files
        require_once FLEXIOWN_PLUGIN_PATH . 'includes/admin/settings.php';
        require_once FLEXIOWN_PLUGIN_PATH . 'includes/class-store-selector.php';
        require_once FLEXIOWN_PLUGIN_PATH . 'includes/util.php';
        require_once FLEXIOWN_PLUGIN_PATH . 'includes/widgets.php';
        require_once FLEXIOWN_PLUGIN_PATH . 'includes/agent.php';
        require_once FLEXIOWN_PLUGIN_PATH . 'includes/class-flexiown-blocks-support.php';
        require_once FLEXIOWN_PLUGIN_PATH . 'includes/webhooks.php';
        require_once FLEXIOWN_PLUGIN_PATH . 'includes/class-flexiown-elementor.php';

        // Initialize the payment gateway
        add_filter('woocommerce_payment_gateways', function ($gateways) {
            $gateways[] = 'WC_Gateway_Flexiown';
            return $gateways;
        });

        // Register Blocks support if WooCommerce Blocks is active
        add_action('woocommerce_blocks_loaded', function() {
            error_log('Flexiown: woocommerce_blocks_loaded action fired');
            
            // Register the blocks integration
            add_action('woocommerce_blocks_payment_method_type_registration', function($payment_method_registry) {
                error_log('Flexiown: woocommerce_blocks_payment_method_type_registration action fired');
                
                // Include the blocks support class
                if (!class_exists('WC_Gateway_Flexiown_Blocks_Support')) {
                    require_once FLEXIOWN_PLUGIN_PATH . 'includes/class-flexiown-blocks-support.php';
                }
                
                $payment_method_registry->register(new WC_Gateway_Flexiown_Blocks_Support());
                error_log('Flexiown: Blocks support registered');
            });
        });
    }
}

function flexiown_init()
{
    // Initialization code here
    return FLEXIOWN_PLUGIN::get_instance();
}

//start plugin
add_action('plugins_loaded', 'flexiown_init', 0);


// Add settings link on plugin page
function flexiown_gateway_plugin_links($links)
{
    $settings_url = add_query_arg(
        array(
            'page' => 'wc-settings',
            'tab' => 'checkout',
            'section' => 'flexiown',
        ),
        admin_url('admin.php')
    );

    $plugin_links = array(
        '<a href="' . esc_url($settings_url) . '">Settings</a>',
        '<a href="https://flexiown.co.za/support" target="_blank">Support</a>',
        '<a href="https://docs.flexiown.co.za" target="_blank">Docs</a>',
    );

    return array_merge($plugin_links, $links);
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'flexiown_gateway_plugin_links');


// PLUGIN UPDATE CHECKER
require FLEXIOWN_PLUGIN_PATH . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/Teljoy/Fleixiown-Woocommerce-Plugin/raw/refs/heads/main/info.json',
	__FILE__, //Full path to the main plugin file or functions.php.
	'woocommerce-flexiown-teljoy'
);
