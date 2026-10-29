<?php
/**
 * The recipient phone is normalised to +40 and a 9-digit NSN; a number that
 * cannot be placed stops the quote before any API call.
 */

require_once dirname(__DIR__) . '/QuoteFlowTestCase.php';

class Recipient_Phone_Test extends Livraria_Quote_Flow_TestCase {

    public function romanian_shapes() {
        return array(
            'spaces' => array('0700 000 001', '700000001'),
            'dots' => array('0700.000.002', '700000002'),
            'dashes' => array('0700-000-003', '700000003'),
            'plus 40' => array('+40700000004', '700000004'),
            'plus 40 spaced' => array('+40 700 000 005', '700000005'),
            '0040' => array('0040700000006', '700000006'),
            '40 no plus' => array('40700000007', '700000007'),
            'no trunk zero' => array('700000008', '700000008'),
            'plus 40 and trunk zero' => array('+40 0700 000 009', '700000009'),
            'brackets' => array('(0700) 000 010', '700000010'),
            'invented range' => array('0700000123', '700000123'),
            'landline 02' => array('021 000 0123', '210000123'),
            'landline 03' => array('0310 000 123', '310000123'),
        );
    }

    /**
     * @dataProvider romanian_shapes
     */
    public function test_romanian_shapes_become_plus40_and_nine_digits($typed, $nsn) {
        $parsed = Livraria_Order_Handler::normalize_phone($typed);
        $this->assertSame(array('phoneCountryCode' => '+40', 'phoneNSN' => $nsn), $parsed);
        $this->assertSame('', Livraria_Order_Handler::phone_problem($parsed));
    }

    public function test_explicit_foreign_codes_are_kept() {
        $this->assertSame(array('phoneCountryCode' => '+36', 'phoneNSN' => '700000011'), Livraria_Order_Handler::normalize_phone('+36 70 000 0011'));
        $this->assertSame(array('phoneCountryCode' => '+33', 'phoneNSN' => '600000012'), Livraria_Order_Handler::normalize_phone('0033 6 00 00 00 12'));
        $this->assertSame(array('phoneCountryCode' => '+373', 'phoneNSN' => '69000013'), Livraria_Order_Handler::normalize_phone('+373 69 000 013'));
        $this->assertSame('', Livraria_Order_Handler::phone_problem(Livraria_Order_Handler::normalize_phone('+36 70 000 0011')));
    }

    public function test_numbers_that_cannot_be_placed() {
        $this->assertSame('unplaceable', Livraria_Order_Handler::phone_problem(Livraria_Order_Handler::normalize_phone('12345')));
        $this->assertSame('invalid_ro', Livraria_Order_Handler::phone_problem(Livraria_Order_Handler::normalize_phone('+40 123 456 789')));
        $this->assertSame('invalid_ro', Livraria_Order_Handler::phone_problem(Livraria_Order_Handler::normalize_phone('0812 345 678')));
        $this->assertSame('invalid_ro', Livraria_Order_Handler::phone_problem(Livraria_Order_Handler::normalize_phone('0700 000')));
        $this->assertSame('missing', Livraria_Order_Handler::phone_problem(Livraria_Order_Handler::normalize_phone('  ')));
    }

    public function test_an_unplaceable_phone_stops_before_any_api_call() {
        $order = $this->order(301, array('billing_phone' => '12345'));

        $result = $this->handler()->get_quotes_for_order(301);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('"12345"', $result['message']);
        $this->assertStringContainsString('billing phone', $result['message']);
        $this->assertSame('phone', $result['fix']);
        $this->assertCount(0, $this->requests);
    }

    public function test_a_phone_field_without_digits_is_unplaceable_not_missing() {
        $this->order(306, array('billing_phone' => 'n/a'));

        $result = $this->handler()->get_quotes_for_order(306);

        $this->assertStringContainsString('"n/a"', $result['message']);
        $this->assertStringContainsString('cannot be used', $result['message']);
        $this->assertCount(0, $this->requests);
    }

    public function test_an_invalid_romanian_number_stops_before_any_api_call() {
        $this->order(302, array('billing_phone' => '+40 123 456 789'));

        $result = $this->handler()->get_quotes_for_order(302);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not a valid Romanian number', $result['message']);
        $this->assertCount(0, $this->requests);
    }

    public function test_no_phone_at_all_stops_with_a_clear_message() {
        $this->order(303, array('billing_phone' => '', 'shipping_phone' => ' '));

        $result = $this->handler()->get_quotes_for_order(303);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no recipient phone number', $result['message']);
        $this->assertSame('phone', $result['fix']);
        $this->assertCount(0, $this->requests);
    }

    public function test_shipping_phone_comes_first_then_billing() {
        $recipient = $this->quoted_recipient($this->order(304, array('shipping_phone' => '0700 000 304', 'billing_phone' => '0700000999')));
        $this->assertSame('+40', $recipient['phoneCountryCode']);
        $this->assertSame('700000304', $recipient['phoneNSN']);

        $recipient = $this->quoted_recipient($this->order(305, array('shipping_phone' => '', 'billing_phone' => '+40 700 000 305')));
        $this->assertSame('700000305', $recipient['phoneNSN']);
    }
}
