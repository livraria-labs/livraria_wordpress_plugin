<?php
/**
 * WooCommerce > Orders > Add order (HPOS: admin.php?page=wc-orders&action=new):
 * no id in the URL and an unsaved order.
 */

require_once dirname(__DIR__) . '/QuoteFlowTestCase.php';

if (!class_exists('WC_Order')) {
    class WC_Order extends Livraria_Fake_Order {
    }
}

class New_Order_Screen_Test extends Livraria_Quote_Flow_TestCase {

    public function tearDown(): void {
        unset($GLOBALS['theorder'], $GLOBALS['wp_test_screen'], $GLOBALS['wp_test_localized']);
        $_GET = array();
        parent::tearDown();
    }

    public function test_a_draft_order_gets_one_line_and_no_buttons() {
        $order = new WC_Order(1501, array('status' => 'auto-draft'));
        $GLOBALS['wp_test_orders'][1501] = $order;

        ob_start();
        (new LivrariaPlugin())->expedition_meta_box_callback($order);
        $html = ob_get_clean();

        $this->assertSame('<p>Save the order first, then quote it here.</p>', $html);
        $this->assertStringNotContainsString('<button', $html);
        $this->assertCount(0, $this->requests);
        $this->assertTrue(LivrariaPlugin::is_draft_order(new Livraria_Fake_Order(1502, array('status' => 'checkout-draft'))));
        $this->assertFalse(LivrariaPlugin::is_draft_order(new Livraria_Fake_Order(1503)));
    }

    public function test_the_order_id_falls_back_to_theorder() {
        $_GET = array('page' => 'wc-orders', 'action' => 'edit');
        $this->assertSame(0, LivrariaPlugin::current_order_id(), 'no order anywhere');

        $GLOBALS['theorder'] = new WC_Order(1504);
        $GLOBALS['wp_test_orders'][1504] = $GLOBALS['theorder'];
        $GLOBALS['wp_test_screen'] = (object) array('id' => 'woocommerce_page_wc-orders', 'post_type' => '');

        (new LivrariaPlugin())->enqueue_admin_styles();

        $data = $GLOBALS['wp_test_localized']['livraria-order-js']['data'];
        $this->assertSame(1504, $data['orderId']);
        $this->assertArrayHasKey('lockerPicker', $data);

        $_GET = array('id' => '1505');
        $this->assertSame(1505, LivrariaPlugin::current_order_id(), 'the URL wins');
    }

    public function test_a_draft_order_gets_no_route_script_data() {
        $GLOBALS['theorder'] = new WC_Order(1506, array('status' => 'auto-draft'));
        $GLOBALS['wp_test_orders'][1506] = $GLOBALS['theorder'];
        $GLOBALS['wp_test_screen'] = (object) array('id' => 'woocommerce_page_wc-orders', 'post_type' => '');

        (new LivrariaPlugin())->enqueue_admin_styles();

        $data = $GLOBALS['wp_test_localized']['livraria-order-js']['data'];
        $this->assertSame(1506, $data['orderId']);
        $this->assertArrayNotHasKey('lockerPicker', $data);
        $this->assertArrayNotHasKey('buyerPick', $data);
    }
}
