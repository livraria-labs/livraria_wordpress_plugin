<?php
/**
 * Weights reach the API in kg and dimensions in cm whatever the shop's units;
 * an order is one parcel by default; typed packages are sent as typed.
 */

require_once dirname(__DIR__) . '/QuoteFlowTestCase.php';

class Parcels_Test extends Livraria_Quote_Flow_TestCase {

    private function packages_for($order, array $custom = array()) {
        return $this->handler()->calculate_packages_from_order($order, $custom);
    }

    public function test_weight_units() {
        $this->assertEqualsWithDelta(1.5, Livraria_Order_Handler::to_kilograms(1500, 'g'), 1e-9);
        $this->assertEqualsWithDelta(1.5, Livraria_Order_Handler::to_kilograms('1.5', 'kg'), 1e-9);
        $this->assertEqualsWithDelta(0.45359237, Livraria_Order_Handler::to_kilograms(1, 'lbs'), 1e-9);
        $this->assertEqualsWithDelta(0.45359237, Livraria_Order_Handler::to_kilograms(16, 'oz'), 1e-6);
        $this->assertSame(0.0, Livraria_Order_Handler::to_kilograms('', 'g'));
    }

    public function test_dimension_units() {
        $this->assertEqualsWithDelta(120.0, Livraria_Order_Handler::to_centimetres(1.2, 'm'), 1e-9);
        $this->assertEqualsWithDelta(25.0, Livraria_Order_Handler::to_centimetres(250, 'mm'), 1e-9);
        $this->assertEqualsWithDelta(25.4, Livraria_Order_Handler::to_centimetres(10, 'in'), 1e-9);
        $this->assertEqualsWithDelta(91.44, Livraria_Order_Handler::to_centimetres(1, 'yd'), 1e-9);
        $this->assertEqualsWithDelta(30.0, Livraria_Order_Handler::to_centimetres(30, 'cm'), 1e-9);
    }

    public function test_one_parcel_per_order_in_kg_and_cm() {
        update_option('woocommerce_weight_unit', 'g');
        update_option('woocommerce_dimension_unit', 'mm');
        $order = $this->order(1001);
        $order->items = array(
            new Livraria_Fake_Item(new Livraria_Fake_Product(800, 300, 200, 50), 2),   // 0.8 kg, 30 x 20 x 5 cm
            new Livraria_Fake_Item(new Livraria_Fake_Product(1200, 400, 150, 100), 1), // 1.2 kg, 40 x 15 x 10 cm
        );

        $packages = $this->packages_for($order);

        $this->assertCount(1, $packages);
        $this->assertEquals(array('weight' => 2.8, 'width' => 20.0, 'height' => 20.0, 'length' => 40.0, 'packageType' => 'package'), $packages[0]);
    }

    public function test_lines_without_data_count_with_the_defaults() {
        $order = $this->order(1002);
        $order->items = array(new Livraria_Fake_Item(new Livraria_Fake_Product('', '', '', ''), 3));

        $packages = $this->packages_for($order);

        $this->assertEquals(array('weight' => 3.0, 'width' => 10.0, 'height' => 30.0, 'length' => 10.0, 'packageType' => 'package'), $packages[0]);
    }

    public function test_a_light_order_is_raised_to_the_panel_minimums() {
        $order = $this->order(1003);
        $order->items = array(new Livraria_Fake_Item(new Livraria_Fake_Product('0.2', '15', '5', '2'), 1));

        $packages = $this->packages_for($order);

        $this->assertEquals(array('weight' => 1.0, 'width' => 10.0, 'height' => 10.0, 'length' => 15.0, 'packageType' => 'package'), $packages[0]);
    }

    public function test_typed_packages_are_sent_as_typed_even_below_the_minimum() {
        $order = $this->order(1004);
        $order->items = array(new Livraria_Fake_Item(new Livraria_Fake_Product(5, 50, 50, 50), 1));

        $packages = $this->packages_for($order, array('packages' => array(
            array('weight' => 0.4, 'width' => 8, 'height' => 3, 'length' => 12),
            array('weight' => 2, 'width' => 20, 'height' => 20, 'length' => 20),
        )));

        $this->assertCount(2, $packages);
        $this->assertSame(0.4, $packages[0]['weight']);
        $this->assertSame(8, $packages[0]['width']);
    }

    public function test_per_line_mode_keeps_one_parcel_per_line() {
        update_option('livraria_package_mode', 'per_line');
        update_option('woocommerce_weight_unit', 'kg');
        update_option('woocommerce_dimension_unit', 'cm');
        $order = $this->order(1005);
        $order->items = array(
            new Livraria_Fake_Item(new Livraria_Fake_Product(2, 30, 20, 10), 2),
            new Livraria_Fake_Item(new Livraria_Fake_Product(1.5, 40, 30, 20), 1),
        );

        $packages = $this->packages_for($order);

        $this->assertCount(2, $packages);
        $this->assertEquals(4.0, $packages[0]['weight']);
        $this->assertEquals(1.5, $packages[1]['weight']);
    }

    public function test_the_quote_request_carries_the_one_parcel() {
        update_option('woocommerce_weight_unit', 'lbs');
        $order = $this->order(1006);
        $order->items = array(new Livraria_Fake_Item(new Livraria_Fake_Product(10, 20, 20, 20), 1));

        $result = $this->handler()->get_quotes_for_order(1006);

        $this->assertTrue($result['success']);
        $sent = $this->sent('/public/awb/quotes')['content']['packages'];
        $this->assertCount(1, $sent);
        $this->assertEqualsWithDelta(4.54, $sent[0]['weight'], 0.001);
    }
}
