<?php
/**
 * Order meta goes through the order object (HPOS-safe), keeps the existing
 * key names, and still finds what 1.1.0 wrote into wp_postmeta.
 */

require_once dirname(__DIR__) . '/TestCase.php';

class Order_Meta_Test extends Livraria_TestCase {

    public function setUp(): void {
        parent::setUp();
        $GLOBALS['wp_test_post_meta'] = array();
        $GLOBALS['wp_test_orders'] = array();
    }

    public function test_writes_go_through_the_order_and_save_once() {
        $order = new Livraria_Fake_Order(201);
        $GLOBALS['wp_test_orders'][201] = $order;

        $this->assertTrue(Livraria_Order_Handler::set_order_meta(201, array(
            '_courier_quote_request_id' => 'qr-invented-201',
            '_courier_selected_quote_id' => 'cq-invented-201',
        )));

        $this->assertSame('qr-invented-201', $order->meta['_courier_quote_request_id']);
        $this->assertSame('cq-invented-201', $order->meta['_courier_selected_quote_id']);
        $this->assertSame(1, $order->saves);
        $this->assertSame('qr-invented-201', Livraria_Order_Handler::get_order_meta(201, '_courier_quote_request_id'));
        $this->assertSame('qr-invented-201', Livraria_Order_Handler::get_order_meta($order, '_courier_quote_request_id'));
    }

    public function test_a_missing_order_writes_nothing() {
        $this->assertFalse(Livraria_Order_Handler::set_order_meta(999, array('_courier_name' => 'X')));
        $this->assertSame('', Livraria_Order_Handler::get_order_meta(999, '_courier_name'));
    }

    public function test_reads_fall_back_to_what_1_1_0_wrote_in_postmeta() {
        $order = new Livraria_Fake_Order(202);
        $GLOBALS['wp_test_orders'][202] = $order;
        $GLOBALS['wp_test_post_meta'][202] = array('_courier_expedition_id' => 'exp-invented-202', '_courier_awb_number' => 'AWB0000000202');

        $this->assertSame('exp-invented-202', Livraria_Order_Handler::get_order_meta(202, '_courier_expedition_id'));

        // The order's own meta wins
        $order->meta['_courier_awb_number'] = 'AWB0000000999';
        $this->assertSame('AWB0000000999', Livraria_Order_Handler::get_order_meta(202, '_courier_awb_number'));
    }

    public function test_delete_removes_order_meta_and_the_legacy_copy() {
        $order = new Livraria_Fake_Order(203);
        $order->meta = array('_courier_expedition_id' => 'exp-invented-203', '_courier_name' => 'Curier Exemplu');
        $GLOBALS['wp_test_orders'][203] = $order;
        $GLOBALS['wp_test_post_meta'][203] = array('_courier_expedition_id' => 'exp-invented-203');

        Livraria_Order_Handler::delete_order_meta(203, array('_courier_expedition_id', '_courier_name'));

        $this->assertSame(array(), $order->meta);
        $this->assertSame('', Livraria_Order_Handler::get_order_meta(203, '_courier_expedition_id'));
        $this->assertSame(1, $order->saves);
    }

    public function test_save_expedition_data_keeps_the_key_names_in_one_save() {
        $order = new Livraria_Fake_Order(204);
        $GLOBALS['wp_test_orders'][204] = $order;
        $handler = new Livraria_Order_Handler(new Livraria_API_Client('https://www.api.livraria.ro'));

        $handler->save_expedition_data(204, array(
            'id' => 'exp-invented-204',
            'awbNumber' => 'AWB0000000204',
            'status' => array('name' => 'Created'),
            'courierQuote' => array('courierName' => 'Curier Exemplu'),
        ), array('id' => 'cq-invented-204', 'amount' => 18.5));

        $this->assertSame(1, $order->saves);
        foreach (array(
            '_courier_expedition_id' => 'exp-invented-204',
            '_courier_quote_id' => 'cq-invented-204',
            '_courier_name' => 'Curier Exemplu',
            '_courier_price' => 18.5,
            '_courier_awb_number' => 'AWB0000000204',
            '_courier_status' => 'Created',
        ) as $key => $value) {
            $this->assertSame($value, $order->meta[$key], $key);
        }
        $this->assertArrayHasKey('_courier_expedition_created', $order->meta);
        $this->assertSame('AWB0000000204', $handler->get_order_expedition_data(204)['awb_number']);
    }

    public function test_hpos_compatibility_is_declared_before_woocommerce_init() {
        global $wp_test_actions;
        $this->assertContains('livraria_declare_hpos_compatibility', $wp_test_actions['before_woocommerce_init'] ?? array());

        require_once dirname(__DIR__) . '/stubs/FeaturesUtil.php';
        \Automattic\WooCommerce\Utilities\FeaturesUtil::$declared = array();
        livraria_declare_hpos_compatibility();

        $declared = \Automattic\WooCommerce\Utilities\FeaturesUtil::$declared;
        $this->assertCount(1, $declared);
        $this->assertSame('custom_order_tables', $declared[0][0]);
        $this->assertStringEndsWith('courier-expedition-wp-plugin.php', $declared[0][1]);
        $this->assertTrue($declared[0][2]);
    }

    public function test_no_code_writes_post_meta_directly() {
        $root = dirname(dirname(__DIR__));
        foreach (array('courier-expedition-wp-plugin.php', 'includes/class-order-handler.php', 'includes/class-api-client.php', 'includes/class-admin-page.php') as $file) {
            $called = array();
            foreach (token_get_all(file_get_contents($root . '/' . $file)) as $token) {
                if (is_array($token) && $token[0] === T_STRING) {
                    $called[$token[1]] = true;
                }
            }
            $this->assertArrayNotHasKey('update_post_meta', $called, $file);
            $this->assertArrayNotHasKey('add_post_meta', $called, $file);
        }
    }

    public function test_remove_expedition_association_clears_the_order() {
        global $wp_test_user_caps;
        $wp_test_user_caps = array('read', 'manage_woocommerce');
        $order = new Livraria_Fake_Order(205);
        $order->meta = array('_courier_expedition_id' => 'exp-invented-205', '_courier_awb_number' => 'AWB0000000205', '_other_plugin_key' => 'kept');
        $GLOBALS['wp_test_orders'][205] = $order;
        $_POST = array('order_id' => '205');
        $plugin = new LivrariaPlugin();

        try {
            $plugin->ajax_delete_expedition();
            $this->fail('no JSON response');
        } catch (Livraria_Test_Json_Response $response) {
            $this->assertTrue($response->success);
        } finally {
            $_POST = array();
            $wp_test_user_caps = array();
        }
        $this->assertSame(array('_other_plugin_key' => 'kept'), $order->meta);
    }
}
