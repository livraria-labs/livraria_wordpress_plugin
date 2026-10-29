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
        set_transient('livraria_couriers', array(array('_id' => 'x')), 3600);
        Livraria_API_Client::clear_session();
        $this->assertFalse(get_transient('livraria_couriers'));
        $this->assertFalse(get_transient(Livraria_Lockers::CACHE_FRESH_PREFIX . 'sameday'));
        $this->assertFalse(get_option(Livraria_Lockers::CACHE_STALE_PREFIX . 'sameday', false));

        Livraria_Lockers::proxy_response($this->client(), '', true);
        (new LivrariaPlugin())->forget_sender_profiles_on_settings_save('livraria_lockers_dpd');
        $this->assertFalse(get_transient(Livraria_Lockers::CACHE_FRESH_PREFIX . 'dpd'));
    }

    public function test_the_checkout_handler_answers_without_a_login() {
        global $wp_test_user_caps;
        $wp_test_user_caps = array(); // a guest buyer
        $_REQUEST = array('courier' => 'dpd', 'county' => 'Iasi');
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

    public function test_the_checkout_gets_the_counties_first_never_the_whole_list() {
        $_REQUEST = array();
        try {
            (new LivrariaPlugin())->ajax_checkout_lockers();
            $this->fail('no JSON response');
        } catch (Livraria_Test_Json_Response $response) {
            $this->assertTrue($response->success);
            $this->assertArrayNotHasKey('lockers', $response->data);
            $this->assertSame(array(array('name' => 'Brasov', 'count' => 1), array('name' => 'Cluj', 'count' => 1), array('name' => 'Iasi', 'count' => 1), array('name' => Livraria_Lockers::OTHER_COUNTY, 'count' => 1)), $response->data['counties']);
        } finally {
            $_REQUEST = array();
        }
    }

    public function test_a_county_returns_both_couriers_lockers_of_that_county_from_the_same_cache() {
        $this->routes['/pudo-points/by-courier?courier=dpd'] = $this->json(200, array(
            array('id' => 'DPD-2002', 'name' => 'DPD Locker Brasov Exemplu', 'address' => array('city' => 'Brasov', 'county' => 'Brașov', 'postalCode' => '500002')),
        ));
        $counties = Livraria_Lockers::proxy_response($this->client(), '', true, '');
        $this->assertSame(array(array('name' => 'Brasov', 'count' => 2), array('name' => 'Cluj', 'count' => 1), array('name' => Livraria_Lockers::OTHER_COUNTY, 'count' => 1)), $counties['body']['counties'], 'diacritics fold into one county; the locker with no address is kept in the bucket');

        $brasov = Livraria_Lockers::proxy_response($this->client(), '', true, 'BRAȘOV');
        $this->assertSame(array('SD-1004', 'DPD-2002'), array_column($brasov['body']['lockers'], 'id'));
        $this->assertSame(array('sameday', 'dpd'), array_column($brasov['body']['lockers'], 'courier'));
        $this->assertSame('hit', $brasov['cache']);
        $this->assertSame(1, $this->count_requests('courier=sameday'), 'the county list and the county come from one cached fetch');
        $this->assertSame(1, $this->count_requests('courier=dpd'));
        $this->assertSame(array(), Livraria_Lockers::proxy_response($this->client(), '', true, 'Nicaieri')['body']['lockers']);
    }

    public function test_the_county_answer_stays_small() {
        $many = array();
        for ($i = 0; $i < 4000; $i++) {
            $many[] = array('id' => 'SD-' . $i, 'name' => 'easybox Exemplu ' . $i, 'address' => array('street' => 'Strada Exemplu', 'streetNo' => (string) $i, 'city' => 'Oras ' . ($i % 40), 'county' => 'Judet ' . ($i % 40), 'postalCode' => '100000'));
        }
        $this->routes['/pudo-points/by-courier?courier=sameday'] = $this->json(200, $many);

        $counties = Livraria_Lockers::proxy_response($this->client(), '', true, '');
        $one = Livraria_Lockers::proxy_response($this->client(), '', true, 'Judet 7');

        $this->assertLessThan(4096, strlen(json_encode($counties['body'])));
        $this->assertCount(100, array_filter($one['body']['lockers'], function($l) { return $l['courier'] === 'sameday'; }));
        $this->assertLessThan(20000, strlen(json_encode($one['body'])));
    }

    /** DPD items as the backend sends them: city and postal code, no county, no street */
    private function dpd_without_county() {
        return array(
            array('id' => 'DPD-3001', 'name' => 'BUCURESTI - STRADA EXEMPLU 51 (OUTDOOR LOCKER)', 'address' => array('city' => 'Bucuresti', 'postalCode' => '010011', 'country' => 'Romania')),
            array('id' => 'DPD-3002', 'name' => 'BUCURESTI - BULEVARDUL EXEMPLU 2 (OUTDOOR LOCKER)', 'address' => array('city' => 'Bucuresti', 'postalCode' => '010099', 'country' => 'Romania')),
            array('id' => 'DPD-3003', 'name' => 'NICAIERI - LOCKER EXEMPLU', 'address' => array('city' => 'Sat Inventat', 'postalCode' => '999999', 'country' => 'Romania')),
        );
    }

    private function count_requests_like($fragment) {
        return count(array_filter($this->requests, function($r) use ($fragment) { return strpos($r['url'], $fragment) !== false; }));
    }

    public function test_dpd_lockers_without_a_county_get_it_from_the_postcode_catalogue() {
        $this->routes['/pudo-points/by-courier?courier=dpd'] = $this->json(200, $this->dpd_without_county());
        $this->routes['/public/location/postcodes?postcode=010011'] = $this->json(200, array(array('county' => 'București', 'city' => 'Bucuresti', 'postcode' => '010011')));
        $this->routes['/public/location/postcodes?postcode=999999'] = $this->json(200, array());
        $this->routes['/public/location/cities'] = $this->json(200, array());

        $counties = Livraria_Lockers::proxy_response($this->client(), 'dpd', true, '')['body']['counties'];
        $this->assertSame(array(array('name' => 'BUCURESTI', 'count' => 2), array('name' => Livraria_Lockers::OTHER_COUNTY, 'count' => 1)), $counties);
        $this->assertSame(1, $this->count_requests_like('postcode=0100'), 'one catalogue lookup per postcode prefix');

        $bucuresti = Livraria_Lockers::proxy_response($this->client(), '', true, 'Bucuresti')['body']['lockers'];
        $this->assertSame(array('DPD-3002', 'DPD-3001'), array_column($bucuresti, 'id'));

        $other = Livraria_Lockers::proxy_response($this->client(), '', true, Livraria_Lockers::OTHER_COUNTY)['body']['lockers'];
        $this->assertSame(array('SD-1006', 'DPD-3003'), array_column($other, 'id'), 'an unknown postcode (and a Sameday locker with no address) lands in the fallback bucket, still listed');

        $locker = Livraria_Lockers::find_locker($this->client(), 'dpd', 'DPD-3001');
        $address = Livraria_Order_Handler::locker_recipient_address($locker);
        $this->assertSame('BUCURESTI', $address['county']);
        $this->assertSame('Bucuresti', $address['city']);
        $this->assertSame('010011', $address['postcode']);
        $this->assertSame('BUCURESTI - STRADA EXEMPLU 51 (OUTDOOR LOCKER)', $address['street'], 'DPD puts the street in the name');
    }

    public function test_the_city_resolves_the_county_when_the_postcode_does_not() {
        $this->routes['/pudo-points/by-courier?courier=dpd'] = $this->json(200, array(
            array('id' => 'DPD-3004', 'name' => 'CLUJ - LOCKER EXEMPLU', 'address' => array('city' => 'Cluj-Napoca', 'postalCode' => '400999')),
        ));
        $this->routes['/public/location/postcodes'] = $this->json(200, array());
        $this->routes['/public/location/cities'] = $this->json(200, array(array('name' => 'Cluj-Napoca', 'county' => 'Cluj', 'postcode' => '400001')));

        $lockers = Livraria_Lockers::proxy_response($this->client(), 'dpd', false, null)['body']['lockers'];
        $this->assertSame('CLUJ', $lockers[0]['county']);
    }

    public function test_a_failed_catalogue_lookup_is_not_cached() {
        $this->routes['/pudo-points/by-courier?courier=dpd'] = $this->json(200, $this->dpd_without_county());
        $this->routes['/public/location/postcodes'] = $this->json(503, array('message' => 'down'));
        $this->routes['/public/location/cities'] = $this->json(503, array('message' => 'down'));

        Livraria_Lockers::proxy_response($this->client(), 'dpd', true, '');
        $this->assertFalse(get_transient(Livraria_Lockers::PREFIX_CACHE . '01'));
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
