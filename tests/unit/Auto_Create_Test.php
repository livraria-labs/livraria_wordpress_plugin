<?php
/**
 * The unattended run claims the order before quoting, so a repeated status
 * change cannot buy two AWBs; one shipment per order in the handler.
 */

require_once dirname(__DIR__) . '/QuoteFlowTestCase.php';

class Auto_Create_Test extends Livraria_Quote_Flow_TestCase {

    const CLAIM = Livraria_Order_Handler::AUTO_CLAIM_META;

    public function setUp(): void {
        parent::setUp();
        global $wp_test_user_caps;
        $wp_test_user_caps = array('read', 'edit_shop_orders');
        update_option('livraria_default_sender_profile_id', 'sp-invented-1');
        update_option('courier_auto_create', 1);
    }

    public function tearDown(): void {
        global $wp_test_user_caps;
        $wp_test_user_caps = array();
        $_POST = array();
        parent::tearDown();
    }

    private function call_ajax($method, array $post) {
        $_POST = $post;
        try {
            (new LivrariaPlugin())->$method();
        } catch (Livraria_Test_Json_Response $response) {
            return $response;
        }
        $this->fail($method . ' sent no JSON response');
    }

    private function count_requests($fragment) {
        return count(array_filter($this->requests, function($r) use ($fragment) {
            return strpos($r['url'], $fragment) !== false;
        }));
    }

    public function test_a_claim_holds_for_ten_minutes() {
        $order = $this->order(901);
        $now = 1800000000;

        $this->assertTrue(Livraria_Order_Handler::claim_order_for_auto_create($order, $now)['claimed']);
        $this->assertSame((string) $now, $order->meta[self::CLAIM]);

        $second = Livraria_Order_Handler::claim_order_for_auto_create($order, $now + 599);
        $this->assertFalse($second['claimed']);
        $this->assertStringContainsString('still running', $second['message']);

        $this->assertTrue(Livraria_Order_Handler::claim_order_for_auto_create($order, $now + 601)['claimed']);
    }

    public function test_an_unreadable_claim_holds() {
        $order = $this->order(902);
        $order->meta[self::CLAIM] = 'not-a-timestamp';

        $this->assertFalse(Livraria_Order_Handler::claim_order_for_auto_create($order, time() + 86400)['claimed']);
        $this->assertSame('not-a-timestamp', $order->meta[self::CLAIM]);
    }

    public function test_an_order_with_a_shipment_is_never_claimed() {
        $order = $this->order(903);
        $order->meta['_courier_expedition_id'] = 'exp-invented-903';

        $claim = Livraria_Order_Handler::claim_order_for_auto_create($order);
        $this->assertFalse($claim['claimed']);
        $this->assertStringContainsString('already has a shipment', $claim['message']);
        $this->assertArrayNotHasKey(self::CLAIM, $order->meta);
    }

    public function test_a_second_auto_run_is_refused_before_any_api_call() {
        $this->order(904);

        $first = $this->call_ajax('ajax_get_quotes_for_order', array('order_id' => '904', 'auto' => '1'));
        $this->assertTrue($first->success);
        $count = count($this->requests);

        $second = $this->call_ajax('ajax_get_quotes_for_order', array('order_id' => '904', 'auto' => '1'));
        $this->assertFalse($second->success);
        $this->assertStringContainsString('still running', $second->data);
        $this->assertCount($count, $this->requests);
    }

    public function test_a_manual_quote_does_not_claim() {
        $order = $this->order(905);
        $this->assertTrue($this->call_ajax('ajax_get_quotes_for_order', array('order_id' => '905'))->success);
        $this->assertArrayNotHasKey(self::CLAIM, $order->meta);
    }

    public function test_an_auto_run_that_cannot_quote_releases_the_claim_and_notes_why() {
        $order = $this->order(906, array('billing_phone' => '12345'));

        $response = $this->call_ajax('ajax_get_quotes_for_order', array('order_id' => '906', 'auto' => '1'));

        $this->assertFalse($response->success);
        $this->assertArrayNotHasKey(self::CLAIM, $order->meta);
        $this->assertCount(1, $order->notes);
        $this->assertStringContainsString('"12345"', $order->notes[0]);
    }

    public function test_an_auto_run_with_only_disabled_quotes_stops_and_notes_the_reasons() {
        $this->routes['/public/awb/quotes'] = $this->json(201, array('quoteRequest' => array('id' => 'qr-invented-907'), 'courierQuotes' => array(
            array('id' => 'cq-907', 'courierName' => 'Curier Unu', 'amount' => 0, 'isDisabled' => true, 'error' => array('message' => 'No pickup point configured.')),
        )));
        $order = $this->order(907);

        $response = $this->call_ajax('ajax_get_quotes_for_order', array('order_id' => '907', 'auto' => '1'));

        $this->assertFalse($response->success);
        $this->assertStringContainsString('Curier Unu: No pickup point configured.', $response->data);
        $this->assertStringContainsString('No pickup point configured.', $order->notes[0]);
        $this->assertArrayNotHasKey(self::CLAIM, $order->meta);
    }

    public function test_create_is_refused_when_the_order_has_a_shipment() {
        $order = $this->order(908);
        $order->meta = array(
            '_courier_expedition_id' => 'exp-invented-908',
            '_courier_quote_request_id' => 'qr-invented-908',
            '_courier_selected_quote_id' => 'cq-invented-908',
        );

        $response = $this->call_ajax('ajax_generate_label', array('order_id' => '908'));

        $this->assertFalse($response->success);
        $this->assertStringContainsString('already has a shipment', $response->data);
        $this->assertSame(0, $this->count_requests('/expedition'));
    }

    public function test_auto_create_success_saves_the_shipment_and_drops_the_claim() {
        $order = $this->order(909);
        $order->meta = array(self::CLAIM => (string) time(), '_courier_quote_request_id' => 'qr-invented-909', '_courier_selected_quote_id' => 'cq-invented-909');
        $this->routes['/attach-billing-info-from-sender-profile'] = $this->json(201, array('id' => 'bi-invented-909'));
        $this->routes['/expedition'] = $this->json(201, array('id' => 'exp-invented-909', 'awbNumber' => 'AWB0000000909', 'status' => array('name' => 'Created')));

        $response = $this->call_ajax('ajax_generate_label', array('order_id' => '909', 'auto' => '1'));

        $this->assertTrue($response->success);
        $this->assertSame('exp-invented-909', $order->meta['_courier_expedition_id']);
        $this->assertArrayNotHasKey(self::CLAIM, $order->meta);
        $this->assertSame(1, $this->count_requests('/expedition'));
    }

    public function test_auto_create_refused_by_the_api_releases_the_claim_and_notes_why() {
        $order = $this->order(910);
        $order->meta = array(self::CLAIM => (string) time(), '_courier_quote_request_id' => 'qr-invented-910', '_courier_selected_quote_id' => 'cq-invented-910');
        $this->routes['/attach-billing-info-from-sender-profile'] = $this->json(201, array('id' => 'bi-invented-910'));
        $this->routes['/expedition'] = $this->json(410, array('message' => 'This quote has expired, please get a new one'));

        $response = $this->call_ajax('ajax_generate_label', array('order_id' => '910', 'auto' => '1'));

        $this->assertFalse($response->success);
        $this->assertSame('Could not create the shipment: This quote has expired, please get a new one', $response->data);
        $this->assertArrayNotHasKey(self::CLAIM, $order->meta);
        $this->assertStringContainsString('This quote has expired', $order->notes[0]);
    }

    public function test_no_answer_to_create_keeps_the_claim() {
        $order = $this->order(911);
        $order->meta = array(self::CLAIM => (string) time(), '_courier_quote_request_id' => 'qr-invented-911', '_courier_selected_quote_id' => 'cq-invented-911');
        $this->routes['/attach-billing-info-from-sender-profile'] = $this->json(201, array('id' => 'bi-invented-911'));
        $this->routes['/expedition'] = function() {
            return new WP_Error('http_request_failed', 'Operation timed out');
        };

        $response = $this->call_ajax('ajax_generate_label', array('order_id' => '911', 'auto' => '1'));

        $this->assertFalse($response->success);
        $this->assertArrayHasKey(self::CLAIM, $order->meta, 'the AWB may exist: no second run for 10 minutes');
        $this->assertCount(1, $order->notes);
    }

    public function test_the_js_flag_clearing_action_is_registered() {
        global $wp_test_actions;
        unset($wp_test_actions['wp_ajax_clear_auto_create_flag']);
        new LivrariaPlugin();
        $this->assertArrayHasKey('wp_ajax_clear_auto_create_flag', $wp_test_actions);
    }
}
