<?php
/**
 * Address line 2 goes into street, never into streetNumber.
 */

require_once dirname(__DIR__) . '/QuoteFlowTestCase.php';

class Recipient_Street_Test extends Livraria_Quote_Flow_TestCase {

    public function test_both_lines_are_folded_into_street() {
        $recipient = $this->quoted_recipient($this->order(401, array(
            'shipping_first_name' => 'Andrei',
            'shipping_last_name' => 'Exemplu',
            'shipping_address_1' => 'Strada Teilor 7',
            'shipping_address_2' => 'Bl. D6, sc. A, ap. 5',
            'shipping_city' => 'Brasov',
            'shipping_postcode' => '500001',
        )));

        $this->assertSame('Strada Teilor 7 Bl. D6, sc. A, ap. 5', $recipient['address']['street']);
        $this->assertSame('', $recipient['address']['streetNumber']);
        foreach (array('block', 'staircase', 'floor', 'apartment') as $part) {
            $this->assertSame('', $recipient['address'][$part], $part);
        }
    }

    public function test_one_line_has_no_trailing_space() {
        $this->assertSame('Strada Florilor 12', Livraria_Order_Handler::fold_street('  Strada Florilor 12 ', ''));
        $this->assertSame('Bl. A1', Livraria_Order_Handler::fold_street('', 'Bl. A1'));
    }

    public function test_street_is_cut_at_100_characters_without_breaking_a_diacritic() {
        $line1 = str_repeat('Ș', 60);
        $line2 = str_repeat('ț', 60);
        $street = Livraria_Order_Handler::fold_street($line1, $line2);

        $this->assertSame(100, mb_strlen($street, 'UTF-8'));
        $this->assertTrue(mb_check_encoding($street, 'UTF-8'));
        $this->assertStringStartsWith($line1 . ' ', $street);
    }
}
