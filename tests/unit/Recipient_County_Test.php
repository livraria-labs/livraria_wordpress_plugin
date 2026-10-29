<?php
/**
 * County and city come from the courier catalogue (GET /public/location/cities,
 * then /postcodes); the WooCommerce state is a hint and the fallback.
 * Catalogue rows are invented, shaped like the backend's.
 */

require_once dirname(__DIR__) . '/QuoteFlowTestCase.php';

class Recipient_County_Test extends Livraria_Quote_Flow_TestCase {

    public function setUp(): void {
        parent::setUp();
        $GLOBALS['wp_test_ro_states'] = array(
            'CJ' => 'Cluj',
            'VL' => 'Vâlcea',
            'DB' => 'Dâmbovița',
            'SM' => 'Satu Mare',
            'AG' => 'Argeș',
        );
    }

    private function count_requests($fragment) {
        return count(array_filter($this->requests, function($r) use ($fragment) {
            return strpos($r['url'], $fragment) !== false;
        }));
    }

    public function test_exact_catalogue_match_wins_over_loose_ones() {
        $this->routes['/public/location/cities'] = $this->json(200, array(
            array('name' => 'Poieni-Cluj', 'county' => 'Cluj', 'postcode' => '407001'),
            array('name' => 'Cluj-Napoca', 'county' => 'Cluj', 'postcode' => '400001'),
        ));
        $recipient = $this->quoted_recipient($this->order(501, array('billing_city' => 'Cluj-Napoca', 'billing_state' => 'CJ')));

        $this->assertSame('CLUJ', $recipient['address']['county']);
        $this->assertSame('CLUJ-NAPOCA', $recipient['address']['city']);
        $this->assertStringContainsString('name=Cluj-Napoca', $this->requests[0]['url']);
    }

    public function test_the_state_hint_picks_between_towns_of_the_same_name_with_aliases() {
        $this->routes['/public/location/cities'] = $this->json(200, array(
            array('name' => 'Valea Mare', 'county' => 'Dambovita', 'postcode' => '137001'),
            array('name' => 'Valea Mare', 'county' => 'Vilcea', 'postcode' => '247001'),
        ));
        $recipient = $this->quoted_recipient($this->order(502, array('billing_city' => 'Valea Mare', 'billing_state' => 'VL', 'billing_postcode' => '240999')));

        $this->assertSame('VILCEA', $recipient['address']['county']);
        $this->assertSame('VALEA MARE', $recipient['address']['city']);
    }

    public function test_postcode_breaks_a_tie_without_a_state() {
        $this->routes['/public/location/cities'] = $this->json(200, array(
            array('name' => 'Valea Mare', 'county' => 'Dambovita', 'postcode' => '137001'),
            array('name' => 'Valea Mare', 'county' => 'Arges', 'postcode' => '117001'),
        ));
        $recipient = $this->quoted_recipient($this->order(503, array('billing_city' => 'Valea Mare', 'billing_state' => '', 'billing_postcode' => '117001')));

        $this->assertSame('ARGES', $recipient['address']['county']);
    }

    public function test_postcodes_are_the_fallback_when_the_city_is_not_found() {
        $this->routes['/public/location/cities'] = $this->json(200, array());
        $this->routes['/public/location/postcodes'] = $this->json(200, array(array('county' => 'Satu Mare', 'city' => 'Carei', 'postcode' => '445100')));
        $recipient = $this->quoted_recipient($this->order(504, array('billing_city' => 'Careii', 'billing_state' => '', 'billing_postcode' => '445100')));

        $this->assertSame('SATU MARE', $recipient['address']['county']);
        $this->assertSame('CAREII', $recipient['address']['city']);
        $this->assertStringContainsString('postcode=445100', $this->requests[1]['url']);
    }

    public function test_a_failed_lookup_falls_back_to_the_state_with_aliases() {
        $GLOBALS['wp_test_ro_states'] = array('DB' => 'Dimbovita', 'SM' => 'Satu-Mare', 'VL' => 'Valcea');
        $this->routes['/public/location/cities'] = $this->json(500, array('message' => 'boom'));
        $this->routes['/public/location/postcodes'] = $this->json(500, array('message' => 'boom'));

        $cases = array('DB' => 'DAMBOVITA', 'SM' => 'SATU MARE', 'VL' => 'VILCEA');
        foreach ($cases as $code => $county) {
            $recipient = $this->quoted_recipient($this->order(505, array('billing_city' => 'Orașul Exemplu', 'billing_state' => $code)));
            $this->assertSame($county, $recipient['address']['county'], $code);
            $this->assertSame('ORASUL EXEMPLU', $recipient['address']['city'], $code);
        }
    }

    public function test_an_unknown_state_code_is_used_as_is_when_nothing_matches() {
        $this->routes['/public/location/cities'] = $this->json(200, array());
        $this->routes['/public/location/postcodes'] = $this->json(200, array());
        $recipient = $this->quoted_recipient($this->order(506, array('billing_city' => 'Sat Inventat', 'billing_state' => 'Ilfov')));

        $this->assertSame('ILFOV', $recipient['address']['county']);
    }

    public function test_a_resolved_locality_is_cached() {
        $this->routes['/public/location/cities'] = $this->json(200, array(array('name' => 'Cluj-Napoca', 'county' => 'Cluj', 'postcode' => '400001')));
        $order = $this->order(507, array('billing_city' => 'Cluj-Napoca', 'billing_state' => 'CJ'));

        $this->quoted_recipient($order);
        $this->quoted_recipient($order);

        $this->assertSame(1, $this->count_requests('/public/location/cities'));
    }

    public function test_a_failed_lookup_is_not_cached() {
        $this->routes['/public/location/cities'] = $this->json(503, array('message' => 'down'));
        $this->routes['/public/location/postcodes'] = $this->json(503, array('message' => 'down'));
        $order = $this->order(508, array('billing_city' => 'Cluj-Napoca', 'billing_state' => 'CJ'));

        $this->quoted_recipient($order);
        $this->quoted_recipient($order);

        $this->assertSame(2, $this->count_requests('/public/location/cities'));
    }
}
