<?php
/**
 * The buyer's locker choice on both WooCommerce checkouts.
 *
 * Classic (shortcode) checkout: the picker renders after the customer details
 * (woocommerce_checkout_after_customer_details) and posts four hidden fields;
 * woocommerce_checkout_process refuses the order on the gate,
 * woocommerce_checkout_create_order writes the pick on the order.
 *
 * Blocks checkout: the picker renders in the Shipping options section, under
 * the shipping rates, through a block integration script
 * (Livraria_Blocks_Integration, slot ExperimentalOrderShippingPackages)
 * and sends the pick as Store API extension data (namespace "livraria");
 * woocommerce_store_api_checkout_update_order_from_request refuses the order
 * on the gate and writes the pick.
 *
 * Shipping methods stay the merchant's, plus the plugin's own "Livrare la
 * locker" (Livraria_Locker_Shipping_Method): on that one the picker is a
 * modal with each courier's price (livraria_locker_rates) and every pick is
 * kept in the WC session (livraria_locker_select on the classic checkout,
 * the Store API update callback on the blocks one) so the rate follows it.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Livraria_Checkout {

    const SCRIPT = 'livraria-locker-picker';
    const STORE_NAMESPACE = 'livraria';

    /** Store API extension data and classic form fields share these keys */
    const FIELDS = array('delivery_mode', 'locker_id', 'locker_name', 'locker_courier');

    public function register() {
        add_action('woocommerce_checkout_after_customer_details', array($this, 'render_classic'));
        add_action('woocommerce_checkout_process', array($this, 'validate_classic'));
        add_action('woocommerce_checkout_create_order', array($this, 'save_classic'), 10, 2);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_classic'));
        add_action('woocommerce_blocks_loaded', array($this, 'register_blocks'));
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'save_blocks'), 10, 2);

        // "Livrare la locker"
        add_action('woocommerce_shipping_init', array(__CLASS__, 'load_shipping_method'));
        add_filter('woocommerce_shipping_methods', array(__CLASS__, 'add_shipping_method'));
        add_filter('woocommerce_cart_shipping_packages', array('Livraria_Locker_Pricing', 'tag_packages'));
        add_action('wp_ajax_livraria_locker_rates', array($this, 'ajax_locker_rates'));
        add_action('wp_ajax_nopriv_livraria_locker_rates', array($this, 'ajax_locker_rates'));
        add_action('wp_ajax_livraria_locker_select', array($this, 'ajax_locker_select'));
        add_action('wp_ajax_nopriv_livraria_locker_select', array($this, 'ajax_locker_select'));
    }

    // -------------------------------------------------------------------------
    // "Livrare la locker"
    // -------------------------------------------------------------------------

    public static function load_shipping_method() {
        if (!class_exists('Livraria_Locker_Shipping_Method') && class_exists('WC_Shipping_Method')) {
            require_once dirname(__FILE__) . '/class-locker-shipping-method.php';
        }
    }

    /**
     * @param array $methods
     * @return array
     */
    public static function add_shipping_method($methods) {
        $methods[Livraria_Locker_Pricing::METHOD_ID] = 'Livraria_Locker_Shipping_Method';
        return $methods;
    }

    /**
     * The modal's prices: what each courier's locker costs this cart in a
     * county and city, on the locker rate the buyer chose
     *
     * @param Livraria_API_Client $api_client
     * @param string $rate Chosen rate id, "livraria_locker:<instance>"
     * @param string $county
     * @param string $city
     * @return array prices: slug => {amount, label}; null when the rate is not a locker rate
     */
    public static function locker_prices($api_client, $rate, $county, $city) {
        $instance = Livraria_Locker_Pricing::instance_of($rate);
        self::load_shipping_method();
        if ($instance <= 0 || !class_exists('Livraria_Locker_Shipping_Method')) {
            return array('prices' => null);
        }
        $settings = (new Livraria_Locker_Shipping_Method($instance))->pricing_settings();
        if (function_exists('wc_load_cart') && function_exists('WC') && WC() && empty(WC()->cart)) {
            wc_load_cart();
        }
        $cart = function_exists('WC') && WC() && !empty(WC()->cart) ? WC()->cart : null;
        $live = null;
        if ($settings['price_mode'] === 'live') {
            $items = Livraria_Locker_Pricing::items_of($cart ? $cart->get_cart() : array());
            $live = Livraria_Locker_Pricing::live_for_cart($api_client, $items, array('county' => $county, 'city' => $city));
        }
        $subtotal = $cart ? (float) $cart->get_subtotal() : 0.0;
        $prices = array();
        foreach (Livraria_Locker_Pricing::buyer_prices($settings, Livraria_Lockers::checkout_couriers($api_client), $subtotal, $live) as $slug => $amount) {
            $prices[$slug] = array('amount' => $amount, 'label' => Livraria_Locker_Pricing::format($amount));
        }
        return array('prices' => $prices);
    }

    /**
     * AJAX (checkout, nonce livraria_checkout): locker_prices() for GET rate, county, city
     */
    public function ajax_locker_rates() {
        check_ajax_referer('livraria_checkout', 'nonce');
        $read = function($key) {
            return isset($_GET[$key]) && is_scalar($_GET[$key]) ? sanitize_text_field(wp_unslash((string) $_GET[$key])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above
        };
        wp_send_json_success(self::locker_prices(new Livraria_API_Client(), $read('rate'), $read('county'), $read('city')));
    }

    /**
     * AJAX (classic checkout, nonce livraria_checkout): keep the buyer's
     * locker for the price; the script then refreshes the checkout
     */
    public function ajax_locker_select() {
        check_ajax_referer('livraria_checkout', 'nonce');
        $input = array();
        foreach (self::FIELDS as $field) {
            $input[$field] = isset($_POST[$field]) ? wp_unslash($_POST[$field]) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above
        }
        wp_send_json_success(array('pick' => Livraria_Locker_Pricing::select(new Livraria_API_Client(), $input)));
    }

    // -------------------------------------------------------------------------
    // Classic checkout
    // -------------------------------------------------------------------------

    /**
     * The picker's container and the four fields it fills in
     */
    public function render_classic() {
        if (!Livraria_Lockers::picker_active()) {
            return;
        }
        // Shown only while the chosen rate is a ticked method; the script re-checks on updated_checkout
        $hidden = Livraria_Lockers::rate_offers_lockers(self::chosen_rate()) ? '' : ' hidden';
        echo '<div id="livraria-locker-picker" class="livraria-locker-picker" data-context="classic"' . $hidden . '></div>';
        foreach (self::FIELDS as $field) {
            echo '<input type="hidden" name="livraria_' . esc_attr($field) . '" id="livraria_' . esc_attr($field) . '" value="" />';
        }
    }

    /**
     * The pick posted by the classic checkout form
     *
     * @return array Livraria_Lockers::normalize_pick() result
     */
    public static function posted_pick() {
        $input = array();
        foreach (self::FIELDS as $field) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce before these hooks
            $input[$field] = isset($_POST['livraria_' . $field]) ? wp_unslash($_POST['livraria_' . $field]) : '';
        }
        return Livraria_Lockers::normalize_pick($input);
    }

    /**
     * The chosen shipping rate id: the checkout form's shipping_method[0],
     * else the session's chosen_shipping_methods[0]
     *
     * @return string
     */
    public static function chosen_rate() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce before these hooks
        if (isset($_POST['shipping_method']) && is_array($_POST['shipping_method'])) {
            $posted = reset($_POST['shipping_method']); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if (is_scalar($posted) && (string) $posted !== '') {
                return sanitize_text_field(wp_unslash((string) $posted));
            }
        }
        if (function_exists('WC') && WC() && isset(WC()->session) && WC()->session) {
            $chosen = WC()->session->get('chosen_shipping_methods');
            if (is_array($chosen) && !empty($chosen)) {
                return (string) reset($chosen);
            }
        }
        return '';
    }

    public function validate_classic() {
        $error = Livraria_Lockers::validate_pick(self::posted_pick(), self::chosen_rate());
        if ($error !== '' && function_exists('wc_add_notice')) {
            wc_add_notice($error, 'error');
        }
    }

    /**
     * @param WC_Order $order
     * @param array $data
     */
    public function save_classic($order, $data = array()) {
        if (Livraria_Lockers::rate_offers_lockers(self::chosen_rate())) {
            Livraria_Lockers::apply_pick($order, self::posted_pick());
        }
    }

    public function enqueue_classic() {
        if (!function_exists('is_checkout') || !is_checkout() || (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received'))) {
            return;
        }
        if (!Livraria_Lockers::picker_active()) {
            return; // no ticked shipping method: nothing on the checkout
        }
        self::register_picker_assets();
        wp_enqueue_style(self::SCRIPT);
        wp_enqueue_script('livraria-checkout-classic', self::asset_url('assets/checkout-lockers.js'), array('jquery', self::SCRIPT), LIVRARIA_WC_PLUGIN_VERSION, true);
        wp_localize_script(self::SCRIPT, 'livrariaLockerConfig', Livraria_Lockers::checkout_config());
    }

    /**
     * The picker widget (shared by both checkouts and the order panel)
     */
    public static function register_picker_assets() {
        wp_register_script(self::SCRIPT, self::asset_url('assets/locker-picker.js'), array(), LIVRARIA_WC_PLUGIN_VERSION, true);
        wp_register_style(self::SCRIPT, self::asset_url('assets/locker-picker.css'), array(), LIVRARIA_WC_PLUGIN_VERSION);
    }

    private static function asset_url($path) {
        return plugin_dir_url(dirname(dirname(__FILE__)) . '/courier-expedition-wp-plugin.php') . $path;
    }

    // -------------------------------------------------------------------------
    // Blocks checkout
    // -------------------------------------------------------------------------

    public function register_blocks() {
        if (function_exists('woocommerce_store_api_register_endpoint_data')) {
            woocommerce_store_api_register_endpoint_data(array(
                'endpoint' => 'checkout',
                'namespace' => self::STORE_NAMESPACE,
                'schema_callback' => array(__CLASS__, 'store_api_schema'),
                'schema_type' => ARRAY_A,
            ));
        }
        if (function_exists('woocommerce_store_api_register_update_callback')) {
            // extensionCartUpdate({ namespace: 'livraria', data: pick }): the rate follows the locker
            woocommerce_store_api_register_update_callback(array(
                'namespace' => self::STORE_NAMESPACE,
                'callback' => function($data) {
                    Livraria_Locker_Pricing::select(new Livraria_API_Client(), is_array($data) ? $data : array());
                },
            ));
        }
        if (interface_exists('\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface')) {
            require_once dirname(__FILE__) . '/class-blocks-integration.php';
            add_action('woocommerce_blocks_checkout_block_registration', function($registry) {
                $registry->register(new Livraria_Blocks_Integration());
            });
        }
    }

    /**
     * Store API: the extension data the blocks checkout may send
     *
     * @return array
     */
    public static function store_api_schema() {
        $schema = array();
        foreach (self::FIELDS as $field) {
            $schema[$field] = array(
                'description' => 'Livraria locker choice: ' . $field,
                'type' => array('string', 'null'),
                'context' => array('view', 'edit'),
                'readonly' => false,
            );
        }
        return $schema;
    }

    /**
     * Blocks checkout: refuse the order on the gate, else write the pick
     *
     * @param WC_Order $order
     * @param WP_REST_Request|array $request
     * @throws Exception RouteException (HTTP 400) when the pick blocks the order
     */
    public function save_blocks($order, $request) {
        $extensions = isset($request['extensions']) && is_array($request['extensions']) ? $request['extensions'] : array();
        $data = isset($extensions[self::STORE_NAMESPACE]) && is_array($extensions[self::STORE_NAMESPACE]) ? $extensions[self::STORE_NAMESPACE] : array();
        $pick = Livraria_Lockers::normalize_pick($data);
        $rate = self::chosen_rate();
        $error = Livraria_Lockers::validate_pick($pick, $rate);
        if ($error !== '') {
            if (class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('livraria_locker_required', $error, 400);
            }
            throw new Exception($error);
        }
        if (Livraria_Lockers::rate_offers_lockers($rate)) {
            Livraria_Lockers::apply_pick($order, $pick);
        }
    }
}
