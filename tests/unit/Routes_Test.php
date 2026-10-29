<?php
/**
 * 3c: the merchant's Route in the order panel: the buyer's pick, origin and
 * destination lockers, one quote request per route, tabs, banners, the
 * recipient per route. Invented lockers, couriers and orders.
 */

require_once dirname(__DIR__) . '/QuoteFlowTestCase.php';
require_once dirname(__DIR__) . '/LockerFixtures.php';

class Routes_Test extends Livraria_Quote_Flow_TestCase {

    /** @var array route => quote the fake backend answers */
    private $answers = array();

    public function setUp(): void {
        parent::setUp();
        Livraria_Lockers::forget_cache();
        $this->routes['/public/couriers'] = $this->json(200, Livraria_Locker_Fixtures::couriers());
        $this->routes['/pudo-points/by-courier?courier=sameday'] = $this->json(200, Livraria_Locker_Fixtures::sameday());
        $this->routes['/pudo-points/by-courier?courier=dpd'] = $this->json(200, Livraria_Locker_Fixtures::dpd());
        $this->answers = array(
            'DOOR_TO_DOOR' => array('amount' => 21.5),
            'DOOR_TO_LOCKER' => array('amount' => 14.0),
            'LOCKER_TO_DOOR' => array('amount' => 17.0),
            'LOCKER_TO_LOCKER' => array('amount' => 12.0),
        );
        $answers = &$this->answers;
        $test = $this;
        $this->routes['/public/awb/quotes'] = function($url, $args) use (&$answers, $test) {
            $body = json_decode($args['body'], true);
            $type = (isset($body['originLockerId']) ? 'LOCKER' : 'DOOR') . '_TO_' . (isset($body['destinationLockerId']) ? 'LOCKER' : 'DOOR');
            $quote = array_merge(array('id' => 'cq-' . $type, 'courierName' => 'Curier Exemplu', 'currency' => 'RON', 'deliveryType' => $type), $answers[$type]);
            return $test->json_answer(201, array('quoteRequest' => array('id' => 'qr-' . $type), 'courierQuotes' => array($quote)));
        };
    }

    public function json_answer($code, $body) {
        return $this->json($code, $body);
    }

    private function quote_calls() {
        return array_values(array_filter($this->requests, function($r) {
            return strpos($r['url'], '/public/awb/quotes') !== false;
        }));
    }

    private function call_for($type) {
        foreach ($this->quote_calls() as $call) {
            $t = (isset($call['body']['originLockerId']) ? 'LOCKER' : 'DOOR') . '_TO_' . (isset($call['body']['destinationLockerId']) ? 'LOCKER' : 'DOOR');
            if ($t === $type) {
                return $call['body'];
            }
        }
        return null;
    }

    private function picked_order($id, $courier, $locker_id, $name = 'Locker ales', array $fields = array()) {
        $order = $this->order($id, $fields);
        $order->meta = array(
            '_livraria_delivery_mode' => 'locker',
            '_livraria_locker_id' => $locker_id,
            '_livraria_locker_name' => $name,
            '_livraria_locker_courier' => $courier,
        );
        return $order;
    }

    private function quote($order_id, array $route = array()) {
        return $this->handler()->get_quotes_for_order($order_id, $route ? array('route' => LivrariaPlugin::sanitize_route($route)) : array());
    }

    // --- S6: admin quote per courier -------------------------------------------------

    public function test_S6_admin_quote_per_courier_sends_the_buyers_locker_with_its_courier_id() {
        $this->picked_order(1401, 'sameday', 'SD-1001', 'easybox Exemplu Centru');
        $result = $this->quote(1401);

        $this->assertTrue($result['success'], $result['message']);
        $d2l = $this->call_for('DOOR_TO_LOCKER');
        $this->assertSame('SD-1001', $d2l['destinationLockerId']);
        $this->assertSame('courier-sameday-id', $d2l['destinationCourierId']);
        // the locker's address, the person's name and phone
        $this->assertSame(array('country' => 'Romania', 'county' => 'Cluj', 'city' => 'Cluj-Napoca', 'postcode' => '400100', 'street' => 'Strada Exemplu', 'streetNumber' => '10',
            'block' => '', 'staircase' => '', 'floor' => '', 'apartment' => ''), $d2l['recipient']['address']);
        $this->assertSame('Ioana Exemplu', $d2l['recipient']['name']);
        $this->assertSame('700000101', $d2l['recipient']['phoneNSN']);
        // door delivery is quoted too, to the order address, without lockers
        $d2d = $this->call_for('DOOR_TO_DOOR');
        $this->assertArrayNotHasKey('destinationLockerId', $d2d);
        $this->assertSame('Strada Florilor 12', $d2d['recipient']['address']['street']);
        // each quote carries its own route's quote request; the unattended pick ships to the locker
        $byId = array_column($result['quotes'], null, 'id');
        $this->assertSame('qr-DOOR_TO_LOCKER', $byId['cq-DOOR_TO_LOCKER']['quoteRequestId']);
        $this->assertSame('qr-DOOR_TO_DOOR', $byId['cq-DOOR_TO_DOOR']['quoteRequestId']);
        $this->assertSame('cq-DOOR_TO_LOCKER', $result['autoQuoteId']);
        $this->assertSame('qr-DOOR_TO_LOCKER', $result['quoteRequestId']);
        $this->assertSame('buyer', $result['banner']['type']);
    }

    public function test_S6_a_dpd_pick_resolves_the_dpd_courier_by_slug() {
        $this->picked_order(1402, 'dpd', 'DPD-2001', 'DPD Locker Exemplu Mall');
        $this->quote(1402);
        $d2l = $this->call_for('DOOR_TO_LOCKER');
        $this->assertSame('DPD-2001', $d2l['destinationLockerId']);
        $this->assertSame('courier-dpd-id', $d2l['destinationCourierId']);
        $this->assertSame('Iasi', $d2l['recipient']['address']['city']);
    }

    // --- S7: no pick ------------------------------------------------------------------

    public function test_S7_no_pick_quotes_door_to_door_only() {
        $this->order(1403);
        $result = $this->quote(1403);

        $this->assertCount(1, $this->quote_calls());
        $this->assertArrayNotHasKey('destinationLockerId', $this->quote_calls()[0]['body']);
        $this->assertArrayNotHasKey('originLockerId', $this->quote_calls()[0]['body']);
        $this->assertNull($result['banner']);
        $this->assertSame('cq-DOOR_TO_DOOR', $result['autoQuoteId']);
    }

    public function test_S7_a_home_order_with_stale_locker_ids_is_no_pick() {
        $order = $this->picked_order(1404, 'sameday', 'SD-1001');
        $order->meta['_livraria_delivery_mode'] = 'home';
        $this->assertNull(Livraria_Order_Handler::read_buyer_pick($order));
    }

    // --- S8: unusable pick ----------------------------------------------------------------

    public function test_S8_a_pick_with_no_courier_is_named_never_assumed_sameday() {
        $this->picked_order(1405, '', 'SD-1001', 'easybox Exemplu Centru');
        $result = $this->quote(1405);

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['quotes']);
        $this->assertStringContainsString('no courier recorded', $result['blocked']);
        $this->assertSame('unusable', $result['banner']['type']);
        $this->assertCount(0, $this->quote_calls(), 'nothing is quoted until the merchant decides');
    }

    public function test_S8_an_unsupported_courier_is_named_as_written() {
        $this->picked_order(1406, 'FanCourier', 'FAN-1', 'FANbox Exemplu');
        $result = $this->quote(1406);

        $this->assertStringContainsString("courier 'FanCourier'", $result['blocked']);
        $this->assertStringContainsString('unsupported courier', $result['blocked']);
        $this->assertCount(0, $this->quote_calls());
    }

    public function test_S8_ship_to_the_order_address_instead_quotes_door_delivery() {
        $this->picked_order(1407, '', 'SD-1001');
        $result = $this->quote(1407, array('destination' => array('mode' => 'order')));

        $this->assertSame('', $result['blocked']);
        $this->assertSame('cq-DOOR_TO_DOOR', $result['autoQuoteId']);
        $this->assertNull($this->call_for('DOOR_TO_LOCKER'));
    }

    public function test_S8_the_unattended_run_stops_on_an_unusable_pick_and_says_why() {
        $order = $this->picked_order(1408, '', 'SD-1001', 'easybox Exemplu Centru');
        $result = $this->handler()->get_quotes_for_order(1408, array(), true);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no courier recorded', $result['message']);
        $this->assertStringContainsString('no courier recorded', $order->notes[0]);
        $this->assertCount(0, $this->quote_calls());
    }

    // --- S9: disabled quote with reason ---------------------------------------------------

    public function test_S9_a_disabled_locker_quote_keeps_its_reason_and_offers_add_email() {
        $this->answers['DOOR_TO_LOCKER'] = array('amount' => 0, 'isDisabled' => true, 'error' => array(
            'code' => 'RECIPIENT_FIELDS_REQUIRED', 'message' => 'Sameday needs the recipient\'s email for a locker delivery.', 'missingRecipientFields' => array('email')));
        $this->picked_order(1409, 'sameday', 'SD-1001', 'easybox Exemplu Centru', array('billing_email' => ''));

        $result = $this->quote(1409);

        $byId = array_column($result['quotes'], null, 'id');
        $locker = $byId['cq-DOOR_TO_LOCKER'];
        $this->assertFalse($locker['usable']);
        $this->assertTrue($locker['needsEmail']);
        $this->assertSame('Sameday needs the recipient\'s email for a locker delivery.', $locker['disabledReason']);
        $this->assertSame('cq-DOOR_TO_DOOR', $result['quotes'][0]['id'], 'usable first');
        $this->assertNull($result['autoQuoteId'], 'the unattended run never ships a disabled quote, nor door delivery against the buyer\'s pick');

        $tabs = array_column($result['tabs'], null, 'key');
        $this->assertSame(1, $tabs['DOOR_TO_LOCKER']['count']);
        $this->assertTrue($tabs['DOOR_TO_DOOR']['cheapest']);
        $this->assertFalse($tabs['DOOR_TO_LOCKER']['cheapest']);
        $this->assertFalse($tabs['ALL']['cheapest']);
    }

    // --- S11: locker origin --------------------------------------------------------------

    public function test_S11_locker_origin_quotes_every_route_with_its_ends() {
        $this->picked_order(1410, 'sameday', 'SD-1001', 'easybox Exemplu Centru');
        $result = $this->quote(1410, array(
            'origin' => array('mode' => 'locker', 'locker' => array('id' => 'DPD-2001', 'courier' => 'dpd', 'name' => 'DPD Locker Exemplu Mall')),
            'destination' => array('mode' => 'buyer'),
        ));

        $this->assertCount(4, $this->quote_calls());
        $l2d = $this->call_for('LOCKER_TO_DOOR');
        $this->assertSame('DPD-2001', $l2d['originLockerId']);
        $this->assertSame('courier-dpd-id', $l2d['originCourierId']);
        $this->assertArrayNotHasKey('clientId', $l2d['sender']);
        $l2l = $this->call_for('LOCKER_TO_LOCKER');
        $this->assertSame('SD-1001', $l2l['destinationLockerId']);
        $this->assertSame('DPD-2001', $l2l['originLockerId']);

        $tabs = array_column($result['tabs'], null, 'key');
        $this->assertTrue($tabs['LOCKER_TO_LOCKER']['cheapest']);
        $this->assertSame(4, $tabs['ALL']['count']);
    }

    public function test_S11_the_nearest_supported_locker_is_suggested_for_the_origin() {
        $this->order(1411);
        $this->routes['/pudo-points/closest'] = $this->json(200, array(
            array('id' => 'CG-1', 'name' => 'Cargus Ship&Go', 'provider' => 'Cargus', 'address' => array('city' => 'Bucuresti')),
            array('id' => 'SD-3001', 'name' => 'easybox Exemplu Nord', 'provider' => 'Sameday', 'address' => array('city' => 'Bucuresti', 'street' => 'Strada Nord')),
        ));

        $suggestion = $this->handler()->suggest_locker(1411, 'origin');

        $this->assertSame('SD-3001', $suggestion['locker']['id']);
        $this->assertSame('sameday', $suggestion['locker']['courier']);
        $this->assertStringContainsString('Strada Exemplu 1', $suggestion['nearTo'], 'near the sender address');
        $closest = array_values(array_filter($this->requests, function($r) { return strpos($r['url'], '/pudo-points/closest') !== false; }));
        $this->assertStringContainsString('limit=10', $closest[0]['url']);
    }

    // --- S12: changed-destination banner ------------------------------------------------

    public function test_S12_changed_destination_banner_names_the_buyers_locker() {
        $this->picked_order(1412, 'sameday', 'SD-1001', 'easybox Exemplu Centru');

        $to_address = $this->quote(1412, array('destination' => array('mode' => 'order')));
        $this->assertSame('changed', $to_address['banner']['type']);
        $this->assertSame("You changed the destination. The buyer chose easybox Sameday easybox Exemplu Centru at checkout; this shipment goes to the order's shipping address.", $to_address['banner']['message']);
        $this->assertNull($this->call_for('DOOR_TO_LOCKER'));

        $to_other = $this->quote(1412, array('destination' => array('mode' => 'locker', 'locker' => array('id' => 'DPD-2001', 'courier' => 'dpd', 'name' => 'DPD Locker Exemplu Mall'))));
        $this->assertStringContainsString('this shipment goes to locker DPD DPD Locker Exemplu Mall.', $to_other['banner']['message']);
        $this->assertSame('DPD-2001', $this->call_for('DOOR_TO_LOCKER')['destinationLockerId']);
    }

    // --- units -------------------------------------------------------------------------

    public function test_courier_id_resolves_by_slug_then_name() {
        $couriers = array(
            array('id' => 'c-1', 'slug' => 'sameday', 'name' => 'Sameday Courier'),
            array('_id' => 'c-2', 'slug' => 'dpd-ro', 'name' => 'DPD'),
            array('slug' => 'gls', 'name' => 'GLS'),
        );
        $this->assertSame('c-1', Livraria_Order_Handler::resolve_courier_id($couriers, 'SameDay'));
        $this->assertSame('c-2', Livraria_Order_Handler::resolve_courier_id($couriers, 'dpd'));
        $this->assertNull(Livraria_Order_Handler::resolve_courier_id($couriers, 'gls'), 'no id');
        $this->assertNull(Livraria_Order_Handler::resolve_courier_id($couriers, ''));
        $this->assertNull(Livraria_Order_Handler::resolve_courier_id(null, 'dpd'));
    }

    public function test_a_courier_livraria_does_not_list_disables_its_route_without_guessing() {
        $this->routes['/public/couriers'] = $this->json(200, array(array('_id' => 'courier-dpd-id', 'slug' => 'dpd')));
        $this->picked_order(1413, 'sameday', 'SD-1001', 'easybox Exemplu Centru');
        $result = $this->quote(1413);

        $row = array_values(array_filter($result['quotes'], function($q) { return $q['deliveryType'] === 'DOOR_TO_LOCKER'; }))[0];
        $this->assertFalse($row['usable']);
        $this->assertSame('Sameday is not available in Livraria right now, so easybox Sameday easybox Exemplu Centru cannot be used.', $row['disabledReason']);
        $this->assertNull($this->call_for('DOOR_TO_LOCKER'));
    }

    public function test_a_locker_without_a_city_stops_its_route() {
        $this->assertNull(Livraria_Order_Handler::locker_recipient_address(array('id' => 'SD-1006', 'street' => 'Strada Necunoscuta')));
        $this->picked_order(1414, 'sameday', 'SD-1006', 'easybox Fara Oras');
        $result = $this->quote(1414);

        $row = array_values(array_filter($result['quotes'], function($q) { return $q['deliveryType'] === 'DOOR_TO_LOCKER'; }))[0];
        $this->assertSame('LOCKER_ADDRESS_UNKNOWN', $row['errorCode']);
        $this->assertNull($this->call_for('DOOR_TO_LOCKER'));
        $this->assertNotNull($this->call_for('DOOR_TO_DOOR'));
    }

    public function test_door_routes_without_a_postcode_offer_add_postcode_and_lockers_still_quote() {
        $this->picked_order(1415, 'sameday', 'SD-1001', 'easybox Exemplu Centru', array('billing_postcode' => ''));
        $result = $this->quote(1415);

        $row = array_values(array_filter($result['quotes'], function($q) { return $q['deliveryType'] === 'DOOR_TO_DOOR'; }))[0];
        $this->assertSame('ORDER_ADDRESS_INCOMPLETE', $row['errorCode']);
        $this->assertSame('postcode', $row['needsField']);
        $this->assertNull($this->call_for('DOOR_TO_DOOR'));
        $this->assertSame('cq-DOOR_TO_LOCKER', $result['autoQuoteId']);

        $order = $GLOBALS['wp_test_orders'][1415];
        $this->assertFalse($this->handler()->update_order_contact(1415, 'postcode', '4000')['success']);
        $this->assertTrue($this->handler()->update_order_contact(1415, 'postcode', '400001')['success']);
        $this->assertSame('400001', $order->fields['billing_postcode']);
        $this->assertSame(array(), $this->handler()->missing_door_fields($order));
    }

    public function test_tab_ties_go_to_the_first_route() {
        $quotes = Livraria_Order_Handler::normalize_quotes(array(
            array('id' => 'a', 'amount' => 10, 'deliveryType' => 'LOCKER_TO_DOOR'),
            array('id' => 'b', 'amount' => 10, 'deliveryType' => 'DOOR_TO_LOCKER'),
            array('id' => 'c', 'amount' => 5, 'deliveryType' => 'DOOR_TO_DOOR', 'isDisabled' => true),
        ));
        $tabs = array_column(Livraria_Order_Handler::quote_tabs($quotes), 'cheapest', 'key');
        $this->assertSame(array('ALL' => false, 'DOOR_TO_DOOR' => false, 'DOOR_TO_LOCKER' => true, 'LOCKER_TO_DOOR' => false, 'LOCKER_TO_LOCKER' => false), $tabs);
    }

    public function test_the_create_path_uses_the_selected_routes_quote_request() {
        global $wp_test_user_caps;
        $wp_test_user_caps = array('read', 'edit_shop_orders');
        $order = $this->picked_order(1416, 'sameday', 'SD-1001');
        update_option('livraria_default_sender_profile_id', 'sp-invented-1');
        $this->routes['/selected-courier-quote'] = $this->json(200, array('id' => 'qr-DOOR_TO_LOCKER'));
        $this->routes['/attach-billing-info-from-sender-profile'] = $this->json(201, array('id' => 'bi-1416'));
        $this->routes['/expedition'] = $this->json(201, array('id' => 'exp-1416', 'awbNumber' => 'AWB0000001416'));
        $plugin = new LivrariaPlugin();

        foreach (array(
            array('ajax_select_quote', array('order_id' => '1416', 'quote_request_id' => 'qr-DOOR_TO_LOCKER', 'courier_quote_id' => 'cq-DOOR_TO_LOCKER')),
            array('ajax_generate_label', array('order_id' => '1416')),
        ) as $call) {
            $_POST = $call[1];
            try {
                $plugin->{$call[0]}();
            } catch (Livraria_Test_Json_Response $response) {
                $this->assertTrue($response->success, $call[0]);
            }
        }
        $_POST = array();
        $wp_test_user_caps = array();
        $this->assertSame(array('quoteRequestId' => 'qr-DOOR_TO_LOCKER'), $this->sent('/expedition'));
        $this->assertStringContainsString('/quote-request/qr-DOOR_TO_LOCKER/selected-courier-quote', implode(' ', array_column($this->requests, 'url')));
        $this->assertSame('exp-1416', $order->meta['_courier_expedition_id']);
    }

    public function test_environment_badge() {
        $this->assertSame('', LivrariaPlugin::environment_badge('https://www.api.livraria.ro/'));
        $this->assertSame('', LivrariaPlugin::environment_badge('https://api.livraria.ro'));
        $this->assertSame('UAT', LivrariaPlugin::environment_badge('https://www.api-uat.livraria.ro/'));
        $this->assertSame('DEV', LivrariaPlugin::environment_badge('http://localhost:3000/'));
    }

    public function test_the_posted_route_is_cleaned() {
        $route = LivrariaPlugin::sanitize_route(array(
            'origin' => array('mode' => 'locker', 'locker' => array('id' => '<b>SD-1</b>', 'courier' => 'SAMEDAY', 'name' => 'x')),
            'destination' => array('mode' => 'somewhere'),
        ));
        $this->assertSame(array('id' => 'SD-1', 'courier' => 'sameday', 'name' => 'x'), $route['origin']['locker']);
        $this->assertSame('buyer', $route['destination']['mode']);
        $this->assertSame('', LivrariaPlugin::sanitize_route(array('origin' => array('mode' => 'locker', 'locker' => array('id' => '1', 'courier' => 'cargus'))))['origin']['locker']['courier']);
    }

    public function test_the_panel_renders_the_route_from_the_order_without_an_api_call() {
        $this->picked_order(1417, 'sameday', 'SD-1001', 'easybox Exemplu Centru');
        $post = new stdClass();
        $post->ID = 1417;
        ob_start();
        (new LivrariaPlugin())->expedition_meta_box_callback($post);
        $html = ob_get_clean();

        $this->assertStringContainsString('id="livraria-origin-mode"', $html);
        $this->assertStringContainsString('The buyer&#039;s pick: easybox Sameday easybox Exemplu Centru', $html);
        $this->assertStringContainsString('livrare la locker', $html);
        $this->assertCount(0, $this->requests);
    }
}
