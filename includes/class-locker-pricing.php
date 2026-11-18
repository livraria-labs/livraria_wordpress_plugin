<?php
/**
 * What the buyer pays for a locker on the "Livrare la locker" shipping method
 * (Livraria_Locker_Shipping_Method): the seller's price (the same for every
 * courier, or one per courier) or the live Livraria price of that courier's
 * locker (POST /public/awb/locker-rates) with the seller's markup and
 * rounding. Free above a cart subtotal when the seller sets one.
 *
 * The buyer's locker lives in the WooCommerce session from the moment it is
 * picked until the order is placed, so the rate (and its price) follows it.
 * Before a pick the rate shows the lowest courier price.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Livraria_Locker_Pricing {

    const METHOD_ID = 'livraria_locker';

    /** WC session key: the locker the price is for (id, name, courier, county, city, postcode) */
    const SESSION_KEY = 'livraria_locker_pick';

    /** Live prices, per request body: 10 minutes (the backend caches too) */
    const RATES_TTL = 600;
    const RATES_PREFIX = 'livraria_locker_rates_';

    const DEFAULT_TITLE = 'Livrare la locker';

    /** Asked when the order's locker is not the one the price was worked out for */
    const MSG_PRICE_STALE = 'Lockerul ales s-a schimbat. Verifică prețul livrării și plasează din nou comanda.';

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /**
     * @return array Instance settings with their defaults
     */
    public static function defaults() {
        $defaults = array(
            'title' => self::DEFAULT_TITLE,
            'price_mode' => 'same',
            'price' => '15',
            'markup_type' => 'none',
            'markup_value' => '0',
            'rounding' => 'none',
            'free_above' => '',
            'live_fallback' => 'seller',
        );
        foreach (array_keys(Livraria_Lockers::COURIERS) as $courier) {
            $defaults[self::price_key($courier)] = '';
        }
        return $defaults;
    }

    /**
     * @param array $values Saved instance settings
     * @return array Complete settings, unknown choices back to the default
     */
    public static function settings($values) {
        $settings = array_merge(self::defaults(), is_array($values) ? $values : array());
        $choices = array(
            'price_mode' => array('same', 'per_courier', 'live'),
            'markup_type' => array('none', 'fixed', 'percent'),
            'rounding' => array('none', 'whole', '99'),
            'live_fallback' => array('seller', 'hide'),
        );
        $defaults = self::defaults();
        foreach ($choices as $key => $allowed) {
            if (!in_array((string) $settings[$key], $allowed, true)) {
                $settings[$key] = $defaults[$key];
            }
        }
        return $settings;
    }

    /** The setting holding a courier's own price ("fan-courier" → "price_fan_courier") */
    public static function price_key($courier) {
        return 'price_' . str_replace('-', '_', $courier);
    }

    /**
     * The shipping method's settings form (WooCommerce instance settings)
     *
     * @return array
     */
    public static function form_fields() {
        $fields = array(
            'title' => array(
                'title' => 'Title',
                'type' => 'text',
                'description' => 'What the buyer sees at checkout. The chosen locker is added after it.',
                'default' => self::DEFAULT_TITLE,
                'desc_tip' => true,
            ),
            'price_mode' => array(
                'title' => 'Price',
                'type' => 'select',
                'default' => 'same',
                'options' => array(
                    'same' => 'The same price for every courier',
                    'per_courier' => 'A price per courier',
                    'live' => 'Your Livraria price for the chosen locker (live)',
                ),
            ),
            'price' => array(
                'title' => 'Price (RON)',
                'type' => 'price',
                'description' => 'Every courier\'s price; with "A price per courier", the price of a courier left blank below. With the live price, what the buyer pays when Livraria cannot be reached.',
                'default' => '15',
                'desc_tip' => true,
            ),
        );
        foreach (Livraria_Lockers::COURIERS as $slug => $courier) {
            $fields[self::price_key($slug)] = array(
                'title' => $courier['product'] . ' (RON)',
                'type' => 'price',
                'description' => 'Used with "A price per courier". Blank: the price above.',
                'default' => '',
                'desc_tip' => true,
            );
        }
        return array_merge($fields, array(
            'markup_type' => array(
                'title' => 'Markup on the live price',
                'type' => 'select',
                'default' => 'none',
                'options' => array('none' => 'None', 'fixed' => 'Fixed amount (RON)', 'percent' => 'Percent'),
            ),
            'markup_value' => array(
                'title' => 'Markup',
                'type' => 'text',
                'default' => '0',
            ),
            'rounding' => array(
                'title' => 'Rounding of the live price',
                'type' => 'select',
                'default' => 'none',
                'options' => array('none' => 'None (to the ban)', 'whole' => 'Up to a whole leu (12.30 → 13)', '99' => 'Up to .99 (12.30 → 12.99)'),
            ),
            'live_fallback' => array(
                'title' => 'When Livraria cannot price',
                'type' => 'select',
                'default' => 'seller',
                'options' => array('seller' => 'Charge the price above', 'hide' => 'Hide locker delivery'),
            ),
            'free_above' => array(
                'title' => 'Free above (RON)',
                'type' => 'price',
                'description' => 'Cart subtotal from which lockers are free. Blank: never.',
                'default' => '',
                'desc_tip' => true,
            ),
        ));
    }

    // -------------------------------------------------------------------------
    // Prices
    // -------------------------------------------------------------------------

    /**
     * A price typed by the seller ("12,50" accepted), null when blank or not a price
     *
     * @param mixed $value
     * @return float|null
     */
    public static function amount($value) {
        $value = str_replace(',', '.', trim((string) $value));
        return is_numeric($value) && (float) $value >= 0 ? (float) $value : null;
    }

    /**
     * @param array $settings
     * @param string $courier
     * @return float|null The seller's price for this courier's lockers
     */
    public static function seller_price($settings, $courier) {
        if ($settings['price_mode'] === 'per_courier') {
            $own = self::amount(isset($settings[self::price_key($courier)]) ? $settings[self::price_key($courier)] : '');
            if ($own !== null) {
                return $own;
            }
        }
        return self::amount($settings['price']);
    }

    /**
     * A live price with the seller's markup and rounding
     *
     * @param float $amount
     * @param array $settings
     * @return float
     */
    public static function adjust($amount, $settings) {
        $markup = self::amount($settings['markup_value']);
        $markup = $markup === null ? 0.0 : $markup;
        if ($settings['markup_type'] === 'fixed') {
            $amount += $markup;
        } elseif ($settings['markup_type'] === 'percent') {
            $amount *= 1 + $markup / 100;
        }
        return self::round_price($amount, $settings['rounding']);
    }

    /**
     * @param float $amount
     * @param string $rounding none | whole (up to a leu) | 99 (up to the next .99)
     * @return float
     */
    public static function round_price($amount, $rounding) {
        $amount = round((float) $amount, 2);
        if ($rounding === 'whole') {
            return (float) ceil($amount);
        }
        if ($rounding === '99') {
            return round(ceil($amount + 0.01) - 0.01, 2);
        }
        return $amount;
    }

    /**
     * @param array $settings
     * @param float $subtotal
     * @return bool Whether the cart is over the seller's free threshold
     */
    public static function is_free($settings, $subtotal) {
        $threshold = self::amount($settings['free_above']);
        return $threshold !== null && $threshold > 0 && (float) $subtotal >= $threshold;
    }

    /**
     * What the buyer pays per courier. With the live price only couriers
     * Livraria priced are offered; when Livraria could not be asked ($live
     * null) the seller's price stands in, or nothing ("hide").
     *
     * @param array $settings settings()
     * @param string[] $couriers
     * @param float $subtotal
     * @param array|null $live live_rates() result
     * @return array slug => price; a courier without a price is left out
     */
    public static function buyer_prices($settings, $couriers, $subtotal, $live = null) {
        $live_mode = $settings['price_mode'] === 'live';
        if ($live_mode && $live === null && $settings['live_fallback'] === 'hide') {
            return array();
        }
        $prices = array();
        foreach ($couriers as $courier) {
            if ($live_mode && is_array($live)) {
                if (!isset($live[$courier])) {
                    continue; // the courier cannot carry this parcel to a locker there
                }
                $price = self::adjust($live[$courier], $settings);
            } else {
                $price = self::seller_price($settings, $courier);
            }
            if ($price === null) {
                continue;
            }
            $prices[$courier] = self::is_free($settings, $subtotal) ? 0.0 : $price;
        }
        return $prices;
    }

    /**
     * Livraria's per-courier locker prices for one request body, cached
     *
     * @param Livraria_API_Client $api_client
     * @param array $body sender, destination, packages[, courier, lockerId]
     * @return array|null slug => amount (RON); null when Livraria did not answer
     */
    public static function live_rates($api_client, $body) {
        $key = self::RATES_PREFIX . md5(serialize($body));
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
        $response = $api_client->get_locker_rates($body);
        if (is_array($response) && isset($response['data']) && is_array($response['data'])) {
            $response = $response['data'];
        }
        if (!is_array($response) || !isset($response['rates']) || !is_array($response['rates'])) {
            return null;
        }
        $rates = array();
        foreach ($response['rates'] as $rate) {
            if (is_array($rate) && isset($rate['courier'], $rate['amount']) && is_numeric($rate['amount'])) {
                $rates[strtolower((string) $rate['courier'])] = (float) $rate['amount'];
            }
        }
        set_transient($key, $rates, self::RATES_TTL);
        return $rates;
    }

    /**
     * Live prices for a cart: to the picked locker exactly, else to each
     * courier's locker nearest the destination
     *
     * @param Livraria_API_Client $api_client
     * @param array[] $items product, quantity
     * @param array $destination county, city, postcode
     * @param array|null $pick session_pick()
     * @return array|null
     */
    public static function live_for_cart($api_client, $items, $destination, $pick = null) {
        if ($pick !== null) {
            $destination = array('county' => $pick['county'], 'city' => $pick['city'], 'postcode' => isset($pick['postcode']) ? $pick['postcode'] : '');
        }
        if (empty($destination['county']) || empty($destination['city'])) {
            return null;
        }
        $sender = (new Livraria_Order_Handler($api_client))->checkout_sender();
        if ($sender === null) {
            return null;
        }
        $body = array(
            'sender' => $sender,
            'destination' => array_filter(array(
                'county' => (string) $destination['county'],
                'city' => (string) $destination['city'],
                'postcode' => isset($destination['postcode']) ? (string) $destination['postcode'] : '',
            ), 'strlen'),
            'packages' => Livraria_Order_Handler::packages_from_items($items),
        );
        if ($pick !== null) {
            $body['courier'] = $pick['courier'];
            $body['lockerId'] = (string) $pick['id'];
        }
        return self::live_rates($api_client, $body);
    }

    /**
     * The products of a WooCommerce shipping package (or cart contents)
     *
     * @param array $contents cart item => data (WC_Product), quantity
     * @return array[] product, quantity
     */
    public static function items_of($contents) {
        $items = array();
        foreach ((array) $contents as $line) {
            if (is_array($line) && isset($line['data'])) {
                $items[] = array('product' => $line['data'], 'quantity' => isset($line['quantity']) ? $line['quantity'] : 1);
            }
        }
        return $items;
    }

    /**
     * The buyer's address of a shipping package as Livraria reads it (the
     * county by name); empty outside Romania
     *
     * @param array $package
     * @return array county, city, postcode
     */
    public static function destination_of($package) {
        $to = isset($package['destination']) && is_array($package['destination']) ? $package['destination'] : array();
        if (isset($to['country']) && $to['country'] !== '' && $to['country'] !== 'RO') {
            return array('county' => '', 'city' => '', 'postcode' => '');
        }
        $state = isset($to['state']) ? (string) $to['state'] : '';
        $states = function_exists('WC') && WC() && isset(WC()->countries) ? WC()->countries->get_states('RO') : array();
        return array(
            'county' => isset($states[$state]) ? $states[$state] : $state,
            'city' => isset($to['city']) ? (string) $to['city'] : '',
            'postcode' => isset($to['postcode']) ? (string) $to['postcode'] : '',
        );
    }

    /**
     * The rate the shipping method adds for a package: the picked locker's
     * price, else the lowest courier price. Null: no rate (no courier, or
     * no price and the seller hides it).
     *
     * @param array $settings settings()
     * @param array $package WooCommerce shipping package
     * @param Livraria_API_Client|null $api_client (tests)
     * @return array|null cost, label, meta
     */
    public static function rate_for_package($settings, $package, $api_client = null) {
        $api_client = $api_client ?: new Livraria_API_Client();
        $couriers = Livraria_Lockers::checkout_couriers($api_client);
        if (empty($couriers)) {
            return null;
        }
        $pick = self::session_pick();
        if ($pick !== null && !in_array($pick['courier'], $couriers, true)) {
            $pick = null;
        }
        $subtotal = isset($package['contents_cost']) ? (float) $package['contents_cost'] : 0.0;
        $live = null;
        if ($settings['price_mode'] === 'live') {
            $contents = isset($package['contents']) ? $package['contents'] : array();
            $live = self::live_for_cart($api_client, self::items_of($contents), self::destination_of($package), $pick);
        }
        $prices = self::buyer_prices($settings, $pick !== null ? array($pick['courier']) : $couriers, $subtotal, $live);
        if (empty($prices)) {
            return null;
        }
        $title = trim((string) $settings['title']) !== '' ? trim((string) $settings['title']) : self::DEFAULT_TITLE;
        if ($pick === null) {
            return array('cost' => min($prices), 'label' => $title, 'meta' => array());
        }
        return array(
            'cost' => $prices[$pick['courier']],
            'label' => $title . ': ' . Livraria_Lockers::display($pick),
            'meta' => array('Locker' => Livraria_Lockers::display($pick)),
        );
    }

    /**
     * A price as the shop shows it, without markup ("12,99 lei", "Gratuit")
     *
     * @param float $amount
     * @return string
     */
    public static function format($amount) {
        if ((float) $amount <= 0) {
            return 'Gratuit';
        }
        if (function_exists('wc_price')) {
            return trim(html_entity_decode(wp_strip_all_tags(wc_price($amount)), ENT_QUOTES, 'UTF-8'));
        }
        return number_format((float) $amount, 2, ',', '.') . ' lei';
    }

    // -------------------------------------------------------------------------
    // The buyer's locker (WC session)
    // -------------------------------------------------------------------------

    /**
     * @return array|null The locker the rate is priced for
     */
    public static function session_pick() {
        if (!function_exists('WC') || !WC() || empty(WC()->session)) {
            return null;
        }
        $pick = WC()->session->get(self::SESSION_KEY);
        return is_array($pick) && !empty($pick['id']) && !empty($pick['courier']) ? $pick : null;
    }

    private static function store_pick($pick) {
        if (function_exists('WC') && WC() && !empty(WC()->session)) {
            WC()->session->set(self::SESSION_KEY, $pick);
        }
    }

    /**
     * The buyer picked a locker (or went back to home delivery): keep it for
     * the price. Only a locker of a courier the shop offers, found in the
     * cached list; its county and city come from there, not from the browser.
     *
     * @param Livraria_API_Client $api_client
     * @param array $input delivery_mode, locker_id, locker_name, locker_courier
     * @return array|null The stored pick; null when none is kept
     */
    public static function select($api_client, $input) {
        $pick = Livraria_Lockers::normalize_pick($input);
        if ($pick['mode'] !== 'locker' || $pick['id'] === '') {
            self::store_pick(null);
            return null;
        }
        if (!in_array($pick['courier'], Livraria_Lockers::checkout_couriers($api_client), true)) {
            return null;
        }
        $locker = Livraria_Lockers::find_locker($api_client, $pick['courier'], $pick['id']);
        if ($locker === null) {
            return null;
        }
        $stored = array(
            'id' => (string) $locker['id'],
            'name' => (string) $locker['name'],
            'courier' => $pick['courier'],
            'county' => (string) $locker['county'],
            'city' => (string) $locker['city'],
            'postcode' => isset($locker['postalCode']) ? (string) $locker['postalCode'] : '',
        );
        self::store_pick($stored);
        return $stored;
    }

    /**
     * Shipping packages carry the session pick, so WooCommerce prices them
     * again when it changes (rates are cached per package hash)
     *
     * @param array $packages
     * @return array
     */
    public static function tag_packages($packages) {
        $pick = self::session_pick();
        foreach ($packages as $i => $package) {
            $packages[$i]['livraria_locker'] = $pick ? $pick['courier'] . ':' . $pick['id'] : '';
        }
        return $packages;
    }

    /**
     * @param string $rate_id
     * @return bool Whether a rate is this plugin's locker method
     */
    public static function is_locker_rate($rate_id) {
        $rate_id = (string) $rate_id;
        return $rate_id === self::METHOD_ID || strpos($rate_id, self::METHOD_ID . ':') === 0;
    }

    /**
     * The instance id of a locker rate ("livraria_locker:7" → 7), 0 otherwise
     */
    public static function instance_of($rate_id) {
        if (!self::is_locker_rate($rate_id)) {
            return 0;
        }
        $parts = explode(':', (string) $rate_id);
        return isset($parts[1]) ? (int) $parts[1] : 0;
    }
}
