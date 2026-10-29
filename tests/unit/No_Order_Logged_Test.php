<?php
/**
 * Even in development mode, the debug log carries ids and counts, never the
 * recipient's or the sender's name, phone, email or address.
 */

require_once dirname(__DIR__) . '/QuoteFlowTestCase.php';

class No_Order_Logged_Test extends Livraria_Quote_Flow_TestCase {

    /** Values of the fixture order and sender profile that must never be logged */
    private $personal = array('Ioana', 'Cluj-Napoca', 'ioana@example.com', '700000101', '0700000101', 'Strada Florilor', '400001',
        'Magazin Exemplu', 'expeditor@example.com', '700000001', 'Strada Exemplu 1', '010101', 'RO00XXXX0000000000000000');

    public function test_redact_masks_people_and_addresses_at_any_depth() {
        $redacted = Livraria_API_Client::redact(array(
            'externalRef' => 'WP-shop-1101',
            'recipient' => array(
                'name' => 'Ioana Exemplu', 'phoneCountryCode' => '+40', 'phoneNSN' => '700000101', 'email' => 'ioana@example.com',
                'companyName' => 'Firma Exemplu SRL', 'cui' => 'RO00000000',
                'address' => array('street' => 'Strada Florilor 12', 'city' => 'CLUJ-NAPOCA', 'county' => 'CLUJ', 'postcode' => '400001'),
            ),
            'service' => array('cod' => array('amount' => 10, 'bankAccount' => 'RO00XXXX0000000000000000')),
            'shipmentNote' => 'Order from Magazin Exemplu - Order #1101',
        ));
        $json = json_encode($redacted);

        foreach (array('Ioana', '700000101', 'ioana@example.com', 'Firma Exemplu', 'RO00000000', 'Strada Florilor', 'CLUJ', '400001', 'RO00XXXX', 'Magazin Exemplu') as $value) {
            $this->assertStringNotContainsString($value, $json, $value);
        }
        $this->assertSame('WP-shop-1101', $redacted['externalRef']);
        $this->assertSame(10, $redacted['service']['cod']['amount']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_dev_mode_quote_and_save_log_ids_and_counts_only() {
        define('WP_DEBUG', true);
        define('LIVRARIA_DEV_MODE', true);
        $this->assertTrue(Livraria_API_Client::is_dev_mode());
        $this->routes['/users/me/sender-profile'] = $this->json(200, array(
            'id' => 'sp-invented-1', 'name' => 'Magazin Exemplu SRL', 'email' => 'expeditor@example.com',
            'phoneCountryCode' => '+40', 'phoneNSN' => '700000001', 'country' => 'Romania', 'county' => 'Bucuresti',
            'city' => 'Bucuresti', 'postcode' => '010101', 'street' => 'Strada Exemplu 1', 'codIban' => 'RO00XXXX0000000000000000',
        ));
        $this->routes['/public/location/cities'] = $this->json(500, array('message' => 'down'));
        $this->routes['/public/location/postcodes'] = $this->json(500, array('message' => 'down'));
        $this->start_log_capture();
        $order = $this->order(1101, array('payment_method' => 'cod'));

        $result = $this->handler()->get_quotes_for_order(1101);
        $this->handler()->save_expedition_data(1101, array('id' => 'exp-invented-1101', 'awbNumber' => 'AWB0000001101', 'courierQuote' => array('courierName' => 'Curier Exemplu')), array('id' => 'cq-invented-1', 'amount' => 21.5));

        $this->assertTrue($result['success']);
        $log = $this->captured_log();
        $this->assertNotSame('', $log, 'dev mode logs something');
        foreach ($this->personal as $value) {
            $this->assertStringNotContainsString($value, $log, $value);
        }
        $this->assertStringContainsString('quote request for order 1101', $log);
        $this->assertStringContainsString('1 package(s)', $log);
        $this->assertStringContainsString('AWB0000001101', $log);
    }
}
