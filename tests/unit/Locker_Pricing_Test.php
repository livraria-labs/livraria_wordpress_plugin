<?php
/**
 * "Livrare la locker": what the buyer pays per courier (the seller's price,
 * or the live Livraria price with markup and rounding), the rate the method
 * adds, the locker kept in the session for it, and the modal's prices.
 */

require_once dirname(__DIR__) . '/QuoteFlowTestCase.php';
require_once dirname(__DIR__) . '/LockerFixtures.php';
require_once dirname(__DIR__) . '/stubs/ShippingMethod.php';

class Locker_Pricing_Test extends Livraria_Quote_Flow_TestCase {

    public function setUp(): void {
        parent::setUp();
        $GLOBALS['wp_test_wc_session'] = array();
        $GLOBALS['wp_test_shipping_settings'] = array();
        $GLOBALS['wp_test_ro_states'] = array('CJ' => 'Cluj', 'IS' => 'Iasi');
        $this->routes['/pudo-points/by-courier?courier=sameday'] = $this->json(200, Livraria_Locker_Fixtures::sameday());
        $this->routes['/pudo-points/by-courier?courier=dpd'] = $this->json(200, Livraria_Locker_Fixtures::dpd());
        $this->routes['/public/awb/locker-rates'] = $this->json(201, array(
            'rates' => array(
                array('courier' => 'sameday', 'courierName' => 'Sameday', 'amount' => 12.3, 'currency' => 'RON', 'lockerId' => 'SD-1001', 'exact' => false),
                array('courier' => 'dpd', 'courierName' => 'DPD', 'amount' => 14.05, 'currency' => 'RON', 'lockerId' => 'DPD-2001', 'exact' => false),
            ),
            'unavailable' => array(),
        ));
        Livraria_Lockers::reset_memo();
    }

    public function tearDown(): void {
        unset($GLOBALS['wp_test_wc_session'], $GLOBALS['wp_test_shipping_settings']);
        parent::tearDown();
    }

    private function client() {
        return new Livraria_API_Client('https://www.api.livraria.ro');
    }

    private function settings(array $values = array()) {
        return Livraria_Locker_Pricing::settings($values);
    }

    private function package(array $to = array(), $cost = 100.0) {
        return array(
            'contents' => array('line-1' => array('data' => new Livraria_Fake_Product('2', '30', '20', '10'), 'quantity' => 1)),
            'contents_cost' => $cost,
            'destination' => array_merge(array('country' => 'RO', 'state' => 'CJ', 'city' => 'Cluj-Napoca', 'postcode' => '400100'), $to),
        );
    }

    private function pick_sameday() {
        $GLOBALS['wp_test_wc_session'][Livraria_Locker_Pricing::SESSION_KEY] = array('id' => 'SD-1001', 'name' => 'easybox Exemplu Centru', 'courier' => 'sameday', 'county' => 'Cluj', 'city' => 'Cluj-Napoca', 'postcode' => '400100');
    }

    private function count_requests($fragment) {
        return count(array_filter($this->requests, function($r) use ($fragment) { return strpos($r['url'], $fragment) !== false; }));
    }

    // --- Seller's prices --------------------------------------------------

    public function test_the_same_price_for_every_courier_by_default() {
        $this->assertSame(array('sameday' => 15.0, 'dpd' => 15.0), Livraria_Locker_Pricing::buyer_prices($this->settings(), array('sameday', 'dpd'), 50));
        $this->assertSame(array('sameday' => 12.5), Livraria_Locker_Pricing::buyer_prices($this->settings(array('price' => '12,50')), array('sameday'), 50), 'a comma decimal is a price');
    }

    public function test_a_price_per_courier_falls_back_to_the_common_price_when_blank() {
        $settings = $this->settings(array('price_mode' => 'per_courier', 'price' => '15', 'price_sameday' => '9.99', 'price_dpd' => ''));
        $this->assertSame(array('sameday' => 9.99, 'dpd' => 15.0), Livraria_Locker_Pricing::buyer_prices($settings, array('sameday', 'dpd'), 50));
        $this->assertSame('price_fan_courier', Livraria_Locker_Pricing::price_key('fan-courier'));
    }

    public function test_free_above_the_threshold() {
        $settings = $this->settings(array('free_above' => '200'));
        $this->assertSame(array('sameday' => 15.0), Livraria_Locker_Pricing::buyer_prices($settings, array('sameday'), 199.99));
        $this->assertSame(array('sameday' => 0.0), Livraria_Locker_Pricing::buyer_prices($settings, array('sameday'), 200));
        $this->assertSame('Gratuit', Livraria_Locker_Pricing::format(0));
    }

    public function test_unknown_choices_go_back_to_the_default() {
        $settings = $this->settings(array('price_mode' => 'auction', 'markup_type' => 'x', 'rounding' => '7', 'live_fallback' => 'maybe'));
        $this->assertSame(array('same', 'none', 'none', 'seller'), array($settings['price_mode'], $settings['markup_type'], $settings['rounding'], $settings['live_fallback']));
    }

    // --- Live prices ------------------------------------------------------

    public function test_live_price_with_markup_and_rounding() {
        $this->assertSame(14.3, Livraria_Locker_Pricing::adjust(12.3, $this->settings(array('markup_type' => 'fixed', 'markup_value' => '2'))));
        $this->assertSame(13.53, Livraria_Locker_Pricing::adjust(12.3, $this->settings(array('markup_type' => 'percent', 'markup_value' => '10'))));
        $this->assertSame(13.0, Livraria_Locker_Pricing::adjust(12.3, $this->settings(array('rounding' => 'whole'))));
        $this->assertSame(12.99, Livraria_Locker_Pricing::adjust(12.3, $this->settings(array('rounding' => '99'))));
        $this->assertSame(12.99, Livraria_Locker_Pricing::adjust(12.99, $this->settings(array('rounding' => '99'))), 'already .99');
        $this->assertSame(13.99, Livraria_Locker_Pricing::adjust(13.0, $this->settings(array('rounding' => '99'))));
        $this->assertSame(12.0, Livraria_Locker_Pricing::adjust(12.0, $this->settings(array('rounding' => 'whole'))));
    }

    public function test_live_offers_only_the_couriers_livraria_priced() {
        $settings = $this->settings(array('price_mode' => 'live'));
        $this->assertSame(array('dpd' => 14.05), Livraria_Locker_Pricing::buyer_prices($settings, array('sameday', 'dpd'), 50, array('dpd' => 14.05)));
    }

    public function test_when_livraria_cannot_price_the_seller_price_stands_in_or_lockers_hide() {
        $this->assertSame(array('sameday' => 15.0), Livraria_Locker_Pricing::buyer_prices($this->settings(array('price_mode' => 'live')), array('sameday'), 50, null));
        $this->assertSame(array(), Livraria_Locker_Pricing::buyer_prices($this->settings(array('price_mode' => 'live', 'live_fallback' => 'hide')), array('sameday'), 50, null));
    }

    public function test_live_rates_are_asked_once_and_cached() {
        $body = array('sender' => array('county' => 'Bucuresti'), 'destination' => array('county' => 'Cluj', 'city' => 'Cluj-Napoca'), 'packages' => array());
        $this->assertSame(array('sameday' => 12.3, 'dpd' => 14.05), Livraria_Locker_Pricing::live_rates($this->client(), $body));
        $this->assertSame(array('sameday' => 12.3, 'dpd' => 14.05), Livraria_Locker_Pricing::live_rates($this->client(), $body));
        $this->assertSame(1, $this->count_requests('/public/awb/locker-rates'));
        $this->assertSame('POST', $this->requests[0]['method']);
    }

    public function test_live_rates_null_when_livraria_fails() {
        $this->routes['/public/awb/locker-rates'] = $this->json(503, array('message' => 'down'));
        $this->assertNull(Livraria_Locker_Pricing::live_rates($this->client(), array('x' => 1)));
    }

    // --- The rate ---------------------------------------------------------

    public function test_before_a_pick_the_rate_shows_the_lowest_price_under_the_title() {
        $settings = $this->settings(array('price_mode' => 'per_courier', 'price_sameday' => '11', 'price_dpd' => '13'));
        $rate = Livraria_Locker_Pricing::rate_for_package($settings, $this->package(), $this->client());
        $this->assertSame(array('cost' => 11.0, 'label' => 'Livrare la locker', 'meta' => array()), $rate);
    }

    public function test_after_a_pick_the_rate_costs_that_lockers_price() {
        $this->pick_sameday();
        $settings = $this->settings(array('price_mode' => 'per_courier', 'price_sameday' => '11', 'price_dpd' => '9'));
        $rate = Livraria_Locker_Pricing::rate_for_package($settings, $this->package(), $this->client());
        $this->assertSame(11.0, $rate['cost'], 'the picked courier, not the cheapest');
        $this->assertSame('Livrare la locker: easybox Sameday easybox Exemplu Centru', $rate['label']);
        $this->assertSame(array('Locker' => 'easybox Sameday easybox Exemplu Centru'), $rate['meta']);
    }

    public function test_live_rate_for_a_pick_prices_that_locker_exactly() {
        $this->pick_sameday();
        $rate = Livraria_Locker_Pricing::rate_for_package($this->settings(array('price_mode' => 'live', 'markup_type' => 'fixed', 'markup_value' => '1')), $this->package(array('state' => 'IS', 'city' => 'Iasi')), $this->client());
        $this->assertSame(13.3, $rate['cost']);
        $sent = $this->sent('/public/awb/locker-rates');
        $this->assertSame('sameday', $sent['courier']);
        $this->assertSame('SD-1001', $sent['lockerId']);
        $this->assertSame(array('county' => 'Cluj', 'city' => 'Cluj-Napoca', 'postcode' => '400100'), $sent['destination'], 'the locker\'s place, not the buyer\'s address');
        $this->assertNotEmpty($sent['sender']);
        $this->assertCount(1, $sent['packages']);
    }

    public function test_live_rate_without_a_pick_asks_for_the_buyers_county_by_name() {
        Livraria_Locker_Pricing::rate_for_package($this->settings(array('price_mode' => 'live')), $this->package(), $this->client());
        $sent = $this->sent('/public/awb/locker-rates');
        $this->assertSame(array('county' => 'Cluj', 'city' => 'Cluj-Napoca', 'postcode' => '400100'), $sent['destination']);
        $this->assertArrayNotHasKey('courier', $sent);
    }

    public function test_no_rate_when_livraria_cannot_price_and_the_seller_hides_it() {
        $this->routes['/public/awb/locker-rates'] = $this->json(503, array('message' => 'down'));
        $this->assertNull(Livraria_Locker_Pricing::rate_for_package($this->settings(array('price_mode' => 'live', 'live_fallback' => 'hide')), $this->package(), $this->client()));
    }

    public function test_couriers_livraria_switched_off_are_not_offered() {
        set_transient('livraria_couriers', array(array('slug' => 'sameday', 'active' => true), array('slug' => 'dpd', 'active' => false)));
        $this->assertSame(array('sameday'), Livraria_Lockers::checkout_couriers($this->client()));
        $settings = $this->settings(array('price_mode' => 'per_courier', 'price_sameday' => '11', 'price_dpd' => '5'));
        $this->assertSame(11.0, Livraria_Locker_Pricing::rate_for_package($settings, $this->package(), $this->client())['cost']);
    }

    public function test_new_couriers_start_off() {
        $this->assertSame(array('sameday', 'dpd'), Livraria_Lockers::enabled_couriers());
        update_option('livraria_lockers_gls', '1');
        $this->assertSame(array('sameday', 'dpd', 'gls'), Livraria_Lockers::enabled_couriers());
    }

    // --- The pick in the session ------------------------------------------

    public function test_select_keeps_the_locker_with_its_place_from_the_cached_list() {
        $stored = Livraria_Locker_Pricing::select($this->client(), array('delivery_mode' => 'locker', 'locker_id' => 'SD-1001', 'locker_name' => 'spoofed', 'locker_courier' => 'Sameday'));
        $expected = array('id' => 'SD-1001', 'name' => 'easybox Exemplu Centru', 'courier' => 'sameday', 'county' => 'Cluj', 'city' => 'Cluj-Napoca', 'postcode' => '400100');
        $this->assertSame($expected, $stored);
        $this->assertSame($expected, Livraria_Locker_Pricing::session_pick());
        $packages = Livraria_Locker_Pricing::tag_packages(array(array('contents' => array())));
        $this->assertSame('sameday:SD-1001', $packages[0]['livraria_locker'], 'a new pick changes the package hash');
    }

    public function test_select_refuses_unknown_lockers_and_couriers_the_shop_does_not_offer() {
        $this->pick_sameday();
        $this->assertNull(Livraria_Locker_Pricing::select($this->client(), array('delivery_mode' => 'locker', 'locker_id' => 'SD-9999', 'locker_courier' => 'sameday')));
        $this->assertNull(Livraria_Locker_Pricing::select($this->client(), array('delivery_mode' => 'locker', 'locker_id' => 'GLS-1', 'locker_courier' => 'gls')));
        $this->assertSame('SD-1001', Livraria_Locker_Pricing::session_pick()['id'], 'a refused pick leaves the last one');
    }

    public function test_home_or_an_empty_pick_clears_the_session() {
        $this->pick_sameday();
        $this->assertNull(Livraria_Locker_Pricing::select($this->client(), array('delivery_mode' => 'home')));
        $this->assertNull(Livraria_Locker_Pricing::session_pick());
        $this->assertSame('', Livraria_Locker_Pricing::tag_packages(array(array()))[0]['livraria_locker']);
    }

    public function test_the_order_must_carry_the_locker_the_price_was_for() {
        $rate = 'livraria_locker:4';
        $pick = function($id, $courier) { return Livraria_Lockers::normalize_pick(array('delivery_mode' => 'locker', 'locker_id' => $id, 'locker_courier' => $courier)); };
        $this->assertSame(Livraria_Lockers::MSG_GATE, Livraria_Lockers::validate_pick(Livraria_Lockers::normalize_pick(array('delivery_mode' => 'home')), $rate));
        $this->assertSame(Livraria_Locker_Pricing::MSG_PRICE_STALE, Livraria_Lockers::validate_pick($pick('SD-1001', 'sameday'), $rate), 'nothing priced yet');
        $this->pick_sameday();
        $this->assertSame(Livraria_Locker_Pricing::MSG_PRICE_STALE, Livraria_Lockers::validate_pick($pick('DPD-2001', 'dpd'), $rate));
        $this->assertSame('', Livraria_Lockers::validate_pick($pick('SD-1001', 'sameday'), $rate));
    }

    public function test_locker_rates_are_recognised_by_method_id() {
        $this->assertTrue(Livraria_Locker_Pricing::is_locker_rate('livraria_locker:7'));
        $this->assertFalse(Livraria_Locker_Pricing::is_locker_rate('livraria_lockers:7'));
        $this->assertSame(7, Livraria_Locker_Pricing::instance_of('livraria_locker:7'));
        $this->assertSame(0, Livraria_Locker_Pricing::instance_of('flat_rate:7'));
        $this->assertTrue(Livraria_Lockers::rate_offers_lockers('livraria_locker:7'), 'the picker shows without ticking the method');
    }

    // --- The shipping method and the modal's prices -----------------------

    public function test_the_shipping_method_adds_one_rate_from_its_instance_settings() {
        $GLOBALS['wp_test_shipping_settings'][4] = array('title' => 'La locker', 'price' => '17');
        Livraria_Checkout::load_shipping_method();
        $method = new Livraria_Locker_Shipping_Method(4);
        $method->calculate_shipping($this->package());
        $this->assertCount(1, $method->rates);
        $this->assertSame('livraria_locker:4', $method->rates[0]['id']);
        $this->assertSame('La locker', $method->rates[0]['label']);
        $this->assertSame(17.0, $method->rates[0]['cost']);
        $this->assertSame(array('livraria_locker' => 'Livraria_Locker_Shipping_Method'), Livraria_Checkout::add_shipping_method(array()));
    }

    public function test_the_modal_gets_each_couriers_price_for_the_county() {
        $GLOBALS['wp_test_shipping_settings'][4] = array('price_mode' => 'per_courier', 'price' => '15', 'price_dpd' => '0');
        $answer = Livraria_Checkout::locker_prices($this->client(), 'livraria_locker:4', 'Cluj', 'Cluj-Napoca');
        $this->assertSame(array(
            'sameday' => array('amount' => 15.0, 'label' => '15,00 lei'),
            'dpd' => array('amount' => 0.0, 'label' => 'Gratuit'),
        ), $answer['prices']);
        $this->assertSame(array('prices' => null), Livraria_Checkout::locker_prices($this->client(), 'flat_rate:3', 'Cluj', 'Cluj-Napoca'));
    }

    public function test_the_checkout_config_carries_the_modal_actions_and_the_priced_pick() {
        $this->pick_sameday();
        $config = Livraria_Lockers::checkout_config($this->client());
        $this->assertSame('livraria_locker_rates', $config['ratesAction']);
        $this->assertSame('livraria_locker_select', $config['selectAction']);
        $this->assertSame('livraria_locker', $config['lockerMethod']);
        $this->assertSame('SD-1001', $config['pricedPick']['id']);
    }

    public function test_api_client_posts_locker_rates() {
        $this->client()->get_locker_rates(array('destination' => array('county' => 'Cluj')));
        $this->assertSame(array('destination' => array('county' => 'Cluj')), $this->sent('/public/awb/locker-rates'));
    }
}
