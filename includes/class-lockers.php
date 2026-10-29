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
            $lockers = self::trim_lockers($payload, $courier);
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
     * @param Livraria_API_Client $api_client
     * @param string $courier_param
     * @param bool $storefront Trim each locker to storefront_entry()
     * @return array code, body, cache ('hit' | 'miss' | 'stale' | '')
     */
    public static function proxy_response($api_client, $courier_param, $storefront) {
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
        $lockers = $storefront ? array_map(array(__CLASS__, 'storefront_entry'), $list['lockers']) : $list['lockers'];
        return array(
            'code' => 200,
            'body' => array('couriers' => $couriers, 'lockers' => $lockers, 'failed' => $list['failed']),
            'cache' => $list['status'],
        );
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
     * @return string Romanian message, or ''
     */
    public static function validate_pick($pick) {
        if (empty(self::enabled_couriers())) {
            return ''; // the picker is not shown
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
            'showModeChoice' => true,
            'strings' => array(
                'modeLabel' => 'Metodă de livrare',
                'modeHome' => 'Livrare la adresă',
                'modeLocker' => 'Livrare la locker',
                'filterAll' => 'Toate',
                'gate' => self::MSG_GATE,
                'countyLabel' => 'Județ',
                'countyAll' => 'Toate județele',
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
