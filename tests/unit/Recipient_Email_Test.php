<?php
/**
 * The recipient email is optional; "Add email" (and "Add phone") write onto
 * the WooCommerce order, never the Livraria side.
 */

require_once dirname(__DIR__) . '/QuoteFlowTestCase.php';

class Recipient_Email_Test extends Livraria_Quote_Flow_TestCase {

    public function tearDown(): void {
        global $wp_test_user_caps;
        $wp_test_user_caps = array();
        $_POST = array();
        parent::tearDown();
    }

    private function call_ajax($plugin, $method) {
        try {
            $plugin->$method();
        } catch (Livraria_Test_Json_Response $response) {
            return $response;
        }
        $this->fail($method . ' sent no JSON response');
    }

    public function test_an_order_without_email_is_quoted_without_the_key() {
        $recipient = $this->quoted_recipient($this->order(701, array('billing_email' => '  ')));
        $this->assertArrayNotHasKey('email', $recipient);
    }

    public function test_an_email_present_is_sent_trimmed() {
        $recipient = $this->quoted_recipient($this->order(702, array('billing_email' => ' maria@example.com ')));
        $this->assertSame('maria@example.com', $recipient['email']);
    }

    public function test_add_email_writes_the_billing_email_on_the_order() {
        $order = $this->order(703, array('billing_email' => ''));

        $result = $this->handler()->update_order_contact(703, 'email', 'maria@example.com');

        $this->assertTrue($result['success']);
        $this->assertSame('maria@example.com', $order->fields['billing_email']);
        $this->assertSame(1, $order->saves);
        $this->assertCount(1, $order->notes);
        $this->assertCount(0, $this->requests, 'nothing is sent to Livraria');
    }

    public function test_add_email_refuses_an_invalid_address() {
        $order = $this->order(704, array('billing_email' => ''));

        $result = $this->handler()->update_order_contact(704, 'email', 'not-an-email');

        $this->assertFalse($result['success']);
        $this->assertSame('', $order->fields['billing_email']);
        $this->assertSame(0, $order->saves);
    }

    public function test_add_phone_writes_the_field_read_first_and_checks_it() {
        $order = $this->order(705, array('shipping_phone' => '12345', 'billing_phone' => ''));
        $this->assertFalse($this->handler()->update_order_contact(705, 'phone', '999')['success']);
        $this->assertTrue($this->handler()->update_order_contact(705, 'phone', '0700 000 705')['success']);
        $this->assertSame('0700 000 705', $order->fields['shipping_phone']);

        $order = $this->order(706, array('shipping_phone' => '', 'billing_phone' => ''));
        $this->assertTrue($this->handler()->update_order_contact(706, 'phone', '+40700000706')['success']);
        $this->assertSame('+40700000706', $order->fields['billing_phone']);
    }

    public function test_other_fields_cannot_be_filled_in() {
        $this->order(707);
        $this->assertFalse($this->handler()->update_order_contact(707, 'postcode', '400001')['success']);
    }

    public function test_the_ajax_fill_in_needs_edit_shop_orders() {
        global $wp_test_user_caps;
        $order = $this->order(708, array('billing_email' => ''));
        $plugin = new LivrariaPlugin();
        $_POST = array('order_id' => '708', 'field' => 'email', 'value' => 'maria@example.com');

        $wp_test_user_caps = array('read');
        $response = $this->call_ajax($plugin, 'ajax_update_order_contact');
        $this->assertSame(403, $response->status);
        $this->assertSame('', $order->fields['billing_email']);

        $wp_test_user_caps = array('read', 'edit_shop_orders');
        $response = $this->call_ajax($plugin, 'ajax_update_order_contact');
        $this->assertTrue($response->success);
        $this->assertSame('maria@example.com', $order->fields['billing_email']);
    }

    public function test_a_phone_stop_tells_the_panel_to_offer_add_phone() {
        global $wp_test_user_caps;
        $wp_test_user_caps = array('read', 'edit_shop_orders');
        $this->order(709, array('billing_phone' => ''));
        $_POST = array('order_id' => '709');

        $response = $this->call_ajax(new LivrariaPlugin(), 'ajax_get_quotes_for_order');

        $this->assertFalse($response->success);
        $this->assertSame('phone', $response->data['fix']);
        $this->assertStringContainsString('no recipient phone number', $response->data['message']);
    }

    public function test_recipient_fields_required_on_create_offers_add_email() {
        global $wp_test_user_caps;
        $wp_test_user_caps = array('read', 'edit_shop_orders');
        $order = $this->order(710, array('billing_email' => ''));
        $order->meta = array('_courier_quote_request_id' => 'qr-invented-710', '_courier_selected_quote_id' => 'cq-invented-710');
        update_option('livraria_default_sender_profile_id', 'sp-invented-1');
        $this->routes['/attach-billing-info-from-sender-profile'] = $this->json(201, array('id' => 'bi-invented-710'));
        $this->routes['/expedition'] = $this->json(400, array(
            'statusCode' => 400,
            'message' => 'The courier requires the recipient\'s email for this delivery.',
            'code' => 'RECIPIENT_FIELDS_REQUIRED',
            'missingRecipientFields' => array('email'),
        ));
        $_POST = array('order_id' => '710');

        $response = $this->call_ajax(new LivrariaPlugin(), 'ajax_generate_label');

        $this->assertFalse($response->success);
        $this->assertSame('email', $response->data['fix']);
        $this->assertSame('Could not create the shipment: The courier requires the recipient\'s email for this delivery.', $response->data['message']);
    }
}
