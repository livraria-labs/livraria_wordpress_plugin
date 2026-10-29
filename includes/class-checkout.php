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
 * Shipping methods stay the merchant's: this class creates none.
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
