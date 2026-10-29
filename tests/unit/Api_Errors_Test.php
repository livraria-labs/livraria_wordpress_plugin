<?php
/**
 * Every non-2xx answer reaches the merchant as the backend's own words, and
 * the details an order screen acts on (code, missingRecipientFields, reason)
 * stay on the client's last error. Bodies are invented, shaped like the
 * backend's NestJS envelopes. No request leaves the test.
 */

require_once dirname(__DIR__) . '/TestCase.php';

class Api_Errors_Test extends Livraria_TestCase {

    /** @var array path fragment => response */
    private $routes = array();
    /** @var string[] URLs requested */
    private $requests = array();

    public function setUp(): void {
        parent::setUp();
        $this->routes = array();
        $this->requests = array();
        $routes = &$this->routes;
        $requests = &$this->requests;
        add_filter('pre_http_request', function($preempt, $args, $url) use (&$routes, &$requests) {
            $requests[] = $url;
            foreach ($routes as $fragment => $response) {
                if (strpos($url, $fragment) !== false) {
                    return is_callable($response) ? $response() : $response;
                }
            }
            return new WP_Error('mocked', 'Stopped by test');
        }, 10, 3);
        update_option('livraria_api_token', 'invented-access-token-1');
        update_option('livraria_token_expires_at', time() + 3600);
    }

    private function json($code, $body) {
        return array(
            'response' => array('code' => $code, 'message' => ''),
            'body' => is_string($body) ? $body : json_encode($body),
            'headers' => array(),
        );
    }

    private function client() {
        return new Livraria_API_Client('https://www.api.livraria.ro');
    }

    public function test_string_message_is_the_merchant_message() {
        $this->routes['/public/awb/quotes'] = $this->json(400, array('statusCode' => 400, 'message' => 'packageDescription must be shorter than or equal to 50 characters'));
        $client = $this->client();

        $this->assertFalse($client->create_quote_request(array('externalRef' => 'WP-shop-1')));
        $this->assertSame('packageDescription must be shorter than or equal to 50 characters', $client->last_error_message());
        $this->assertSame(400, $client->last_error_status());
    }

    public function test_validation_list_gives_its_first_non_blank_line() {
        $this->routes['/public/awb/quotes'] = $this->json(400, array(
            'statusCode' => 400,
            'message' => array('', '  ', 'recipient.phoneNSN must match /^[723]\\d{8}$/', 'recipient.name should not be empty'),
            'error' => 'Bad Request',
        ));
        $client = $this->client();

        $client->create_quote_request(array());
        $this->assertSame('recipient.phoneNSN must match /^[723]\\d{8}$/', $client->last_error_message());
    }

    public function test_nested_envelope_error_wins_and_keeps_its_code() {
        $this->routes['/expedition'] = $this->json(403, array(
            'statusCode' => 403,
            'message' => array('success' => false, 'error' => 'Billing is locked for this account until the open invoice is paid.', 'code' => 'BILLING_LOCKED'),
            'error' => 'Forbidden',
        ));
        $client = $this->client();

        $this->assertFalse($client->create_expedition(array('quoteRequestId' => 'qr-invented-1')));
        $error = $client->get_last_error();
        $this->assertSame('Billing is locked for this account until the open invoice is paid.', $error['message']);
        $this->assertSame('BILLING_LOCKED', $error['code']);
        $this->assertSame(403, $error['status']);
    }

    public function test_top_level_error_when_message_is_missing() {
        $this->routes['/users/me'] = $this->json(500, array('statusCode' => 500, 'error' => 'Internal Server Error'));
        $client = $this->client();

        $client->get_user_info();
        $this->assertSame('Internal Server Error', $client->last_error_message());
    }

    public function test_canned_lines_when_the_body_says_nothing() {
        $cases = array(
            403 => 'The Livraria API refused this request (HTTP 403).',
            404 => 'The Livraria API could not find what this request asked for (HTTP 404).',
            429 => 'Too many requests to the Livraria API. Wait a moment and try again.',
            502 => 'The Livraria API returned HTTP 502.',
        );
        foreach ($cases as $code => $expected) {
            $this->routes = array('/users/me' => $this->json($code, ''));
            $client = $this->client();
            $client->get_user_info();
            $this->assertSame($expected, $client->last_error_message(), 'HTTP ' . $code);
        }
        $this->assertSame('The Livraria session is no longer valid. Log in again under Settings > Livraria.', Livraria_API_Client::message_from_error_body(null, 401));
    }

    public function test_recipient_fields_required_keeps_code_and_fields() {
        $this->routes['/expedition'] = $this->json(400, array(
            'statusCode' => 400,
            'message' => 'The courier requires the recipient\'s email for this delivery.',
            'code' => 'RECIPIENT_FIELDS_REQUIRED',
            'missingRecipientFields' => array('email'),
        ));
        $client = $this->client();

        $client->create_expedition(array('quoteRequestId' => 'qr-invented-2'));
        $error = $client->get_last_error();
        $this->assertSame('RECIPIENT_FIELDS_REQUIRED', $client->last_error_code());
        $this->assertSame(array('email'), $error['missingRecipientFields']);
        $this->assertSame('The courier requires the recipient\'s email for this delivery.', $error['message']);
    }

    public function test_courier_not_active_410_is_the_backends_words() {
        $this->routes['/selected-courier-quote'] = $this->json(410, array('statusCode' => 410, 'message' => 'This courier is not active right now.', 'code' => 'COURIER_NOT_ACTIVE'));
        $client = $this->client();

        $this->assertFalse($client->select_courier_quote('qr-invented-3', 'cq-invented-3'));
        $this->assertSame('This courier is not active right now.', $client->last_error_message());
        $this->assertSame('COURIER_NOT_ACTIVE', $client->last_error_code());
    }

    public function test_401_refresh_expired_keeps_the_reconnect_behaviour() {
        $client = $this->client();
        $client->set_encrypted_option(Livraria_API_Client::OPT_REFRESH_TOKEN, 'invented-refresh-token-9');
        $this->routes['/auth/refresh'] = $this->json(401, array('statusCode' => 401, 'message' => array('success' => false, 'error' => 'Refresh token expired', 'reason' => 'REFRESH_EXPIRED')));
        $this->routes['/public/awb/quotes'] = $this->json(401, array('statusCode' => 401, 'message' => 'Unauthorized'));

        $this->assertFalse($client->create_quote_request(array()));

        $this->assertTrue(Livraria_API_Client::is_session_expired());
        $error = $client->get_last_error();
        $this->assertSame(Livraria_API_Client::SESSION_EXPIRED_MESSAGE, $error['message']);
        $this->assertSame(401, $error['status']);

        // The next call does not reach the API and says the same
        $count = count($this->requests);
        $this->assertFalse($this->client()->get_sender_profile());
        $this->assertCount($count, $this->requests);
    }

    public function test_expired_session_before_the_call_names_the_reconnect() {
        update_option(Livraria_API_Client::OPT_SESSION_STATE, 'expired');
        update_option('livraria_token_expires_at', time() - 10);
        $client = $this->client();

        $this->assertFalse($client->create_quote_request(array()));
        $error = $client->get_last_error();
        $this->assertSame(Livraria_API_Client::SESSION_EXPIRED_MESSAGE, $error['message']);
        $this->assertSame('REFRESH_EXPIRED', $error['reason']);
        $this->assertCount(0, $this->requests);
    }

    public function test_no_response_says_so() {
        $client = $this->client();

        $this->assertFalse($client->get_user_info());
        $this->assertSame('No response from the Livraria API (Stopped by test).', $client->last_error_message());
        $this->assertSame(0, $client->last_error_status());
    }

    public function test_a_success_clears_the_last_error() {
        $client = $this->client();
        $this->routes['/users/me'] = $this->json(500, array('message' => 'boom'));
        $client->get_user_info();
        $this->assertSame('boom', $client->last_error_message());

        $this->routes['/users/me'] = $this->json(200, array('id' => 'u-invented-1'));
        $this->assertSame(array('id' => 'u-invented-1'), $client->get_user_info());
        $this->assertNull($client->get_last_error());
    }

    public function test_pdf_download_failure_keeps_the_backends_words() {
        $this->routes['/public/awb/print/'] = $this->json(404, array('statusCode' => 404, 'message' => 'AWB not found for this account'));
        $client = $this->client();

        $this->assertFalse($client->download_awb_pdf('AWB0000000009'));
        $this->assertSame('AWB not found for this account', $client->last_error_message());
    }

    public function test_login_failure_reads_a_validation_list_as_text() {
        $this->routes['/auth/login'] = $this->json(400, array('message' => array('email must be an email')));
        $result = $this->client()->login('not-an-email', 'invented-password-3');
        $this->assertSame('Login failed: email must be an email', $result['message']);
    }

    public function test_order_handler_prefixes_what_failed() {
        $this->routes['/public/awb/quotes'] = $this->json(400, array('message' => 'sender.address.county should not be empty'));
        $client = $this->client();
        $handler = new Livraria_Order_Handler($client);
        $client->create_quote_request(array());

        $this->assertSame('Could not get quotes: sender.address.county should not be empty', $handler->api_error_message('Could not get quotes'));
        $client->clear_last_error();
        $this->assertSame('Could not get quotes: the Livraria API did not say why', $handler->api_error_message('Could not get quotes'));
    }

    public function test_select_quote_ajax_shows_the_backends_words() {
        global $wp_test_user_caps;
        $wp_test_user_caps = array('read', 'edit_shop_orders');
        $this->routes['/selected-courier-quote'] = $this->json(400, array('message' => 'This quote is disabled and cannot be selected.'));
        update_option('courier_api_base_url', Livraria_API_Client::PRODUCTION_API_URL);
        $plugin = new LivrariaPlugin();
        $_POST = array('order_id' => '101', 'quote_request_id' => 'qr-invented-4', 'courier_quote_id' => 'cq-invented-4');

        try {
            $plugin->ajax_select_quote();
            $this->fail('no JSON response');
        } catch (Livraria_Test_Json_Response $response) {
            $this->assertFalse($response->success);
            $this->assertSame('Could not select the quote: This quote is disabled and cannot be selected.', $response->data);
        } finally {
            $_POST = array();
            $wp_test_user_caps = array();
        }
    }
}
