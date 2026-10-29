<?php
/**
 * 3b: the buyer's locker choice on the classic and the blocks checkout:
 * what rides on the order, the gate, the settings.
 */

require_once dirname(__DIR__) . '/QuoteFlowTestCase.php';
require_once dirname(__DIR__) . '/stubs/BlocksIntegration.php';

class Checkout_Lockers_Test extends Livraria_Quote_Flow_TestCase {

    private $checkout;

    public function setUp(): void {
        parent::setUp();
        $this->checkout = new Livraria_Checkout();
        $GLOBALS['wp_test_notices'] = array();
        $_POST = array();
        // The merchant ticked flat_rate:3 for lockers and the buyer chose it
        update_option(Livraria_Lockers::OPT_METHODS, array('flat_rate:3'));
        $GLOBALS['wp_test_wc_session'] = array('chosen_shipping_methods' => array('flat_rate:3'));
    }

    public function tearDown(): void {
        $_POST = array();
        unset($GLOBALS['wp_test_wc_session'], $GLOBALS['wp_test_is_checkout'], $GLOBALS['wp_test_enqueued']);
        parent::tearDown();
    }

    private function post_pick($mode, $id = '', $name = '', $courier = '') {
        $_POST = array('livraria_delivery_mode' => $mode, 'livraria_locker_id' => $id, 'livraria_locker_name' => $name, 'livraria_locker_courier' => $courier);
    }

    private function classic_order($mode, $id = '', $name = '', $courier = '', $order = null) {
        $this->post_pick($mode, $id, $name, $courier);
        $GLOBALS['wp_test_notices'] = array();
        $this->checkout->validate_classic();
        $order = $order ?: new Livraria_Fake_Order(1301);
        if (empty($GLOBALS['wp_test_notices'])) {
            $this->checkout->save_classic($order, array());
        }
        return $order;
    }

    private function blocks_order($data, $order = null) {
        $order = $order ?: new Livraria_Fake_Order(1302);
        $this->checkout->save_blocks($order, array('extensions' => array('livraria' => $data)));
        return $order;
    }

    private function pick_meta($order) {
        $meta = array();
        foreach (array(Livraria_Lockers::META_MODE, Livraria_Lockers::META_ID, Livraria_Lockers::META_NAME, Livraria_Lockers::META_COURIER) as $key) {
            $meta[$key] = isset($order->meta[$key]) ? $order->meta[$key] : null;
        }
        return $meta;
    }

    public function test_S1_storefront_pick_per_courier_rides_on_the_order_from_both_checkouts() {
        $sameday = $this->classic_order('locker', 'SD-1001', 'easybox Exemplu Centru', 'sameday');
        $this->assertSame(array('_livraria_delivery_mode' => 'locker', '_livraria_locker_id' => 'SD-1001', '_livraria_locker_name' => 'easybox Exemplu Centru', '_livraria_locker_courier' => 'sameday'), $this->pick_meta($sameday));
        $this->assertSame(0, $sameday->saves, 'the checkout saves the order, not the plugin');

        $dpd = $this->blocks_order(array('delivery_mode' => 'locker', 'locker_id' => 'DPD-2001', 'locker_name' => 'DPD Locker Exemplu Mall', 'locker_courier' => 'dpd'));
        $this->assertSame(array('_livraria_delivery_mode' => 'locker', '_livraria_locker_id' => 'DPD-2001', '_livraria_locker_name' => 'DPD Locker Exemplu Mall', '_livraria_locker_courier' => 'dpd'), $this->pick_meta($dpd));
    }

    public function test_S2_switching_courier_keeps_the_new_lockers_own_courier() {
        $order = $this->classic_order('locker', 'SD-1001', 'easybox Exemplu Centru', 'sameday');
        $this->classic_order('locker', 'DPD-2001', 'DPD Locker Exemplu Mall', 'DPD', $order);
        $this->assertSame('DPD-2001', $order->meta['_livraria_locker_id']);
        $this->assertSame('dpd', $order->meta['_livraria_locker_courier']);
        $this->assertSame('DPD Locker Exemplu Mall', $order->meta['_livraria_locker_name']);
    }

    public function test_S3_back_to_home_clears_the_locker() {
        $order = $this->blocks_order(array('delivery_mode' => 'locker', 'locker_id' => 'SD-1001', 'locker_name' => 'x', 'locker_courier' => 'sameday'));
        $this->blocks_order(array('delivery_mode' => 'home', 'locker_id' => 'SD-1001', 'locker_courier' => 'sameday'), $order);
        $this->assertSame(array('_livraria_delivery_mode' => 'home', '_livraria_locker_id' => null, '_livraria_locker_name' => null, '_livraria_locker_courier' => null), $this->pick_meta($order));
    }

    public function test_S4_the_gate_locker_mode_without_a_locker_blocks_both_checkouts() {
        $order = $this->classic_order('locker');
        $this->assertSame(array(array('message' => Livraria_Lockers::MSG_GATE, 'type' => 'error')), $GLOBALS['wp_test_notices']);
        $this->assertSame(array(), $order->meta);

        try {
            $this->blocks_order(array('delivery_mode' => 'locker', 'locker_id' => ''));
            $this->fail('the blocks checkout must refuse the order');
        } catch (Exception $e) {
            $this->assertSame(Livraria_Lockers::MSG_GATE, $e->getMessage());
        }
    }

    public function test_S4_require_locker_only_takes_home_delivery_away() {
        $this->classic_order('home');
        $this->assertSame(array(), $GLOBALS['wp_test_notices'], 'without the setting home delivery passes');

        update_option(Livraria_Lockers::OPT_REQUIRE_LOCKER, '1');
        $this->classic_order('home');
        $this->assertSame(Livraria_Lockers::MSG_HOME_OFF, $GLOBALS['wp_test_notices'][0]['message']);
        $this->classic_order('locker');
        $this->assertSame(Livraria_Lockers::MSG_GATE, $GLOBALS['wp_test_notices'][0]['message']);
        $order = $this->classic_order('locker', 'SD-1001', 'easybox Exemplu Centru', 'sameday');
        $this->assertSame('SD-1001', $order->meta['_livraria_locker_id']);
    }

    public function test_S5_a_courier_disabled_in_settings_leaves_the_list_and_cannot_be_picked() {
        update_option('livraria_lockers_sameday', '0');

        $this->assertSame(array('dpd'), Livraria_Lockers::enabled_couriers());
        $this->assertSame(array(array('slug' => 'dpd', 'label' => 'locker DPD')), Livraria_Lockers::checkout_config()['couriers']);
        $this->classic_order('locker', 'SD-1001', 'easybox Exemplu Centru', 'sameday');
        $this->assertSame(Livraria_Lockers::MSG_LOCKER_GONE, $GLOBALS['wp_test_notices'][0]['message']);

        update_option('livraria_lockers_dpd', '0');
        ob_start();
        $this->checkout->render_classic();
        $this->assertSame('', ob_get_clean(), 'no courier on: no picker, nothing to validate');
        $this->classic_order('locker');
        $this->assertSame(array(), $GLOBALS['wp_test_notices']);
    }

    public function test_both_couriers_are_on_by_default_and_the_copy_is_romanian() {
        $config = Livraria_Lockers::checkout_config();
        $this->assertSame(array('sameday', 'dpd'), array_column($config['couriers'], 'slug'));
        $this->assertSame('easybox Sameday', $config['couriers'][0]['label']);
        $this->assertSame('Livrare la locker', $config['strings']['modeLocker']);
        $this->assertSame('Schimbă', $config['strings']['change']);
        $this->assertFalse($config['requireLocker']);
    }

    public function test_classic_render_posts_four_fields() {
        ob_start();
        $this->checkout->render_classic();
        $html = ob_get_clean();
        foreach (array('livraria_delivery_mode', 'livraria_locker_id', 'livraria_locker_name', 'livraria_locker_courier') as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $html);
        }
        $this->assertStringContainsString('id="livraria-locker-picker"', $html);
    }

    public function test_checkout_hooks_are_registered() {
        global $wp_test_actions;
        $saved = $wp_test_actions;
        $wp_test_actions = array();
        $this->checkout->register();
        $hooks = array_keys($wp_test_actions);
        $wp_test_actions = $saved;
        foreach (array('woocommerce_checkout_after_customer_details', 'woocommerce_checkout_process', 'woocommerce_checkout_create_order',
            'woocommerce_blocks_loaded', 'woocommerce_store_api_checkout_update_order_from_request') as $hook) {
            $this->assertContains($hook, $hooks);
        }
    }

    public function test_blocks_store_api_schema_and_integration() {
        $GLOBALS['wp_test_store_api'] = array();
        $this->checkout->register_blocks();

        $this->assertSame('checkout', $GLOBALS['wp_test_store_api'][0]['endpoint']);
        $this->assertSame('livraria', $GLOBALS['wp_test_store_api'][0]['namespace']);
        $this->assertSame(array('delivery_mode', 'locker_id', 'locker_name', 'locker_courier'), array_keys(Livraria_Checkout::store_api_schema()));

        $integration = new Livraria_Blocks_Integration();
        $this->assertSame('livraria-lockers', $integration->get_name());
        $this->assertSame(array('livraria-checkout-blocks'), $integration->get_script_handles());
        $this->assertSame('livraria_checkout_lockers', $integration->get_script_data()['action']);
    }

    public function test_the_blocks_picker_sits_under_the_shipping_rates_once() {
        $script = file_get_contents(dirname(dirname(__DIR__)) . '/assets/checkout-lockers-blocks.js');
        $this->assertStringContainsString('el(wc.blocksCheckout.ExperimentalOrderShippingPackages, null, el(LockerPicker))', $script);
        $this->assertStringNotContainsString('ExperimentalOrderMeta', $script, 'not in the order summary card');
        $this->assertStringContainsString('owner === null', $script, 'one picker even if the slot renders per package');
    }

    // --- which shipping methods show the locker choice (PrestaShop rule) ------------

    public function test_the_picker_shows_only_for_a_ticked_rate_on_the_classic_checkout() {
        ob_start();
        $this->checkout->render_classic();
        $html = ob_get_clean();
        $this->assertStringContainsString('<div id="livraria-locker-picker" class="livraria-locker-picker" data-context="classic"></div>', $html);

        $GLOBALS['wp_test_wc_session'] = array('chosen_shipping_methods' => array('free_shipping:5'));
        ob_start();
        $this->checkout->render_classic();
        $this->assertStringContainsString('data-context="classic" hidden></div>', ob_get_clean(), 'rendered hidden; the script shows it when a ticked rate is chosen');

        $_POST = array('shipping_method' => array('flat_rate:3'));
        $this->assertSame('flat_rate:3', Livraria_Checkout::chosen_rate(), 'the posted rate wins over the session');

        $script = file_get_contents(dirname(dirname(__DIR__)) . '/assets/checkout-lockers.js');
        $this->assertStringContainsString("on('updated_checkout updated_shipping_method', sync)", $script);
        $this->assertStringContainsString("write({ mode: '', id: '', name: '', courier: '' })", $script, 'hidden = the pick is cleared');
    }

    public function test_the_picker_follows_the_selected_rate_on_the_blocks_checkout() {
        $config = Livraria_Lockers::checkout_config();
        $this->assertSame(array('flat_rate:3'), $config['methods']);

        $script = file_get_contents(dirname(dirname(__DIR__)) . '/assets/checkout-lockers-blocks.js');
        $this->assertStringContainsString("select('wc/store/cart').getShippingRates()", $script);
        $this->assertStringContainsString('rates[i].selected', $script);
        $this->assertStringContainsString("send({ mode: '', id: '', name: '', courier: '' })", $script);
    }

    public function test_a_rate_matches_its_ticked_instance_and_suffixes_only() {
        update_option(Livraria_Lockers::OPT_METHODS, array('flat_rate:3', 'table_rate:5'));
        $this->assertTrue(Livraria_Lockers::rate_offers_lockers('flat_rate:3'));
        $this->assertTrue(Livraria_Lockers::rate_offers_lockers('table_rate:5:2'));
        $this->assertFalse(Livraria_Lockers::rate_offers_lockers('flat_rate:31'));
        $this->assertFalse(Livraria_Lockers::rate_offers_lockers('free_shipping:5'));
        $this->assertFalse(Livraria_Lockers::rate_offers_lockers(''));
    }

    public function test_the_gate_is_inert_for_an_unticked_rate_on_both_checkouts() {
        $GLOBALS['wp_test_wc_session'] = array('chosen_shipping_methods' => array('free_shipping:5'));

        $order = $this->classic_order('locker');
        $this->assertSame(array(), $GLOBALS['wp_test_notices']);
        $this->assertSame(array(), $order->meta);

        $order = $this->blocks_order(array('delivery_mode' => 'locker', 'locker_id' => 'SD-1001', 'locker_courier' => 'sameday'));
        $this->assertSame(array(), $order->meta, 'no locker rides on an order whose rate offers none');

        update_option(Livraria_Lockers::OPT_REQUIRE_LOCKER, '1');
        $this->classic_order('home');
        $this->assertSame(array(), $GLOBALS['wp_test_notices'], 'require locker applies to ticked rates only');
    }

    public function test_no_ticked_method_means_nothing_on_the_checkout() {
        update_option(Livraria_Lockers::OPT_METHODS, array());
        $GLOBALS['wp_test_is_checkout'] = true;
        $GLOBALS['wp_test_enqueued'] = array();

        $this->checkout->enqueue_classic();
        $this->assertSame(array(), $GLOBALS['wp_test_enqueued']);
        ob_start();
        $this->checkout->render_classic();
        $this->assertSame('', ob_get_clean());
        $this->assertSame(array(), (new Livraria_Blocks_Integration())->get_script_handles());
        $this->classic_order('locker');
        $this->assertSame(array(), $GLOBALS['wp_test_notices']);

        update_option(Livraria_Lockers::OPT_METHODS, array('flat_rate:3'));
        $this->checkout->enqueue_classic();
        $this->assertContains('wp_enqueue_script:livraria-checkout-classic', $GLOBALS['wp_test_enqueued']);
    }

    public function test_the_ticked_methods_save_as_a_clean_list() {
        global $wp_test_user_caps;
        $wp_test_user_caps = array('read', 'manage_options');
        $_POST = array('option_name' => 'livraria_locker_methods', 'option_value' => 'free_shipping:5,flat_rate:3,<script>,flat_rate:3');
        try {
            (new LivrariaPlugin())->ajax_update_option();
        } catch (Livraria_Test_Json_Response $response) {
            $this->assertTrue($response->success);
        }
        $wp_test_user_caps = array();
        $this->assertSame(array('free_shipping:5', 'flat_rate:3'), get_option('livraria_locker_methods'));
        $this->assertSame(array(), Livraria_Lockers::sanitize_methods(''));
    }

    public function test_an_order_without_a_pick_field_is_left_alone() {
        $order = $this->blocks_order(array());
        $this->assertSame(array(), $order->meta);
    }

    public function test_the_settings_save_through_the_allowed_option_list() {
        global $wp_test_user_caps;
        $wp_test_user_caps = array('read', 'manage_options');
        $_POST = array('option_name' => 'livraria_lockers_dpd', 'option_value' => 'off');
        try {
            (new LivrariaPlugin())->ajax_update_option();
        } catch (Livraria_Test_Json_Response $response) {
            $this->assertTrue($response->success);
        }
        $wp_test_user_caps = array();
        $this->assertSame('0', get_option('livraria_lockers_dpd'));
        $this->assertFalse(Livraria_Lockers::courier_enabled('dpd'));
    }
}
