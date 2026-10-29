<?php
/**
 * 3a: the merged Sameday + DPD locker list behind the checkout and the order
 * panel: trimmed, filtered, cached 15 minutes, stale on a backend failure,
 * 400 / 502 as the Shopify proxy.
 */

require_once dirname(__DIR__) . '/QuoteFlowTestCase.php';
require_once dirname(__DIR__) . '/LockerFixtures.php';

class Lockers_Proxy_Test extends Livraria_Quote_Flow_TestCase {

    public function setUp(): void {
        parent::setUp();
        $this->routes['/pudo-points/by-courier?courier=sameday'] = $this->json(200, Livraria_Locker_Fixtures::sameday());
        $this->routes['/pudo-points/by-courier?courier=dpd'] = $this->json(200, Livraria_Locker_Fixtures::dpd());
        Livraria_Lockers::forget_cache();
    }

    private function client() {
        return new Livraria_API_Client('https://www.api.livraria.ro');
    }

    private function count_requests($fragment) {
        return count(array_filter($this->requests, function($r) use ($fragment) {
            return strpos($r['url'], $fragment) !== false;
        }));
    }

    public function test_trim_drops_hidden_unofferable_and_nameless_lockers() {
        $lockers = Livraria_Lockers::trim_lockers(Livraria_Locker_Fixtures::sameday(), 'sameday');
        $this->assertSame(array('SD-1001', 'SD-1004', 'SD-1006'), array_column($lockers, 'id'));
        $this->assertSame(array('id' => 'SD-1001', 'name' => 'easybox Exemplu Centru', 'county' => 'Cluj', 'city' => 'Cluj-Napoca',
            'street' => 'Strada Exemplu', 'streetNo' => '10', 'postalCode' => '400100', 'courier' => 'sameday'), $lockers[0]);
        $this->assertSame('DPD-2001', Livraria_Lockers::trim_lockers(Livraria_Locker_Fixtures::dpd(), 'dpd')[0]['id']);
    }

    public function test_the_checkout_gets_one_merged_list_without_postcodes() {
        $answer = Livraria_Lockers::proxy_response($this->client(), '', true);

        $this->assertSame(200, $answer['code']);
        $this->assertSame(array('sameday', 'dpd'), $answer['body']['couriers']);
        $this->assertSame(array('Alfa easybox Gara', 'DPD Locker Exemplu Mall', 'easybox Exemplu Centru', 'easybox Fara Oras'), array_column($answer['body']['lockers'], 'name'));
        $this->assertSame(array('id' => 'SD-1004', 'name' => 'Alfa easybox Gara', 'county' => 'Brasov', 'city' => 'Brasov', 'street' => 'Bulevardul Gării 2', 'courier' => 'sameday'), $answer['body']['lockers'][0]);
        foreach ($answer['body']['lockers'] as $locker) {
            $this->assertSame(array('id', 'name', 'county', 'city', 'street', 'courier'), array_keys($locker));
        }
        // with the merchant's session, server-side
        $this->assertStringContainsString('/pudo-points/by-courier?courier=sameday', $this->requests[0]['url']);
    }

    public function test_the_list_is_cached_for_15_minutes() {
        Livraria_Lockers::proxy_response($this->client(), 'sameday', true);
        $second = Livraria_Lockers::proxy_response($this->client(), 'sameday', true);

        $this->assertSame('hit', $second['cache']);
        $this->assertSame(1, $this->count_requests('courier=sameday'));
    }

    public function test_S10_proxy_answers_400_for_a_courier_it_does_not_offer() {
        foreach (array('fancourier', 'SAMEDAY ; drop', 'x') as $courier) {
            $answer = Livraria_Lockers::proxy_response($this->client(), $courier, true);
            $this->assertSame(400, $answer['code'], $courier);
            $this->assertStringContainsString('Use one of: sameday, dpd', $answer['body']['error']);
        }
        update_option('livraria_lockers_dpd', '0');
        $this->assertSame(400, Livraria_Lockers::proxy_response($this->client(), 'dpd', true)['code'], 'a courier the shop switched off');
        $this->assertCount(0, $this->requests);
    }

    public function test_S10_proxy_answers_502_only_with_nothing_cached() {
        $this->routes['/pudo-points/by-courier?courier=sameday'] = $this->json(500, array('message' => 'down'));
        $this->routes['/pudo-points/by-courier?courier=dpd'] = $this->json(500, array('message' => 'down'));

        $answer = Livraria_Lockers::proxy_response($this->client(), '', true);
        $this->assertSame(502, $answer['code']);
        $this->assertSame('Failed to load lockers', $answer['body']['error']);
    }

    public function test_S10_a_backend_failure_serves_the_last_good_list_as_stale() {
        Livraria_Lockers::proxy_response($this->client(), 'dpd', true);
        delete_transient(Livraria_Lockers::CACHE_FRESH_PREFIX . 'dpd'); // 15 minutes later
        $this->routes['/pudo-points/by-courier?courier=dpd'] = $this->json(503, array('message' => 'down'));

        $answer = Livraria_Lockers::proxy_response($this->client(), 'dpd', true);

        $this->assertSame(200, $answer['code']);
        $this->assertSame('stale', $answer['cache']);
        $this->assertSame('DPD-2001', $answer['body']['lockers'][0]['id']);
    }

    public function test_one_courier_failing_still_serves_the_other() {
        $this->routes['/pudo-points/by-courier?courier=dpd'] = $this->json(500, array('message' => 'down'));

        $answer = Livraria_Lockers::proxy_response($this->client(), '', true);

        $this->assertSame(200, $answer['code']);
        $this->assertSame(array('dpd'), $answer['body']['failed']);
        $this->assertCount(3, $answer['body']['lockers']);
    }

    public function test_logout_login_and_settings_save_drop_the_lists() {
        Livraria_Lockers::proxy_response($this->client(), '', true);
        Livraria_API_Client::clear_session();
        $this->assertFalse(get_transient(Livraria_Lockers::CACHE_FRESH_PREFIX . 'sameday'));
        $this->assertFalse(get_option(Livraria_Lockers::CACHE_STALE_PREFIX . 'sameday', false));

        Livraria_Lockers::proxy_response($this->client(), '', true);
        (new LivrariaPlugin())->forget_sender_profiles_on_settings_save('livraria_lockers_dpd');
        $this->assertFalse(get_transient(Livraria_Lockers::CACHE_FRESH_PREFIX . 'dpd'));
    }

    public function test_the_checkout_handler_answers_without_a_login() {
        global $wp_test_user_caps;
        $wp_test_user_caps = array(); // a guest buyer
        $_REQUEST = array('courier' => 'dpd');
        try {
            (new LivrariaPlugin())->ajax_checkout_lockers();
            $this->fail('no JSON response');
        } catch (Livraria_Test_Json_Response $response) {
            $this->assertTrue($response->success);
            $this->assertSame('DPD-2001', $response->data['lockers'][0]['id']);
        } finally {
            $_REQUEST = array();
        }
        global $wp_test_actions;
        $this->assertArrayHasKey('wp_ajax_nopriv_livraria_checkout_lockers', $wp_test_actions);
    }

    public function test_the_admin_handler_needs_edit_shop_orders_and_keeps_addresses() {
        global $wp_test_user_caps;
        $wp_test_user_caps = array('read');
        try {
            (new LivrariaPlugin())->ajax_admin_lockers();
        } catch (Livraria_Test_Json_Response $response) {
            $this->assertSame(403, $response->status);
        }
        $wp_test_user_caps = array('read', 'edit_shop_orders');
        $_REQUEST = array('courier' => 'sameday');
        try {
            (new LivrariaPlugin())->ajax_admin_lockers();
        } catch (Livraria_Test_Json_Response $response) {
            $this->assertTrue($response->success);
            $this->assertSame('500001', $response->data['lockers'][0]['postalCode']);
        } finally {
            $_REQUEST = array();
            $wp_test_user_caps = array();
        }
    }
}
