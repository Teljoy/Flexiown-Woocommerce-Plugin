<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;
use WC_Order;
use WP_REST_Request;
use WP_REST_Server;
use stdClass;

/**
 * Flexiown Blocks integration
 */
final class WC_Gateway_Flexiown_Blocks_Support extends AbstractPaymentMethodType {

    /**
     * Store API namespace used for extension data.
     */
    private const STORE_NAMESPACE = 'flexiown';

    /**
     * Key under the namespace that holds the checkout payload.
     */
    private const EXTENSION_DATA_KEY = 'application';

    /**
     * Session key for persisting user input between reloads.
     */
    private const SESSION_KEY = 'flexiown_extension_data';

    /**
     * Tracks whether Store API hooks have already been registered.
     */
    private static $store_api_registered = false;

    /**
     * Tracks whether REST API routes have been registered.
     */
    private static $rest_routes_registered = false;

    /**
     * The gateway instance.
     */
    private $gateway;

    /**
     * Payment method name/id/slug.
     */
    protected $name = 'flexiown';

    /**
     * Initializes the payment method type.
     */
    public function initialize() {
        $this->settings = get_option('woocommerce_flexiown_settings', []);
        $gateways       = WC()->payment_gateways->payment_gateways();
        $this->gateway  = $gateways[$this->name];

        $this->register_store_api_support();
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Helper to determine if onboarding fields are enabled.
     */
    private function onboarding_enabled() {
        return $this->gateway && method_exists($this->gateway, 'is_onboarding_enabled') && $this->gateway->is_onboarding_enabled();
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     */
    public function is_active() {
        return $this->gateway && $this->gateway->is_available();
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     */
    public function get_payment_method_script_handles() {
        $script_path       = '/assets/js/frontend/blocks.js';
        $script_asset_path = FLEXIOWN_PLUGIN_PATH . '/assets/js/frontend/blocks.asset.php';
        $script_asset      = file_exists($script_asset_path)
            ? require($script_asset_path)
            : array(
                'dependencies' => array(),
                'version'      => FLEXIOWN_VERSION
            );
        $script_url        = FLEXIOWN_PLUGIN_URL . $script_path;

        wp_register_script(
            'wc-flexiown-payments-blocks',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('wc-flexiown-payments-blocks', 'woocommerce-gateway-flexiown', FLEXIOWN_PLUGIN_PATH . '/languages/');
        }

        return ['wc-flexiown-payments-blocks'];
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     */
    public function get_payment_method_data() {
        $prefill = $this->get_extension_session_state();

        return [
            'title'       => $this->gateway ? $this->gateway->title : 'Flexiown',
            'description' => $this->gateway ? $this->gateway->description : 'Try It, Love It, Own It. You will be redirected to Flexiown to securely complete your payment.',
            'supports'    => $this->gateway ? $this->gateway->supports : ['products'],
            'namespace'   => self::STORE_NAMESPACE,
            'dataKey'     => self::EXTENSION_DATA_KEY,
            'prefill'     => $prefill,
            'restUrl'     => esc_url_raw(rest_url('flexiown/v1/blocks-data')),
            'options'     => [
                'debtReview'      => $this->format_select_options($this->gateway ? $this->gateway->get_debt_review_options() : []),
                'maritalStatus'   => $this->format_select_options($this->gateway ? $this->gateway->get_marital_status_options() : []),
                'kinRelationship' => $this->format_select_options($this->gateway ? $this->gateway->get_relationship_options() : []),
            ],
            'onboardingEnabled' => $this->onboarding_enabled(),
        ];
    }

    /**
     * Register Store API schema + callbacks when Blocks are available.
     */
    private function register_store_api_support() {
        if (self::$store_api_registered || ! $this->gateway) {
            return;
        }

        if (
            ! function_exists('woocommerce_store_api_register_endpoint_data') ||
            ! function_exists('woocommerce_store_api_register_update_callback')
        ) {
            return;
        }

        foreach ([CartSchema::IDENTIFIER, CheckoutSchema::IDENTIFIER] as $endpoint) {
            woocommerce_store_api_register_endpoint_data([
                'endpoint'        => $endpoint,
                'namespace'       => self::STORE_NAMESPACE,
                'schema_callback' => [$this, 'get_extension_schema'],
                'data_callback'   => [$this, 'get_extension_data'],
            ]);
        }

        woocommerce_store_api_register_update_callback([
            'namespace' => self::STORE_NAMESPACE,
            'callback'  => [$this, 'handle_extension_update'],
        ]);

        add_action(
            'woocommerce_store_api_checkout_update_order_from_request',
            [$this, 'persist_extension_data_to_order'],
            10,
            2
        );

        self::$store_api_registered = true;
    }

    /**
     * Schema definition for our extension data.
     */
    public function get_extension_schema() {
        $string_schema = function ($description = '') {
            return [
                'type'        => 'string',
                'description' => $description,
                'arg_options' => [
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ];
        };

        return [
            self::EXTENSION_DATA_KEY => [
                'description' => __('Flexiown optional checkout data.', 'flexiown'),
                'type'        => 'object',
                'properties'  => [
                    'salary'                    => $string_schema(__('Monthly salary, numbers only.', 'flexiown')),
                    'isUnderDebtReview'         => $string_schema(__('Debt review selection.', 'flexiown')),
                    'registrationDocumentNumber'=> $string_schema(__('Registration or ID number.', 'flexiown')),
                    'mobileNumber'              => $string_schema(__('Mobile number Flexiown can contact.', 'flexiown')),
                    'employerName'              => $string_schema(__('Employer name.', 'flexiown')),
                    'employerContact'           => $string_schema(__('Employer contact number.', 'flexiown')),
                    'maritalStatus'             => $string_schema(__('Marital status option.', 'flexiown')),
                    'nextOfKinName'             => $string_schema(__('Next of kin name.', 'flexiown')),
                    'nextOfKinContact'          => $string_schema(__('Next of kin contact number.', 'flexiown')),
                    'nextOfKinRelationship'     => $string_schema(__('Next of kin relationship option.', 'flexiown')),
                ],
            ],
        ];
    }

    /**
     * Provide initial data for the Store API payload.
     */
    public function get_extension_data() {
        if (! $this->onboarding_enabled()) {
            return [
                self::EXTENSION_DATA_KEY => $this->get_field_defaults(),
            ];
        }

        return [
            self::EXTENSION_DATA_KEY => $this->get_extension_session_state(),
        ];
    }

    /**
     * Handle cart/extensions updates to persist user input.
     */
    public function handle_extension_update($data) {
        if (! $this->onboarding_enabled()) {
            $this->set_extension_session_state($this->get_field_defaults());
            return;
        }

        if (empty($data[self::EXTENSION_DATA_KEY]) || ! is_array($data[self::EXTENSION_DATA_KEY])) {
            return;
        }

        $sanitized = $this->sanitize_extension_data($data[self::EXTENSION_DATA_KEY]);
        $this->set_extension_session_state($sanitized);

        if ($this->gateway && method_exists($this->gateway, 'flexiown_log')) {
            $this->gateway->flexiown_log(
                'Flexiown Blocks cart/extensions payload: ' . $this->stringify_for_log($sanitized),
                false
            );
        }
    }

    /**
     * Persist request data to the order meta when processing checkout.
     *
     * @param WC_Order        $order
     * @param WP_REST_Request $request
     */
    public function persist_extension_data_to_order($order, $request) {
        if (! $order instanceof WC_Order) {
            return;
        }

        if ($order->get_payment_method() !== $this->name) {
            return;
        }

        if (! $this->onboarding_enabled()) {
            return;
        }

        $extensions = $this->extract_extensions_payload($request);
        $payload    = [];

        if (! empty($extensions[self::STORE_NAMESPACE][self::EXTENSION_DATA_KEY])) {
            $payload = (array) $extensions[self::STORE_NAMESPACE][self::EXTENSION_DATA_KEY];
        } else {
            $payload = $this->get_extension_session_state();
        }

        if (! $this->has_extension_data($payload)) {
            return;
        }

        $sanitized = $this->sanitize_extension_data($payload);

        if ($this->gateway && method_exists($this->gateway, 'flexiown_log')) {
            $this->gateway->flexiown_log(
                'Flexiown Blocks checkout payload: ' . $this->stringify_for_log($sanitized),
                false
            );
        }

        $field_map = [
            '_flexiown_salary'                       => $sanitized['salary'],
            '_flexiown_registration_document_number' => $sanitized['registrationDocumentNumber'],
            '_flexiown_mobile_number'                => $sanitized['mobileNumber'],
            '_flexiown_employer_name'                => $sanitized['employerName'],
            '_flexiown_employer_contact'             => $sanitized['employerContact'],
            '_flexiown_next_of_kin_name'             => $sanitized['nextOfKinName'],
            '_flexiown_next_of_kin_contact'          => $sanitized['nextOfKinContact'],
            '_flexiown_marital_status'               => $sanitized['maritalStatus'],
            '_flexiown_relationship_type'            => $sanitized['nextOfKinRelationship'],
        ];

        foreach ($field_map as $meta_key => $value) {
            if ($value !== '') {
                $order->update_meta_data($meta_key, $value);
            } else {
                $order->delete_meta_data($meta_key);
            }
        }

        if (in_array($sanitized['isUnderDebtReview'], ['yes', 'no'], true)) {
            $order->update_meta_data('_flexiown_is_under_debt_review', $sanitized['isUnderDebtReview']);
        } else {
            $order->delete_meta_data('_flexiown_is_under_debt_review');
        }

        $this->set_extension_session_state($sanitized);
    }

    /**
     * Check whether at least one extension field contains a value.
     */
    private function has_extension_data($payload) {
        if (! $this->onboarding_enabled()) {
            return false;
        }

        if (! is_array($payload)) {
            return false;
        }

        foreach ($payload as $value) {
            if ($value !== '' && $value !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format associative arrays into value/label pairs for JS selects.
     */
    private function format_select_options($options) {
        $formatted = [];
        foreach ((array) $options as $value => $label) {
            $formatted[] = [
                'value' => (string) $value,
                'label' => $label,
            ];
        }

        return $formatted;
    }

    /**
     * Base structure for the optional fields.
     */
    private function get_field_defaults() {
        return [
            'salary'                    => '',
            'isUnderDebtReview'         => '',
            'registrationDocumentNumber'=> '',
            'mobileNumber'              => $this->get_default_mobile_number(),
            'employerName'              => '',
            'employerContact'           => '',
            'maritalStatus'             => '',
            'nextOfKinName'             => '',
            'nextOfKinContact'          => '',
            'nextOfKinRelationship'     => '',
        ];
    }

    private function get_default_mobile_number() {
        if ($this->gateway && method_exists($this->gateway, 'get_default_mobile_number')) {
            return $this->gateway->get_default_mobile_number();
        }

        return '';
    }

    /**
     * Fetch the persisted data from the WooCommerce session.
     */
    private function get_extension_session_state() {
        $defaults = $this->get_field_defaults();

        if (! $this->onboarding_enabled()) {
            return $defaults;
        }

        if (! function_exists('WC') || ! WC()->session) {
            return $defaults;
        }

        $stored = WC()->session->get(self::SESSION_KEY, []);
        if (! is_array($stored)) {
            return $defaults;
        }

        return array_merge($defaults, array_intersect_key($stored, $defaults));
    }

    /**
     * Save sanitized values back to the WooCommerce session.
     */
    private function set_extension_session_state($data) {
        if (! function_exists('WC') || ! WC()->session) {
            return;
        }

        $payload = $this->onboarding_enabled() ? $data : $this->get_field_defaults();

        WC()->session->set(self::SESSION_KEY, $payload);
    }

    /**
     * Normalize and sanitize incoming values from JS/Store API.
     */
    private function sanitize_extension_data($data) {
        $defaults = $this->get_field_defaults();
        if (! $this->onboarding_enabled()) {
            return $defaults;
        }

        $data     = is_array($data) ? array_merge($defaults, array_intersect_key($data, $defaults)) : $defaults;

        $data['salary'] = $this->sanitize_salary($data['salary']);
        $data['registrationDocumentNumber'] = $this->gateway && method_exists($this->gateway, 'sanitize_id_value')
            ? $this->gateway->sanitize_id_value($data['registrationDocumentNumber'])
            : sanitize_text_field($data['registrationDocumentNumber']);
        $data['employerName'] = sanitize_text_field($data['employerName']);
        $data['nextOfKinName'] = sanitize_text_field($data['nextOfKinName']);
        $data['mobileNumber'] = $this->gateway && method_exists($this->gateway, 'sanitize_phone_value')
            ? $this->gateway->sanitize_phone_value($data['mobileNumber'])
            : sanitize_text_field($data['mobileNumber']);

        $data['employerContact'] = $this->gateway && method_exists($this->gateway, 'sanitize_phone_value')
            ? $this->gateway->sanitize_phone_value($data['employerContact'])
            : sanitize_text_field($data['employerContact']);
        $data['nextOfKinContact'] = $this->gateway && method_exists($this->gateway, 'sanitize_phone_value')
            ? $this->gateway->sanitize_phone_value($data['nextOfKinContact'])
            : sanitize_text_field($data['nextOfKinContact']);

        $data['isUnderDebtReview'] = $this->sanitize_select_value($data['isUnderDebtReview'], $this->gateway ? $this->gateway->get_debt_review_options() : []);
        $data['maritalStatus'] = $this->sanitize_select_value($data['maritalStatus'], $this->gateway ? $this->gateway->get_marital_status_options() : []);
        $data['nextOfKinRelationship'] = $this->sanitize_select_value($data['nextOfKinRelationship'], $this->gateway ? $this->gateway->get_relationship_options() : []);

        return $data;
    }

    /**
     * Register custom REST endpoints used by the Blocks UI to persist data.
     */
    public function register_rest_routes() {
        if (self::$rest_routes_registered) {
            return;
        }

        register_rest_route(
            'flexiown/v1',
            '/blocks-data',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => '__return_true',
                'callback'            => [$this, 'handle_rest_extension_update'],
            ]
        );

        self::$rest_routes_registered = true;
    }

    /**
     * Handle REST payloads from the Blocks UI.
     */
    public function handle_rest_extension_update($request) {
        if (! $request instanceof WP_REST_Request) {
            return rest_ensure_response(['success' => false]);
        }

        if (! $this->onboarding_enabled()) {
            $this->set_extension_session_state($this->get_field_defaults());
            return rest_ensure_response([
                'success' => true,
            ]);
        }

        $raw      = $this->normalize_request_payload($request->get_param(self::EXTENSION_DATA_KEY));
        $sanitized = $this->sanitize_extension_data(is_array($raw) ? $raw : []);

        $this->set_extension_session_state($sanitized);

        if ($this->gateway && method_exists($this->gateway, 'flexiown_log')) {
            $this->gateway->flexiown_log(
                'Flexiown Blocks REST payload: ' . $this->stringify_for_log($sanitized),
                false
            );
        }

        return rest_ensure_response([
            'success' => true,
        ]);
    }

    /**
     * Extract the extensions payload from any available REST request source.
     */
    private function extract_extensions_payload($request) {
        if (! $request instanceof WP_REST_Request) {
            return [];
        }

        $candidates = [];
        $candidates[] = $request->get_param('extensions');

        $json_params = $request->get_json_params();
        if (is_array($json_params) && array_key_exists('extensions', $json_params)) {
            $candidates[] = $json_params['extensions'];
        }

        $body_params = $request->get_body_params();
        if (is_array($body_params) && array_key_exists('extensions', $body_params)) {
            $candidates[] = $body_params['extensions'];
        }

        foreach ($candidates as $candidate) {
            if (empty($candidate)) {
                continue;
            }

            $normalized = $this->normalize_request_payload($candidate);
            if (is_array($normalized) && ! empty($normalized)) {
                return $normalized;
            }
        }

        return [];
    }

    /**
     * Convert request payloads (which may be stdClass instances) into plain arrays recursively.
     */
    private function normalize_request_payload($value) {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->normalize_request_payload($child);
        }

        return $value;
    }

    /**
     * Helper wrapper for consistent debug logging when the gateway logger is enabled.
     */
    private function stringify_for_log($value) {
        if (is_array($value) || is_object($value)) {
            return print_r($value, true);
        }

        return (string) $value;
    }

    /**
     * Strip invalid characters from salary field.
     */
    private function sanitize_salary($value) {
        $value = preg_replace('/[^0-9.]/', '', (string) $value);
        return $value !== null ? $value : '';
    }

    /**
     * Ensure select values align with the configured options.
     */
    private function sanitize_select_value($value, $options) {
        $value = (string) $value;
        return array_key_exists($value, (array) $options) ? $value : '';
    }
}
