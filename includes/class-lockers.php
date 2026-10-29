<?php
/**
 * Lockers for Livraria Plugin: the merged Sameday + DPD locker list (cached,
 * proxied to the checkout and the order panel), the buyer's pick on the
 * order, and the settings that switch couriers on.
 *
 * Shape and rules follow the Shopify extension (lockerPick.js, lockers.ts,
 * locker-cache.ts, proxy.lockers.tsx). "locker" is the generic word;
 * "easybox" only beside Sameday.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Livraria_Lockers {

    /**
     * Couriers whose lockers can be picked, at either end of the route.
     * product: how one of its lockers is named on screen.
     */
    const COURIERS = array(
        'sameday' => array('slug' => 'sameday', 'name' => 'Sameday', 'product' => 'easybox Sameday'),
        'dpd' => array('slug' => 'dpd', 'name' => 'DPD', 'product' => 'locker DPD'),
    );

    /** Order meta written by both checkouts (same keys as the Shopify cart attributes) */
    const META_MODE = '_livraria_delivery_mode';
    const META_ID = '_livraria_locker_id';
    const META_NAME = '_livraria_locker_name';
    const META_COURIER = '_livraria_locker_courier';

    /** Settings: lockers per courier (on by default) and "require locker" (off) */
    const OPT_ENABLED_PREFIX = 'livraria_lockers_';
    const OPT_REQUIRE_LOCKER = 'livraria_require_locker';

    /** Fresh list: transient, 15 minutes. Last good list: option, kept for a backend failure. */
    const CACHE_TTL = 900;
    const CACHE_FRESH_PREFIX = 'livraria_lockers_list_';
    const CACHE_STALE_PREFIX = 'livraria_lockers_stale_';

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /**
     * @param string $courier
     * @return bool Whether the shop offers this courier's lockers (default: yes)
     */
    public static function courier_enabled($courier) {
        if (!isset(self::COURIERS[$courier])) {
            return false;
        }
        return (string) get_option(self::OPT_ENABLED_PREFIX . $courier, '1') !== '0';
    }

    /**
     * @return string[] Slugs of the couriers the shop offers, in COURIERS order
     */
    public static function enabled_couriers() {
        return array_values(array_filter(array_keys(self::COURIERS), array(__CLASS__, 'courier_enabled')));
    }

    /**
     * "Require locker" hides home delivery at checkout. It does not decide the
     * gate: locker mode without a locker is refused either way.
     *
     * @return bool
     */
    public static function require_locker() {
        return (string) get_option(self::OPT_REQUIRE_LOCKER, '0') === '1';
    }

    /** Shipping methods that show the locker choice: "method_id:instance_id" list (PrestaShop rule) */
    const OPT_METHODS = 'livraria_locker_methods';

    /**
     * @return string[] The ticked shipping method instances, e.g. "flat_rate:3"
     */
    public static function locker_methods() {
        $methods = get_option(self::OPT_METHODS, array());
        return is_array($methods) ? array_values(array_filter(array_map('strval', $methods))) : array();
    }

    /**
     * A posted list of "method_id:instance_id" values, cleaned
     *
     * @param mixed $value Array, or a comma-separated string (the settings page posts one string)
     * @return string[]
     */
    public static function sanitize_methods($value) {
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $clean = array();
        foreach ($values as $item) {
            $item = sanitize_text_field((string) $item);
            if (preg_match('/^[A-Za-z0-9_\-]+:\d+$/', $item) && !in_array($item, $clean, true)) {
                $clean[] = $item;
            }
        }
        return $clean;
    }

    /**
     * Whether the chosen shipping rate is one the merchant ticked. A rate id
     * is "method_id:instance_id", sometimes with more after it
     * ("table_rate:5:2"): a ticked instance matches itself and its suffixes.
     *
     * @param string|null $rate_id
     * @return bool
     */
    public static function rate_offers_lockers($rate_id) {
        $rate_id = (string) $rate_id;
        if ($rate_id === '') {
            return false;
        }
        foreach (self::locker_methods() as $method) {
            if ($rate_id === $method || strpos($rate_id, $method . ':') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the checkout carries the locker choice at all: a courier on and
     * at least one shipping method ticked. Without a ticked method nothing is
     * shown, enqueued or checked (an untouched upgrade changes nothing).
     *
     * @return bool
     */
    public static function picker_active() {
        return !empty(self::enabled_couriers()) && !empty(self::locker_methods());
    }

    /**
     * The shop's shipping method instances for the settings page: zone name +
     * method title, value "method_id:instance_id"
     *
     * @return array[] value, label
     */
    public static function shop_shipping_methods() {
        $methods = array();
        if (!class_exists('WC_Shipping_Zones')) {
            return $methods;
        }
        $zones = WC_Shipping_Zones::get_zones();
        if (class_exists('WC_Shipping_Zone')) {
            $rest = new WC_Shipping_Zone(0);
            $zones[] = array('zone_name' => $rest->get_zone_name(), 'shipping_methods' => $rest->get_shipping_methods());
        }
        foreach ($zones as $zone) {
            foreach ((array) ($zone['shipping_methods'] ?? array()) as $method) {
                if (!is_object($method) || empty($method->id) || empty($method->instance_id)) {
                    continue;
                }
                $methods[] = array(
                    'value' => $method->id . ':' . $method->instance_id,
                    'label' => trim(($zone['zone_name'] ?? '') . ' — ' . (method_exists($method, 'get_title') ? $method->get_title() : $method->id)),
                );
            }
        }
        return $methods;
    }

    /** Option names the settings page may write */
    public static function setting_names() {
        $names = array(self::OPT_REQUIRE_LOCKER);
        foreach (array_keys(self::COURIERS) as $courier) {
            $names[] = self::OPT_ENABLED_PREFIX . $courier;
        }
        return $names;
    }

    // -------------------------------------------------------------------------
    // The list
    // -------------------------------------------------------------------------

    /**
     * The courier slug a request asks for, or null when it is not one we offer
     *
     * @param mixed $value
     * @return string|null
     */
    public static function parse_courier($value) {
        $slug = strtolower(trim((string) $value));
        return isset(self::COURIERS[$slug]) ? $slug : null;
    }

    /**
     * One courier's GET /pudo-points/by-courier payload as locker records.
     * Dropped: lockers the courier hides from recipients
     * (capabilities.clientVisible === false), lockers the backend marks not
     * offerable (capabilities.offerability.offerable === false) and entries
     * without an id or a name. A missing field is not a false.
     *
     * @param mixed $payload
     * @param string $courier
     * @return array[] id, name, county, city, street, streetNo, postalCode, courier
     */
    public static function trim_lockers($payload, $courier) {
        $raw = (is_array($payload) && isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : $payload;
        if (!is_array($raw)) {
            return array();
        }
        $lockers = array();
        foreach ($raw as $l) {
            if (!is_array($l)) {
                continue;
            }
            $caps = isset($l['capabilities']) && is_array($l['capabilities']) ? $l['capabilities'] : array();
            if (array_key_exists('clientVisible', $caps) && $caps['clientVisible'] === false) {
                continue;
            }
            if (isset($caps['offerability']) && is_array($caps['offerability'])
                && array_key_exists('offerable', $caps['offerability']) && $caps['offerability']['offerable'] === false) {
                continue;
            }
            $address = isset($l['address']) && is_array($l['address']) ? $l['address'] : array();
            $id = isset($l['id']) ? $l['id'] : (isset($l['lockerId']) ? $l['lockerId'] : '');
            $locker = array(
                'id' => (string) $id,
                'name' => isset($l['name']) ? (string) $l['name'] : '',
                'county' => isset($address['county']) ? (string) $address['county'] : '',
                'city' => isset($address['city']) ? (string) $address['city'] : '',
                'street' => isset($address['street']) ? (string) $address['street'] : '',
                'streetNo' => isset($address['streetNo']) ? (string) $address['streetNo'] : '',
                'postalCode' => isset($address['postalCode']) ? (string) $address['postalCode'] : '',
                'courier' => $courier,
            );
            if ($locker['id'] !== '' && $locker['name'] !== '') {
                $lockers[] = $locker;
            }
        }
        return $lockers;
    }

    /**
     * What the checkout receives for one locker: no postcode, nothing the
     * buyer does not need to choose
     *
     * @param array $locker trim_lockers() record
     * @return array id, name, county, city, street, courier
     */
    public static function storefront_entry($locker) {
        return array(
            'id' => $locker['id'],
            'name' => $locker['name'],
            'county' => $locker['county'],
            'city' => $locker['city'],
            'street' => trim($locker['street'] . ' ' . $locker['streetNo']),
            'courier' => $locker['courier'],
        );
    }

    /**
     * One courier's lockers: fresh from the transient (hit), else from the
     * backend (miss, cached 15 minutes, kept as the last good copy), else the
     * last good copy when the backend fails (stale), else null.
     *
     * @param Livraria_API_Client $api_client
     * @param string $courier
     * @return array|null lockers, status ('hit', 'miss' or 'stale'); null = failed, nothing cached
     */
    public static function courier_list($api_client, $courier) {
        $fresh = get_transient(self::CACHE_FRESH_PREFIX . $courier);
        if (is_array($fresh)) {
            return array('lockers' => $fresh, 'status' => 'hit');
        }
        $payload = $api_client->get_pudo_points_by_courier($courier);
        if ($payload !== false) {
            $lockers = self::resolve_missing_counties($api_client, self::trim_lockers($payload, $courier));
            set_transient(self::CACHE_FRESH_PREFIX . $courier, $lockers, self::CACHE_TTL);
            update_option(self::CACHE_STALE_PREFIX . $courier, $lockers, false);
            return array('lockers' => $lockers, 'status' => 'miss');
        }
        $stale = get_option(self::CACHE_STALE_PREFIX . $courier, false);
        if (is_array($stale)) {
            return array('lockers' => $stale, 'status' => 'stale');
        }
        return null;
    }

    /**
     * The lockers of several couriers as one list, sorted by name
     *
     * @param Livraria_API_Client $api_client
     * @param string[] $couriers
     * @return array|null lockers, status (worst of the couriers), failed (couriers with no list); null when none answered
     */
    public static function merged_list($api_client, $couriers) {
        $lockers = array();
        $failed = array();
        $statuses = array();
        foreach ($couriers as $courier) {
            $list = self::courier_list($api_client, $courier);
            if ($list === null) {
                $failed[] = $courier;
                continue;
            }
            $statuses[] = $list['status'];
            $lockers = array_merge($lockers, $list['lockers']);
        }
        if (empty($statuses) && !empty($couriers)) {
            return null;
        }
        usort($lockers, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        $status = in_array('stale', $statuses, true) ? 'stale' : (in_array('miss', $statuses, true) ? 'miss' : 'hit');
        return array('lockers' => $lockers, 'status' => $status, 'failed' => $failed);
    }

    /**
     * The answer of the locker proxy for $courier_param ('' = every enabled
     * courier): 400 for a courier we do not offer or the shop switched off,
     * 502 when the backend failed and nothing is cached, else 200.
     *
     * $county narrows the answer, from the same cached lists (no extra
     * backend call): null = every locker; '' = only the counties (name and
     * locker count, a few KB, what the checkout asks first); a county name =
     * that county's lockers (diacritics and case ignored).
     *
     * @param Livraria_API_Client $api_client
     * @param string $courier_param
     * @param bool $storefront Trim each locker to storefront_entry()
     * @param string|null $county
     * @return array code, body, cache ('hit' | 'miss' | 'stale' | '')
     */
    public static function proxy_response($api_client, $courier_param, $storefront, $county = null) {
        $couriers = self::enabled_couriers();
        if ((string) $courier_param !== '') {
            $courier = self::parse_courier($courier_param);
            if ($courier === null || !in_array($courier, $couriers, true)) {
                return array(
                    'code' => 400,
                    'body' => array('error' => 'Unsupported courier: ' . sanitize_text_field((string) $courier_param) . '. Use one of: ' . implode(', ', $couriers) . '.'),
                    'cache' => '',
                );
            }
            $couriers = array($courier);
        }
        $list = self::merged_list($api_client, $couriers);
        if ($list === null) {
            return array('code' => 502, 'body' => array('error' => 'Failed to load lockers'), 'cache' => '');
        }
        if ($county === '') {
            return array(
                'code' => 200,
                'body' => array('couriers' => $couriers, 'counties' => self::counties($list['lockers']), 'failed' => $list['failed']),
                'cache' => $list['status'],
            );
        }
        $lockers = $list['lockers'];
        if ($county !== null) {
            $key = self::county_key($county);
            if ($key === self::county_key(self::OTHER_COUNTY)) {
                $key = ''; // the bucket of lockers whose county is not known
            }
            $lockers = array_values(array_filter($lockers, function($locker) use ($key) {
                return self::county_key($locker['county']) === $key;
            }));
        }
        $lockers = $storefront ? array_map(array(__CLASS__, 'storefront_entry'), $lockers) : $lockers;
        return array(
            'code' => 200,
            'body' => array('couriers' => $couriers, 'lockers' => $lockers, 'failed' => $list['failed']),
            'cache' => $list['status'],
        );
    }

    /**
     * A county name compared without case, diacritics, spaces or dashes (letters and digits kept)
     *
     * @param string $county
     * @return string
     */
    public static function county_key($county) {
        $map = array(
            'ă' => 'a', 'Ă' => 'A', 'â' => 'a', 'Â' => 'A', 'î' => 'i', 'Î' => 'I',
            'ș' => 's', 'Ș' => 'S', 'ş' => 's', 'Ş' => 'S', 'ț' => 't', 'Ț' => 'T', 'ţ' => 't', 'Ţ' => 'T',
        );
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(strtr((string) $county, $map)));
    }

    /**
     * The distinct counties of a locker list, sorted, with their locker counts
     *
     * @param array $lockers
     * @return array[] name, count
     */
    public static function counties($lockers) {
        $counties = array();
        $other = 0;
        foreach ($lockers as $locker) {
            $key = self::county_key($locker['county']);
            if ($key === '') {
                $other++; // kept: still findable by search in the bucket
                continue;
            }
            if (!isset($counties[$key])) {
                $counties[$key] = array('name' => $locker['county'], 'count' => 0);
            }
            $counties[$key]['count']++;
        }
        $counties = array_values($counties);
        usort($counties, function($a, $b) {
            return strcmp(self::county_key($a['name']), self::county_key($b['name']));
        });
        if ($other > 0) {
            $counties[] = array('name' => self::OTHER_COUNTY, 'count' => $other);
        }
        return $counties;
    }

    /** The county-list entry for lockers whose county could not be worked out */
    const OTHER_COUNTY = 'Alt județ / necunoscut';

    /** Cache of postcode prefix -> county (from the Livraria catalogue) */
    const PREFIX_CACHE = 'livraria_loc_prefix_';

    /**
     * Fill in the county of lockers that came without one (DPD sends only
     * city and postal code). Romanian postcodes are 6 digits and their first
     * two identify the county: for each distinct prefix the county is read
     * once from the Livraria catalogue (GET /public/location/postcodes with a
     * postcode of that prefix) and cached for 7 days. A prefix the catalogue
     * does not resolve falls back to the city (GET /public/location/cities, a
     * single county for that name). Spelled as the catalogue spells it
     * (uppercase, no diacritics, known aliases). A locker still without a
     * county stays in the list (counties() puts it under OTHER_COUNTY).
     *
     * @param Livraria_API_Client $api_client
     * @param array $lockers trim_lockers() records
     * @return array
     */
    public static function resolve_missing_counties($api_client, $lockers) {
        $by_prefix = array();
        foreach ($lockers as $index => $locker) {
            if ($locker['county'] === '' && preg_match('/^(\d{2})\d{4}$/', $locker['postalCode'], $m)) {
                $by_prefix[$m[1]][] = $locker['postalCode'];
            }
        }
        $prefix_county = array();
        foreach ($by_prefix as $prefix => $postcodes) {
            $prefix_county[$prefix] = self::prefix_county($api_client, (string) $prefix, array_values(array_unique($postcodes)));
        }
        $city_county = array();
        foreach ($lockers as $index => $locker) {
            if ($locker['county'] !== '') {
                continue;
            }
            $prefix = preg_match('/^(\d{2})\d{4}$/', $locker['postalCode'], $m) ? $m[1] : '';
            $county = ($prefix !== '' && isset($prefix_county[$prefix])) ? $prefix_county[$prefix] : '';
            if ($county === '' && $locker['city'] !== '') {
                $city_key = self::county_key($locker['city']);
                if (!array_key_exists($city_key, $city_county)) {
                    $city_county[$city_key] = self::city_county($api_client, $locker['city']);
                }
                $county = $city_county[$city_key];
            }
            $lockers[$index]['county'] = $county;
        }
        return $lockers;
    }

    /**
     * The county of a postcode prefix, from the catalogue, cached 7 days ('' when unknown)
     */
    private static function prefix_county($api_client, $prefix, $postcodes) {
        $cached = get_transient(self::PREFIX_CACHE . $prefix);
        if (is_string($cached)) {
            return $cached;
        }
        $failed = false;
        foreach (array_slice($postcodes, 0, 3) as $postcode) {
            $matches = $api_client->find_postcodes(array('postcode' => $postcode));
            if ($matches === false) {
                $failed = true;
                continue;
            }
            if (!empty($matches[0]['county'])) {
                $county = Livraria_Order_Handler::county_alias($matches[0]['county']);
                set_transient(self::PREFIX_CACHE . $prefix, $county, 7 * 86400);
                return $county;
            }
        }
        if (!$failed) {
            set_transient(self::PREFIX_CACHE . $prefix, '', 86400); // the catalogue has none: ask again tomorrow
        }
        return '';
    }

    /**
     * The county of a city name when the catalogue gives exactly one, else ''
     */
    private static function city_county($api_client, $city) {
        $cities = $api_client->find_cities($city);
        if (!is_array($cities)) {
            return '';
        }
        $wanted = self::county_key($city);
        $counties = array();
        foreach ($cities as $c) {
            if (is_array($c) && isset($c['name'], $c['county']) && self::county_key($c['name']) === $wanted) {
                $counties[self::county_key($c['county'])] = Livraria_Order_Handler::county_alias($c['county']);
            }
        }
        return count($counties) === 1 ? reset($counties) : '';
    }

    /**
     * A locker record by courier and id, from the cached list (fetched when
     * nothing is cached), or null
     *
     * @param Livraria_API_Client $api_client
     * @param string $courier
     * @param string $id
     * @return array|null
     */
    public static function find_locker($api_client, $courier, $id) {
        if (self::parse_courier($courier) === null || (string) $id === '') {
            return null;
        }
        $list = self::courier_list($api_client, $courier);
        if ($list === null) {
            return null;
        }
        foreach ($list['lockers'] as $locker) {
            if ((string) $locker['id'] === (string) $id) {
                return $locker;
            }
        }
        return null;
    }

    /**
     * Drop every cached list (login, logout, session end, settings save)
     */
    public static function forget_cache() {
        delete_transient('livraria_couriers'); // the courier list the Route resolves ids from
        delete_transient('livraria_courier_ids'); // the courier ids every quote request is checked against
        foreach (array_keys(self::COURIERS) as $courier) {
            delete_transient(self::CACHE_FRESH_PREFIX . $courier);
            delete_option(self::CACHE_STALE_PREFIX . $courier);
        }
    }

    // -------------------------------------------------------------------------
    // The buyer's pick at checkout
    // -------------------------------------------------------------------------

    /** Checkout copy is Romanian whatever the shop's language */
    const MSG_GATE = 'Alege un locker pentru a continua la finalizarea comenzii.';
    const MSG_HOME_OFF = 'Livrarea la adresă nu este disponibilă pentru acest magazin. Alege un locker.';
    const MSG_LOCKER_GONE = 'Lockerul ales nu mai este disponibil. Alege alt locker.';

    /**
     * The pick as the checkout sent it (classic form fields or Store API
     * extension data), cleaned
     *
     * @param array $input delivery_mode, locker_id, locker_name, locker_courier
     * @return array mode ('home', 'locker' or '' when nothing was sent), id, name, courier
     */
    public static function normalize_pick($input) {
        $input = is_array($input) ? $input : array();
        $value = function($key) use ($input) {
            return isset($input[$key]) && is_scalar($input[$key]) ? sanitize_text_field((string) $input[$key]) : '';
        };
        $mode = strtolower($value('delivery_mode'));
        if ($mode === 'easybox') {
            $mode = 'locker';
        }
        if ($mode !== 'home' && $mode !== 'locker') {
            $mode = '';
        }
        return array(
            'mode' => $mode,
            'id' => $value('locker_id'),
            'name' => $value('locker_name'),
            'courier' => strtolower($value('locker_courier')),
        );
    }

    /**
     * Why the order cannot be placed with this pick, '' when it can.
     * The gate: locker delivery chosen and no locker picked always blocks.
     * "Require locker" only takes home delivery away.
     *
     * @param array $pick normalize_pick() result
     * @param string|null $rate_id The chosen shipping rate; the gate applies only to a ticked one
     * @return string Romanian message, or ''
     */
    public static function validate_pick($pick, $rate_id = null) {
        if (empty(self::enabled_couriers())) {
            return ''; // the picker is not shown
        }
        if (!self::rate_offers_lockers($rate_id)) {
            return ''; // not a shipping method the merchant ticked: no locker choice
        }
        if ($pick['mode'] === 'locker') {
            if ($pick['id'] === '') {
                return self::MSG_GATE;
            }
            if (!self::courier_enabled($pick['courier'])) {
                return self::MSG_LOCKER_GONE;
            }
            return '';
        }
        if (self::require_locker()) {
            return $pick['mode'] === 'home' ? self::MSG_HOME_OFF : self::MSG_GATE;
        }
        return '';
    }

    /**
     * Write the pick on the order (HPOS-safe, through the order object; the
     * checkout saves the order). Home delivery clears any earlier locker.
     *
     * @param WC_Order $order
     * @param array $pick normalize_pick() result
     */
    public static function apply_pick($order, $pick) {
        if ($pick['mode'] === 'locker' && $pick['id'] !== '') {
            $order->update_meta_data(self::META_MODE, 'locker');
            $order->update_meta_data(self::META_ID, $pick['id']);
            $order->update_meta_data(self::META_NAME, $pick['name']);
            $order->update_meta_data(self::META_COURIER, $pick['courier']);
            return;
        }
        if ($pick['mode'] === 'home') {
            $order->update_meta_data(self::META_MODE, 'home');
            foreach (array(self::META_ID, self::META_NAME, self::META_COURIER) as $key) {
                $order->delete_meta_data($key);
            }
        }
    }

    /**
     * What the checkout script needs (classic and blocks)
     *
     * @return array
     */
    public static function checkout_config() {
        $couriers = array();
        foreach (self::enabled_couriers() as $slug) {
            $couriers[] = array('slug' => $slug, 'label' => self::COURIERS[$slug]['product']);
        }
        return array(
            'ajaxUrl' => function_exists('admin_url') ? admin_url('admin-ajax.php') : '',
            'action' => 'livraria_checkout_lockers',
            'nonce' => function_exists('wp_create_nonce') ? wp_create_nonce('livraria_checkout') : '',
            'couriers' => $couriers,
            'requireLocker' => self::require_locker(),
            'methods' => self::locker_methods(),
            'showModeChoice' => true,
            'strings' => array(
                'modeLabel' => 'Metodă de livrare',
                'modeHome' => 'Livrare la adresă',
                'modeLocker' => 'Livrare la locker',
                'filterAll' => 'Toate',
                'gate' => self::MSG_GATE,
                'countyLabel' => 'Județ',
                'countyAll' => 'Toate județele',
                'countyPick' => 'Alege județul',
                'cityLabel' => 'Oraș',
                'cityAll' => 'Toate orașele',
                'search' => 'Caută după nume sau adresă',
                'loading' => 'Se încarcă lockerele…',
                'error' => 'Nu am putut încărca lockerele.',
                'retry' => 'Reîncearcă',
                'select' => 'Alege',
                'change' => 'Schimbă',
                'clear' => 'Șterge',
                'clearFilters' => 'Șterge filtrele',
                'chosen' => 'Locker ales:',
                'showingFirst' => 'Se afișează primele {cap} din {total} lockere — rafinează căutarea.',
                'none' => 'Niciun locker nu corespunde căutării.',
            ),
        );
    }

    // -------------------------------------------------------------------------
    // Display
    // -------------------------------------------------------------------------

    /**
     * "easybox Sameday <name>" / "locker DPD <name>"
     *
     * @param array $locker id, name, courier
     * @return string
     */
    public static function display($locker) {
        if (!is_array($locker)) {
            return '';
        }
        $courier = isset($locker['courier']) ? $locker['courier'] : '';
        $product = isset(self::COURIERS[$courier]) ? self::COURIERS[$courier]['product'] : 'locker';
        $name = !empty($locker['name']) ? $locker['name'] : (isset($locker['id']) ? $locker['id'] : '');
        return trim($product . ' ' . $name);
    }
}
