<?php
/**
 * Base for tests that run the order -> quote request path against routed,
 * invented API answers. Records every URL and the decoded JSON body sent.
 */

require_once __DIR__ . '/TestCase.php';

abstract class Livraria_Quote_Flow_TestCase extends Livraria_TestCase {

    /** @var array path fragment => response array or callable */
    protected $routes = array();
    /** @var array[] url, method, body (decoded) */
    protected $requests = array();

    public function setUp(): void {
        parent::setUp();
        $GLOBALS['wp_test_orders'] = array();
        $GLOBALS['wp_test_post_meta'] = array();
        $GLOBALS['wp_test_transients'] = array();
        $GLOBALS['wp_test_ro_states'] = array();
        $this->requests = array();
        $this->routes = array(
            '/public/couriers' => $this->json(200, array(array('_id' => 'courier-invented-1', 'name' => 'Curier Exemplu'))),
            '/users/me/sender-profile' => $this->json(200, array(
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
            )),
            '/public/awb/quotes' => $this->json(201, array(
                'quoteRequest' => array('id' => 'qr-invented-1'),
                'courierQuotes' => array(
                    array('id' => 'cq-invented-1', 'courierName' => 'Curier Exemplu', 'amount' => 21.5, 'currency' => 'RON'),
                ),
            )),
        );
        $routes = &$this->routes;
        $requests = &$this->requests;
        add_filter('pre_http_request', function($preempt, $args, $url) use (&$routes, &$requests) {
            $requests[] = array(
                'url' => $url,
                'method' => $args['method'] ?? 'GET',
                'body' => isset($args['body']) ? json_decode($args['body'], true) : null,
            );
            // Longest fragment first, so /public/awb/quotes beats /public
            $fragments = array_keys($routes);
            usort($fragments, function($a, $b) { return strlen($b) - strlen($a); });
            foreach ($fragments as $fragment) {
                if (strpos($url, $fragment) !== false) {
                    $response = $routes[$fragment];
                    return is_callable($response) ? $response($url, $args) : $response;
                }
            }
            return new WP_Error('mocked', 'No route in test for ' . $url);
        }, 10, 3);
        update_option('livraria_api_token', 'invented-access-token-2');
        update_option('livraria_token_expires_at', time() + 3600);
    }

    protected function json($code, $body) {
        return array(
            'response' => array('code' => $code, 'message' => ''),
            'body' => is_string($body) ? $body : json_encode($body),
            'headers' => array(),
        );
    }

    protected function handler() {
        return new Livraria_Order_Handler(new Livraria_API_Client('https://www.api.livraria.ro'));
    }

    protected function order($id = 101, array $fields = array()) {
        $order = new Livraria_Fake_Order($id, $fields);
        $GLOBALS['wp_test_orders'][$id] = $order;
        return $order;
    }

    /** The JSON body of the last request whose URL contains $fragment, or null */
    protected function sent($fragment) {
        for ($i = count($this->requests) - 1; $i >= 0; $i--) {
            if (strpos($this->requests[$i]['url'], $fragment) !== false) {
                return $this->requests[$i]['body'];
            }
        }
        return null;
    }

    /** The recipient block sent with the quote request for $order */
    protected function quoted_recipient($order, array $custom = array()) {
        $result = $this->handler()->get_quotes_for_order($order->get_id(), $custom);
        $this->assertTrue($result['success'], $result['message'] ?? '');
        return $this->sent('/public/awb/quotes')['recipient'];
    }
}
