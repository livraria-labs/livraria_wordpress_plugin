<?php
/**
 * The service.cod block is sent only on a COD order or when the merchant typed
 * an amount; with no "cod" key at all otherwise.
 */

require_once dirname(__DIR__) . '/QuoteFlowTestCase.php';

class Cod_Test extends Livraria_Quote_Flow_TestCase {

    public function setUp(): void {
        parent::setUp();
        $this->routes['/users/me/sender-profile'] = $this->json(200, array(
            'id' => 'sp-invented-1',
            'name' => 'Magazin Exemplu SRL',
            'email' => 'expeditor@example.com',
            'phoneCountryCode' => '+40',
            'phoneNSN' => '700000001',
            'country' => 'Romania',
            'county' => 'Bucuresti',
            'city' => 'Bucuresti',
            'postcode' => '010101',
            'street' => 'Strada Exemplu 1',
            'codIban' => 'RO00XXXX0000000000000000',
        ));
    }

    private function service_for($order, array $custom = array()) {
        $result = $this->handler()->get_quotes_for_order($order->get_id(), $custom);
        $this->assertTrue($result['success'], $result['message'] ?? '');
        return $this->sent('/public/awb/quotes')['service'];
    }

    public function test_a_prepaid_order_sends_no_cod_key() {
        $service = $this->service_for($this->order(801, array('payment_method' => 'bacs', 'payment_method_title' => 'Direct bank transfer')));
        $this->assertArrayNotHasKey('cod', $service);
        $this->assertArrayHasKey('insurance', $service);
    }

    public function test_the_cod_gateway_collects_the_order_total_into_the_profile_iban() {
        $service = $this->service_for($this->order(802, array('payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery', 'total' => '149.90')));
        $this->assertSame(array('amount' => 149.9, 'collector' => 'CLIENT', 'bankAccount' => 'RO00XXXX0000000000000000'), $service['cod']);
    }

    public function test_cod_titles_are_recognised() {
        foreach (array('Plata ramburs la livrare', 'Numerar la livrare', 'Pay on delivery', 'Payment on Delivery') as $title) {
            $order = new Livraria_Fake_Order(803, array('payment_method' => 'custom_gateway', 'payment_method_title' => $title));
            $this->assertTrue(Livraria_Order_Handler::is_cod_order($order), $title);
        }
        foreach (array('Card online', 'Barcode wallet', 'Direct bank transfer', '') as $title) {
            $order = new Livraria_Fake_Order(804, array('payment_method' => 'custom_gateway', 'payment_method_title' => $title));
            $this->assertFalse(Livraria_Order_Handler::is_cod_order($order), $title);
        }
    }

    public function test_a_typed_amount_on_a_prepaid_order_sends_cod() {
        $service = $this->service_for($this->order(805), array('cod_amount' => 50));
        $this->assertSame(50.0, (float) $service['cod']['amount']);
        $this->assertSame('CLIENT', $service['cod']['collector']);
    }

    public function test_a_typed_amount_replaces_the_order_total() {
        $service = $this->service_for($this->order(806, array('payment_method' => 'cod', 'total' => '149.90')), array('cod_amount' => 99.5));
        $this->assertSame(99.5, (float) $service['cod']['amount']);
    }

    public function test_a_zero_typed_amount_sends_no_cod_key() {
        $this->assertArrayNotHasKey('cod', $this->service_for($this->order(807), array('cod_amount' => 0)));
        $this->assertArrayNotHasKey('cod', $this->service_for($this->order(808, array('payment_method' => 'cod')), array('cod_amount' => 0)));
    }

    public function test_no_iban_on_the_profile_means_no_bank_account() {
        $this->routes['/users/me/sender-profile'] = $this->json(200, array(
            'id' => 'sp-invented-2', 'name' => 'Magazin Exemplu SRL', 'email' => 'expeditor@example.com',
            'phoneCountryCode' => '+40', 'phoneNSN' => '700000002',
            'country' => 'Romania', 'county' => 'Bucuresti', 'city' => 'Bucuresti', 'postcode' => '010101', 'street' => 'Strada Exemplu 2',
        ));
        $service = $this->service_for($this->order(809, array('payment_method' => 'cod', 'total' => '20')));
        $this->assertEquals(array('amount' => 20, 'collector' => 'CLIENT'), $service['cod']);
    }
}
