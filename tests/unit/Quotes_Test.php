<?php
/**
 * Disabled quotes are shown (greyed, last, with the API's reason) and never
 * auto-picked; the unattended pick is the cheapest usable quote.
 * Quotes are invented, shaped like the backend's courierQuotes.
 */

require_once dirname(__DIR__) . '/QuoteFlowTestCase.php';

class Quotes_Test extends Livraria_Quote_Flow_TestCase {

    private function api_quotes() {
        return array(
            array('id' => 'cq-fan', 'courierName' => 'Curier Unu', 'amount' => 0, 'currency' => 'RON', 'isDisabled' => true,
                  'error' => array('message' => 'No pickup point is configured for this courier on your account.')),
            array('id' => 'cq-dpd', 'courierName' => 'Curier Doi', 'amount' => 21.5, 'currency' => 'RON', 'isDisabled' => false),
            array('id' => 'cq-zero', 'courierName' => 'Curier Trei', 'amount' => 0, 'currency' => 'RON'),
            array('id' => 'cq-locker', 'courierName' => 'Curier Patru', 'amount' => 12, 'currency' => 'RON', 'isLockerQuote' => true),
            array('id' => 'cq-str', 'courierName' => 'Curier Cinci', 'amount' => '18.20', 'currency' => 'RON'),
            array('id' => '', 'courierName' => 'Curier Sase', 'amount' => 9, 'currency' => 'RON'),
            array('id' => 'cq-email', 'courierName' => 'Curier Sapte', 'amount' => 0, 'currency' => 'RON', 'isDisabled' => true,
                  'error' => array('code' => 'RECIPIENT_FIELDS_REQUIRED', 'message' => 'This courier needs the recipient\'s email.', 'missingRecipientFields' => array('email'))),
        );
    }

    public function test_disabled_quotes_are_kept_last_with_their_reason() {
        $quotes = Livraria_Order_Handler::normalize_quotes($this->api_quotes());

        $this->assertSame(array('cq-dpd', 'cq-locker', 'cq-str', 'cq-fan', 'cq-zero', '', 'cq-email'), array_column($quotes, 'id'));
        $this->assertSame(array(true, true, true, false, false, false, false), array_column($quotes, 'usable'));

        $fan = $quotes[3];
        $this->assertTrue($fan['isDisabled']);
        $this->assertSame('No pickup point is configured for this courier on your account.', $fan['disabledReason']);
        $this->assertSame('No price was returned for this courier.', $quotes[4]['disabledReason']);
        $this->assertSame('', $quotes[0]['disabledReason']);
    }

    public function test_locker_quotes_are_kept_with_their_route() {
        $quotes = Livraria_Order_Handler::normalize_quotes($this->api_quotes());
        $locker = $quotes[array_search('cq-locker', array_column($quotes, 'id'), true)];
        $this->assertSame('DOOR_TO_LOCKER', $locker['deliveryType']);
        $this->assertSame('DOOR_TO_DOOR', $quotes[0]['deliveryType']);
    }

    public function test_recipient_fields_required_marks_the_email_fill_in() {
        $quotes = Livraria_Order_Handler::normalize_quotes($this->api_quotes());
        $email = $quotes[6];

        $this->assertTrue($email['needsEmail']);
        $this->assertSame('RECIPIENT_FIELDS_REQUIRED', $email['errorCode']);
        $this->assertSame(array('email'), $email['missingRecipientFields']);
        $this->assertFalse($quotes[3]['needsEmail']);

        // missingRecipientFields at the quote's top level is read too
        $top = Livraria_Order_Handler::normalize_quotes(array(array('id' => 'cq-8', 'amount' => 0, 'isDisabled' => true,
            'missingRecipientFields' => array('email'), 'error' => array('code' => 'RECIPIENT_FIELDS_REQUIRED', 'message' => 'Email needed.'))));
        $this->assertTrue($top[0]['needsEmail']);
    }

    public function test_auto_pick_is_the_cheapest_usable_never_a_disabled_zero() {
        $quotes = Livraria_Order_Handler::normalize_quotes($this->api_quotes());
        $best = Livraria_Order_Handler::select_best_quote($quotes);
        $this->assertSame('cq-locker', $best['id'], 'the cheapest usable quote of any route');

        $none = Livraria_Order_Handler::normalize_quotes(array($this->api_quotes()[0], $this->api_quotes()[6]));
        $this->assertNull(Livraria_Order_Handler::select_best_quote($none));
        $this->assertSame(
            'Curier Unu: No pickup point is configured for this courier on your account. · Curier Sapte: This courier needs the recipient\'s email.',
            Livraria_Order_Handler::summarise_unusable_quotes($none)
        );
    }

    public function test_bad_input_gives_no_quotes() {
        $this->assertSame(array(), Livraria_Order_Handler::normalize_quotes(null));
        $this->assertSame(array(), Livraria_Order_Handler::normalize_quotes('oops'));
        $this->assertSame(array(), Livraria_Order_Handler::normalize_quotes(array('not-a-quote')));
        $this->assertNull(Livraria_Order_Handler::select_best_quote(array()));
    }

    public function test_the_panel_gets_every_quote_and_the_auto_pick() {
        $this->routes['/public/awb/quotes'] = $this->json(201, array('quoteRequest' => array('id' => 'qr-invented-6'), 'courierQuotes' => $this->api_quotes()));
        $this->order(601);

        $result = $this->handler()->get_quotes_for_order(601);

        $this->assertTrue($result['success']);
        $this->assertCount(6, $result['quotes']);
        $this->assertSame('cq-str', $result['autoQuoteId']);
        $this->assertSame('qr-invented-6', $result['quoteRequestId']);
    }

    public function test_only_disabled_quotes_still_reach_the_panel_with_no_auto_pick() {
        $only_disabled = array($this->api_quotes()[0], $this->api_quotes()[6]);
        $this->routes['/public/awb/quotes'] = $this->json(201, array('quoteRequest' => array('id' => 'qr-invented-7'), 'courierQuotes' => $only_disabled));
        $this->order(602);

        $result = $this->handler()->get_quotes_for_order(602);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['quotes']);
        $this->assertNull($result['autoQuoteId']);
        $this->assertStringContainsString('Curier Unu: No pickup point', $result['unusableSummary']);
    }
}
