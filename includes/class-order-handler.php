<?php
/**
 * Order Handler for Livraria Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Livraria_Order_Handler {
    
    private $api_client;
    private $last_validation_errors = array();
    
    public function __construct($api_client) {
        $this->api_client = $api_client;
    }
    
    /** Order meta holding the unattended run's claim: a Unix timestamp */
    const AUTO_CLAIM_META = '_livraria_auto_create_claim';
    /** How long a claim holds before another run may take the order over */
    const AUTO_CLAIM_TTL = 600;

    /**
     * Get quotes for WooCommerce order (creates quote request)
     *
     * With $auto (the unattended run on a status change), the order is
     * claimed first (claim_order_for_auto_create()): a second run, or an
     * order that already has a shipment, is refused before any API call. A
     * run that cannot go on releases its claim and writes why on the order.
     * 
     * @param int $order_id WooCommerce order ID
     * @param array $custom_data Custom expedition data from admin interface
     * @param bool $auto The unattended run
     * @return array Result array with success status, quotes, and quoteRequestId
     */
    public function get_quotes_for_order($order_id, $custom_data = array(), $auto = false) {
        if (!$auto) {
            return $this->quote_order($order_id, $custom_data);
        }

        $claim = self::claim_order_for_auto_create($order_id);
        if (!$claim['claimed']) {
            return array('success' => false, 'message' => $claim['message'], 'claimRefused' => true);
        }

        $result = $this->quote_order($order_id, $custom_data);
        if ($result['success'] && empty($result['autoQuoteId'])) {
            $result = array(
                'success' => false,
                'message' => 'No courier can take this order automatically: ' . ($result['unusableSummary'] !== '' ? $result['unusableSummary'] : 'no usable quote.'),
            );
        }
        if (!$result['success']) {
            $this->auto_create_failed($order_id, $result['message'], true);
        }
        return $result;
    }

    /**
     * Claim the order for one unattended run. Refused when the order already
     * has a shipment, or another run claimed it less than AUTO_CLAIM_TTL
     * seconds ago. Fails safe: a claim whose timestamp cannot be read holds.
     *
     * @param mixed $order_or_id WC_Order or order id
     * @param int|null $now Unix time (tests)
     * @return array claimed (bool), message (why not)
     */
    public static function claim_order_for_auto_create($order_or_id, $now = null) {
        $now = $now === null ? time() : (int) $now;
        $order = self::order_object($order_or_id);
        if (!$order) {
            return array('claimed' => false, 'message' => 'Order not found');
        }
        if (self::get_order_meta($order, '_courier_expedition_id')) {
            return array('claimed' => false, 'message' => 'This order already has a shipment; no second one was created.');
        }
        $claim = self::get_order_meta($order, self::AUTO_CLAIM_META);
        if ($claim !== '') {
            $claimed_at = (is_numeric($claim) && (int) $claim > 0) ? (int) $claim : null;
            if ($claimed_at === null) {
                return array('claimed' => false, 'message' => 'An automatic shipment for this order is already marked as in progress. Create the shipment from the order panel.');
            }
            if ($now - $claimed_at < self::AUTO_CLAIM_TTL) {
                return array('claimed' => false, 'message' => 'An automatic shipment for this order started ' . max(0, $now - $claimed_at) . ' seconds ago and is still running; no second one was started.');
            }
        }
        self::set_order_meta($order, array(self::AUTO_CLAIM_META => (string) $now));
        return array('claimed' => true, 'message' => '');
    }

    /**
     * End the unattended run's claim
     *
     * @param mixed $order_or_id
     */
    public static function release_auto_create_claim($order_or_id) {
        self::delete_order_meta($order_or_id, array(self::AUTO_CLAIM_META));
    }

    /**
     * The unattended run stopped: say why on the order and, when nothing can
     * have been bought, release the claim so a later run may try again
     *
     * @param int $order_id
     * @param string $message
     * @param bool $release False when the outcome is unknown (no response to /expedition)
     */
    public function auto_create_failed($order_id, $message, $release) {
        $order = self::order_object($order_id);
        if ($order && method_exists($order, 'add_order_note')) {
            $order->add_order_note('Livraria: the automatic shipment was not created. ' . $message);
        }
        if ($release) {
            self::release_auto_create_claim($order_id);
        }
    }

    /**
     * Quote the order: validation, recipient, couriers, POST /public/awb/quotes
     *
     * @param int $order_id
     * @param array $custom_data
     * @return array
     */
    private function quote_order($order_id, $custom_data = array()) {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_order')) {
            return array('success' => false, 'message' => 'WooCommerce not found');
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return array('success' => false, 'message' => 'Order not found');
        }
        
        // Validate order has shipping address
        if (!$this->validate_order_for_shipping($order)) {
            $missing_fields_text = implode(', ', $this->last_validation_errors);
            return array(
                'success' => false, 
                'message' => 'Order is missing required information: ' . $missing_fields_text . '. Please complete these fields in the order (shipping address for delivery details, billing for contact info).'
            );
        }
        
        // The recipient first: an unusable phone stops here, before any API call
        $recipient = $this->build_recipient($order);
        if (isset($recipient['error'])) {
            return array('success' => false, 'message' => $recipient['error'], 'fix' => $recipient['fix'] ?? '');
        }
        
        try {
            // Get available couriers first (with caching)
            $courier_ids = $this->get_available_courier_ids();
            if (empty($courier_ids)) {
                $why = $this->api_client->last_error_message();
                return array('success' => false, 'message' => $why !== '' ? 'Could not load the courier list: ' . $why : 'No couriers available');
            }
            
            // Create quote request and get courier quotes
            $quote_request_data = $this->prepare_quote_request_data($order, $custom_data, $courier_ids, $recipient);
            
            // Debug: Log the data being sent (only in development mode)
            if (Livraria_API_Client::is_dev_mode()) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug logging only in development mode
                error_log('Livraria Debug: Quote request data structure: ' . print_r($quote_request_data, true));
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
                error_log('Livraria Debug: Has sender: ' . (isset($quote_request_data['sender']) ? 'yes' : 'no'));
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
                error_log('Livraria Debug: Has recipient: ' . (isset($quote_request_data['recipient']) ? 'yes' : 'no'));
            }
            
            $quotes_response = $this->api_client->create_quote_request($quote_request_data);
            
            if (!$quotes_response) {
                return array('success' => false, 'message' => $this->api_error_message('Could not get quotes'));
            }
            
            // Extract quotes and quoteRequestId from response
            $quote_request_id = null;
            $courier_quotes = array();
            
            if (isset($quotes_response['quoteRequest']['id'])) {
                $quote_request_id = $quotes_response['quoteRequest']['id'];
            } elseif (isset($quotes_response['quoteRequestId'])) {
                $quote_request_id = $quotes_response['quoteRequestId'];
            }
            
            if (isset($quotes_response['courierQuotes'])) {
                $courier_quotes = $quotes_response['courierQuotes'];
            } elseif (isset($quotes_response['data'])) {
                $courier_quotes = is_array($quotes_response['data']) ? $quotes_response['data'] : array($quotes_response['data']);
            } elseif (isset($quotes_response['quotes'])) {
                $courier_quotes = is_array($quotes_response['quotes']) ? $quotes_response['quotes'] : array($quotes_response['quotes']);
            }
            
            // Every door-to-door quote, disabled ones included (greyed, last)
            $filtered_quotes = self::normalize_quotes($courier_quotes);
            
            if (empty($filtered_quotes)) {
                return array('success' => false, 'message' => 'No courier quotes available');
            }
            $best_quote = self::select_best_quote($filtered_quotes);
            
            if (!$quote_request_id) {
                return array('success' => false, 'message' => 'Quote request ID not found in response');
            }
            
            // Store quote request ID in order meta temporarily
            self::set_order_meta($order_id, array('_courier_quote_request_id' => $quote_request_id));
            
            return array(
                'success' => true,
                'quotes' => $filtered_quotes,
                'quoteRequestId' => $quote_request_id,
                // What an unattended run ships with: the cheapest usable quote, or null
                'autoQuoteId' => $best_quote ? $best_quote['id'] : null,
                'unusableSummary' => self::summarise_unusable_quotes($filtered_quotes),
                'message' => 'Quotes retrieved successfully'
            );
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Exception: ' . $e->getMessage());
        }
    }
    
    /**
     * Create expedition for WooCommerce order
     * 
     * @param int $order_id WooCommerce order ID
     * @param array $custom_data Custom expedition data from admin interface
     * @return array Result array with success status and message
     */
    public function create_expedition_for_order($order_id, $custom_data = array()) {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_order')) {
            return array('success' => false, 'message' => 'WooCommerce not found');
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return array('success' => false, 'message' => 'Order not found');
        }
        
        // Check if expedition already exists
        if (self::get_order_meta($order_id, '_courier_expedition_id')) {
            return array('success' => false, 'message' => 'Expedition already exists for this order');
        }
        
        // Validate order has shipping address
        if (!$this->validate_order_for_shipping($order)) {
            $missing_fields_text = implode(', ', $this->last_validation_errors);
            return array(
                'success' => false, 
                'message' => 'Order is missing required information: ' . $missing_fields_text . '. Please complete these fields in the order (shipping address for delivery details, billing for contact info).'
            );
        }
        
        try {
            // Step 1: Get available couriers first (with caching)
            $courier_ids = $this->get_available_courier_ids();
            if (empty($courier_ids)) {
                return array('success' => false, 'message' => 'No couriers available');
            }
            
            // Step 2: Create quote request and get courier quotes in one call
            $quote_request_data = $this->prepare_quote_request_data($order, $custom_data, $courier_ids);
            
            // Debug: Log the data being sent (only in development mode)
            if (Livraria_API_Client::is_dev_mode()) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug logging only in development mode
                error_log('Livraria Debug: Quote request data: ' . print_r($quote_request_data, true));
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug logging only in development mode
                error_log('Livraria Debug: Available courier IDs: ' . print_r($courier_ids, true));
            }
            
            $quotes_response = $this->api_client->create_quote_request($quote_request_data);
            
            if (!$quotes_response || !isset($quotes_response['courierQuotes'])) {
                return array('success' => false, 'message' => 'Failed to create quote request or get courier quotes');
            }
            
            // The new API returns courierQuotes directly, we'll need to create a quote request separately
            // For now, we'll use a placeholder ID
            $quote_request_id = 'placeholder-' . time();
            $courier_quotes = $quotes_response['courierQuotes'];
            
            if (empty($courier_quotes)) {
                return array('success' => false, 'message' => 'No courier quotes available');
            }
            
            // Step 2: Select best quote
            $selected_quote = self::select_best_quote(self::normalize_quotes($courier_quotes));
            if ($selected_quote === null) {
                return array('success' => false, 'message' => 'No usable courier quote: ' . self::summarise_unusable_quotes(self::normalize_quotes($courier_quotes)));
            }
            
            // Step 3: Create expedition
            $expedition_data = array(
                'quoteRequestId' => $quote_request_id,
                'courierQuoteId' => $selected_quote['id'],
                'billingInfo' => $this->prepare_billing_info($order),
                'notes' => 'WooCommerce Order #' . $order->get_order_number(),
                'pickupDate' => $this->calculate_pickup_date()
            );
            
            $expedition_response = $this->api_client->create_expedition($expedition_data);
            
            if (!$expedition_response || !isset($expedition_response['id'])) {
                return array('success' => false, 'message' => 'Failed to create expedition');
            }
            
            // Step 5: Save expedition data
            $this->save_expedition_data($order_id, $expedition_response, $selected_quote);
            
            // Step 6: Add order note
            $this->add_expedition_order_note($order, $expedition_response);
            
            return array(
                'success' => true, 
                'message' => 'Expedition created successfully',
                'expedition_id' => $expedition_response['id'],
                'awb_number' => $expedition_response['awbNumber'] ?? null
            );
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Exception: ' . $e->getMessage());
        }
    }
    
    /**
     * "<what failed>: <the backend's words>" for the last failed API call
     *
     * @param string $what What the plugin was trying to do
     * @return string
     */
    public function api_error_message($what) {
        $message = $this->api_client->last_error_message();
        if ($message === '') {
            $message = 'the Livraria API did not say why';
        }
        return $what . ': ' . $message;
    }

    /**
     * Validate order has required shipping information
     * 
     * @param WC_Order $order
     * @return bool
     */
    private function validate_order_for_shipping($order) {
        $required_fields = array(
            'shipping_first_name' => 'Shipping First Name',
            'shipping_last_name' => 'Shipping Last Name',
            'shipping_address_1' => 'Shipping Address',
            'shipping_city' => 'Shipping City',
            'shipping_postcode' => 'Shipping Postal Code',
            'shipping_country' => 'Shipping Country',
            // Keep email and phone from billing since they're not typically in shipping
            // The phone is checked by build_recipient() (shipping, then billing).
            // The email is optional: a courier that needs it answers with a
            // disabled quote (RECIPIENT_FIELDS_REQUIRED) and the panel offers
            // "Add email".
        );
        
        $missing_fields = array();
        
        foreach ($required_fields as $field => $label) {
            $method = 'get_' . $field;
            if (method_exists($order, $method)) {
                $value = $order->$method();
                
                // Special handling: if shipping field is empty, try to use billing equivalent
                if (empty($value) && strpos($field, 'shipping_') === 0) {
                    $billing_field = str_replace('shipping_', 'billing_', $field);
                    $billing_method = 'get_' . $billing_field;
                    if (method_exists($order, $billing_method)) {
                        $value = $order->$billing_method();
                    }
                }
                
                if (empty($value)) {
                    $missing_fields[] = $label;
                }
            }
        }
        
        if (!empty($missing_fields)) {
            // Store missing fields for error message
            $this->last_validation_errors = $missing_fields;
            return false;
        }
        
        return true;
    }
    
    /** Payment method titles that mean the courier collects the money (lowercase) */
    const COD_TITLE_INDICATORS = array('cash on delivery', 'payment on delivery', 'pay on delivery', 'ramburs', 'numerar');

    /**
     * Whether the buyer pays the courier: WooCommerce's own COD gateway (id
     * "cod"), or a payment method title naming cash / payment on delivery,
     * ramburs or numerar
     *
     * @param WC_Order $order
     * @return bool
     */
    public static function is_cod_order($order) {
        if (!$order || !method_exists($order, 'get_payment_method')) {
            return false;
        }
        if ((string) $order->get_payment_method() === 'cod') {
            return true;
        }
        $title = (string) $order->get_payment_method_title();
        $title = function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
        foreach (self::COD_TITLE_INDICATORS as $indicator) {
            if (strpos($title, $indicator) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * The quote request's service block. "cod" is there only when there is
     * something to collect (a COD order, or an amount typed in the panel):
     * the backend reads its presence as "this is a COD shipment".
     *
     * @param array $service_options get_service_options() result
     * @param mixed $sender_profile For the COD IBAN
     * @return array
     */
    private function build_service($service_options, $sender_profile) {
        $service = array(
            'saturdayDelivery' => $service_options['saturday_delivery'],
            'openOnDelivery' => $service_options['open_on_delivery'],
        );
        if ($service_options['cod_amount'] > 0) {
            $service['cod'] = $this->build_cod_object($service_options['cod_amount'], $sender_profile);
        }
        $service['insurance'] = array('amount' => $service_options['insurance_amount']);
        return $service;
    }

    /**
     * Build COD object for quote request
     * Includes bankAccount if COD amount > 0 and sender profile has codIban
     * 
     * @param float $cod_amount COD amount
     * @param mixed $sender_profile Sender profile from API
     * @return array COD object
     */
    private function build_cod_object($cod_amount, $sender_profile) {
        $cod_obj = array(
            'amount' => $cod_amount,
            'collector' => 'CLIENT'
        );
        
        // Add bankAccount if COD amount > 0 and sender profile has codIban
        if ($cod_amount > 0 && $sender_profile) {
            $sender_profile = (array) $sender_profile;
            $cod_iban = $sender_profile['codIban'] ?? null;
            
            if (!empty($cod_iban)) {
                $cod_obj['bankAccount'] = $cod_iban;
            }
        }
        
        return $cod_obj;
    }
    
    /**
     * Remove Romanian diacritics from string
     * 
     * @param string $str String with Romanian diacritics
     * @return string String without diacritics
     */
    private function remove_romanian_diacritics($str) {
        if (empty($str)) {
            return $str;
        }
        
        $diacritics_map = array(
            'ă' => 'a', 'Ă' => 'A',
            'â' => 'a', 'Â' => 'A',
            'î' => 'i', 'Î' => 'I',
            'ș' => 's', 'Ș' => 'S', 'ş' => 's', 'Ş' => 'S',
            'ț' => 't', 'Ț' => 'T', 'ţ' => 't', 'Ţ' => 'T',
        );
        
        return strtr($str, $diacritics_map);
    }
    
    /**
     * Split a phone number into a country code and a national significant
     * number (NSN), as the Livraria API wants it: phoneCountryCode "+40" and
     * a 9-digit phoneNSN starting 7, 2 or 3 for Romania.
     *
     * Romanian numbers turn up in every shape: 0722 123 456, 0722.123.456,
     * +40722123456, 0040722123456, 40722123456, 722123456, +40 0722 123 456.
     * A number with another country code is accepted when it is written with
     * "+" (or "00"). Anything else comes back with an empty country code, and
     * phone_problem() turns that into a stop. Ported from the PrestaShop
     * module (OrderHandler::normalizePhone).
     *
     * @param string $phone As typed
     * @return array phoneCountryCode, phoneNSN
     */
    public static function normalize_phone($phone) {
        $raw = preg_replace('/[^\d+]/', '', (string) $phone);
        if ($raw === '' || $raw === null) {
            return array('phoneCountryCode' => '', 'phoneNSN' => '');
        }

        // 00 as the international prefix
        if (strpos($raw, '00') === 0) {
            $raw = '+' . substr($raw, 2);
        }
        $digits = preg_replace('/\D/', '', $raw);

        if (strpos($raw, '+40') === 0
            || (strpos($raw, '+') !== 0 && (strpos($digits, '40') === 0 || strpos($digits, '0') === 0 || preg_match('/^[723]\d{8}$/', $digits)))) {
            $national = $digits;
            if (strpos($national, '40') === 0 && strlen($national) > 9) {
                $national = substr($national, 2);
            }
            // Trunk zero, including the "+40 0722..." double prefix
            while (strpos($national, '0') === 0 && strlen($national) > 9) {
                $national = substr($national, 1);
            }
            return array('phoneCountryCode' => '+40', 'phoneNSN' => $national);
        }

        if (strpos($raw, '+') === 0) {
            // Longest match first, so "+36" is never read as "+367"
            $known = array(
                '+373', '+359', '+355', '+356', '+357', '+358', '+350', '+351', '+352', '+353', '+354',
                '+380', '+381', '+382', '+383', '+385', '+386', '+387', '+389',
                '+420', '+421', '+423', '+370', '+371', '+372', '+374', '+375', '+376', '+377', '+378',
                '+30', '+31', '+32', '+33', '+34', '+36', '+39', '+41', '+43', '+44', '+45',
                '+46', '+47', '+48', '+49', '+90', '+20', '+27', '+7', '+1',
            );
            foreach ($known as $code) {
                $bare = substr($code, 1);
                if (strpos($digits, $bare) === 0) {
                    return array('phoneCountryCode' => $code, 'phoneNSN' => ltrim(substr($digits, strlen($bare)), '0'));
                }
            }
            // Unknown code: two digits is the commonest length in Europe
            return array('phoneCountryCode' => '+' . substr($digits, 0, 2), 'phoneNSN' => substr($digits, 2));
        }

        return array('phoneCountryCode' => '', 'phoneNSN' => $digits);
    }

    /**
     * Why a normalised phone cannot be sent, '' when it can
     *
     * @param array $parsed normalize_phone() result
     * @return string '', 'missing', 'unplaceable' or 'invalid_ro'
     */
    public static function phone_problem($parsed) {
        if ($parsed['phoneNSN'] === '' && $parsed['phoneCountryCode'] === '') {
            return 'missing';
        }
        if ($parsed['phoneCountryCode'] === '' || $parsed['phoneNSN'] === '' || strlen($parsed['phoneNSN']) < 4) {
            return 'unplaceable';
        }
        if ($parsed['phoneCountryCode'] === '+40' && !preg_match('/^[723]\d{8}$/', $parsed['phoneNSN'])) {
            return 'invalid_ro';
        }
        return '';
    }

    /**
     * The recipient phone as typed: the shipping phone (WooCommerce 5.6+),
     * else the billing phone
     *
     * @param WC_Order $order
     * @return array phone (trimmed, '' if none), source ('shipping', 'billing' or '')
     */
    public static function recipient_phone($order) {
        $candidates = array(
            'shipping' => method_exists($order, 'get_shipping_phone') ? $order->get_shipping_phone() : '',
            'billing' => method_exists($order, 'get_billing_phone') ? $order->get_billing_phone() : '',
        );
        foreach ($candidates as $source => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return array('phone' => $value, 'source' => $source);
            }
        }
        return array('phone' => '', 'source' => '');
    }

    /**
     * County spellings the catalogue does not use, as they reach us from a
     * shop's state list, keyed by their normalised form
     */
    const COUNTY_ALIASES = array(
        'DIMBOVITA' => 'DAMBOVITA',
        'SATU-MARE' => 'SATU MARE',
        'VALCEA' => 'VILCEA',
    );

    /** How long a resolved locality is cached (a shop re-quotes the same towns) */
    const LOCALITY_CACHE_TTL = 604800; // 7 days

    /**
     * Uppercase, trimmed, Romanian diacritics stripped: how localities reach the couriers
     *
     * @param string $value
     * @return string
     */
    public static function normalize_locality($value) {
        $map = array(
            'ă' => 'a', 'Ă' => 'A', 'â' => 'a', 'Â' => 'A', 'î' => 'i', 'Î' => 'I',
            'ș' => 's', 'Ș' => 'S', 'ş' => 's', 'Ş' => 'S', 'ț' => 't', 'Ț' => 'T', 'ţ' => 't', 'Ţ' => 'T',
        );
        return strtoupper(trim(preg_replace('/\s+/u', ' ', strtr((string) $value, $map))));
    }

    /**
     * A county name normalised, with COUNTY_ALIASES applied
     *
     * @param string $county
     * @return string
     */
    public static function county_alias($county) {
        $county = self::normalize_locality($county);
        return isset(self::COUNTY_ALIASES[$county]) ? self::COUNTY_ALIASES[$county] : $county;
    }

    /**
     * Comparison key for two county spellings (aliases applied, letters only)
     */
    private static function county_key($county) {
        return preg_replace('/[^A-Z]/', '', self::county_alias($county));
    }

    /**
     * The recipient's county and city as the courier catalogue spells them.
     *
     * The WooCommerce state (code -> name) is only a hint. In order:
     * GET /public/location/cities by the city name, exact name matches,
     * narrowed by the hint, then by postcode; else GET
     * /public/location/postcodes by postcode. A result is cached in a
     * transient. When the lookup fails or finds nothing, the hint is used as
     * before (uppercase, diacritics stripped, aliases applied), with a debug
     * line saying so.
     *
     * @param string $state WooCommerce state code (e.g. "CJ") or name
     * @param string $city City as typed on the order
     * @param string $postcode
     * @return array county, city, source ('catalogue', 'postcodes' or 'state')
     */
    public function resolve_locality($state, $city, $postcode) {
        $hint = $state;
        if ($state !== '' && function_exists('WC') && WC() && isset(WC()->countries) && WC()->countries) {
            $states = WC()->countries->get_states('RO');
            if (is_array($states) && isset($states[$state])) {
                $hint = $states[$state];
            }
        }
        $hint = self::county_alias(html_entity_decode((string) $hint, ENT_QUOTES, 'UTF-8'));
        $city_n = self::normalize_locality($city);
        $postcode = trim((string) $postcode);
        $fallback = array('county' => $hint, 'city' => $city_n, 'source' => 'state');

        if ($city_n === '' && $postcode === '') {
            return $fallback;
        }

        $cache_key = 'livraria_loc_' . md5($hint . '|' . $city_n . '|' . $postcode);
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['county'], $cached['city'])) {
            return $cached;
        }

        $resolved = null;
        $failed = false;

        if ($city_n !== '') {
            $cities = $this->api_client->find_cities($city);
            if ($cities === false) {
                $failed = true;
            } else {
                $resolved = self::pick_city($cities, $city_n, $hint, $postcode);
            }
        }

        if ($resolved === null && $postcode !== '') {
            $matches = $this->api_client->find_postcodes(array('postcode' => $postcode));
            if ($matches === false) {
                $failed = true;
            } elseif (!empty($matches[0]['county'])) {
                $resolved = array('county' => self::normalize_locality($matches[0]['county']), 'city' => $city_n, 'source' => 'postcodes');
            }
        }

        if ($resolved !== null) {
            set_transient($cache_key, $resolved, self::LOCALITY_CACHE_TTL);
            return $resolved;
        }

        if (Livraria_API_Client::is_dev_mode()) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode, no order data
            error_log('Livraria Debug: county ' . ($failed ? 'lookup failed (' . $this->api_client->last_error_message() . ')' : 'not found in the catalogue') . '; using the order\'s state as the county');
        }
        return $fallback;
    }

    /**
     * The one catalogue locality that fits, or null
     *
     * @param array $cities find_cities() result
     * @param string $city_n Normalised city
     * @param string $hint Normalised county hint ('' for none)
     * @param string $postcode
     * @return array|null county, city, source
     */
    private static function pick_city($cities, $city_n, $hint, $postcode) {
        $exact = array_values(array_filter($cities, function($c) use ($city_n) {
            return is_array($c) && isset($c['name'], $c['county']) && self::normalize_locality($c['name']) === $city_n;
        }));
        if ($hint !== '') {
            $hint_key = self::county_key($hint);
            $by_hint = array_values(array_filter($exact, function($c) use ($hint_key) {
                return self::county_key($c['county']) === $hint_key;
            }));
            if (!empty($by_hint)) {
                $exact = $by_hint;
            }
        }
        if (count($exact) > 1 && $postcode !== '') {
            $by_postcode = array_values(array_filter($exact, function($c) use ($postcode) {
                return isset($c['postcode']) && (string) $c['postcode'] === $postcode;
            }));
            if (!empty($by_postcode)) {
                $exact = $by_postcode;
            }
        }
        $counties = array_unique(array_map(function($c) {
            return self::normalize_locality($c['county']);
        }, $exact));
        if (count($counties) !== 1) {
            return null;
        }
        return array('county' => reset($counties), 'city' => self::normalize_locality($exact[0]['name']), 'source' => 'catalogue');
    }

    /** The API's limit on address.street */
    const MAX_STREET_LENGTH = 100;

    /**
     * Address line 1 and line 2 as one street line, at most 100 characters
     *
     * @param string $line1
     * @param string $line2
     * @return string
     */
    public static function fold_street($line1, $line2) {
        $street = trim(preg_replace('/\s+/u', ' ', trim((string) $line1) . ' ' . trim((string) $line2)));
        return function_exists('mb_substr') ? mb_substr($street, 0, self::MAX_STREET_LENGTH, 'UTF-8') : substr($street, 0, self::MAX_STREET_LENGTH);
    }

    /**
     * The recipient of the quote request, or array('error' => message,
     * 'fix' => order field the merchant can fill in) when the order cannot be
     * shipped as it is. Runs before any API call.
     *
     * @param WC_Order $order
     * @return array
     */
    public function build_recipient($order) {
        $phone = self::recipient_phone($order);
        $parsed = self::normalize_phone($phone['phone']);
        $problem = self::phone_problem($parsed);
        if ($problem === 'missing' && $phone['phone'] !== '') {
            $problem = 'unplaceable'; // typed, but no digits in it
        }
        switch ($problem) {
            case 'missing':
                return array(
                    'error' => 'The order has no recipient phone number (shipping or billing phone). Couriers refuse a shipment without one; add it to the order.',
                    'fix' => 'phone',
                );
            case 'unplaceable':
                return array(
                    'error' => 'The recipient phone "' . $phone['phone'] . '" (' . $phone['source'] . ' phone) cannot be used: it is neither a Romanian number (07XX XXX XXX) nor an international number starting with + and its country code. Correct it on the order.',
                    'fix' => 'phone',
                );
            case 'invalid_ro':
                return array(
                    'error' => 'The recipient phone "' . $phone['phone'] . '" (' . $phone['source'] . ' phone) is not a valid Romanian number: couriers need 10 digits starting 07, 02 or 03. Correct it on the order.',
                    'fix' => 'phone',
                );
        }

        $recipient_name = trim($this->get_shipping_or_billing($order, 'first_name') . ' ' . $this->get_shipping_or_billing($order, 'last_name'));

        // County and city from the courier catalogue; the shop's state is a hint
        $locality = $this->resolve_locality(
            trim((string) $this->get_shipping_or_billing($order, 'state')),
            (string) $this->get_shipping_or_billing($order, 'city'),
            trim((string) $this->get_shipping_or_billing($order, 'postcode'))
        );
        $recipient_county = $locality['county'];
        $recipient_city = $locality['city'];

        $recipient = array(
            'name' => $recipient_name,
            'phoneCountryCode' => $parsed['phoneCountryCode'],
            'phoneNSN' => $parsed['phoneNSN'],
            'isCompany' => false,
            'address' => array(
                'country' => 'Romania',
                'county' => $recipient_county,
                'city' => $recipient_city,
                'postcode' => $this->get_shipping_or_billing($order, 'postcode'),
                // Both address lines in street: couriers print streetNumber as
                // the house number, and line 2 is "Bl. D6, sc. A, ap. 5"
                'street' => self::fold_street($this->get_shipping_or_billing($order, 'address_1'), $this->get_shipping_or_billing($order, 'address_2')),
                'streetNumber' => '',
                'block' => '',
                'staircase' => '',
                'floor' => '',
                'apartment' => '',
            )
        );

        // Optional: sent only when the order has one (a blank email fails the API's email check)
        $email = trim((string) $order->get_billing_email());
        if ($email !== '') {
            $recipient['email'] = $email;
        }
        return $recipient;
    }

    /**
     * Fill in a recipient contact field on the WooCommerce ORDER (never on
     * the Livraria side), from the order panel's "Add email" / "Add phone":
     * - email: the billing email;
     * - phone: the field build_recipient() reads first (the shipping phone
     *   when it has one, else the billing phone).
     * The value is checked first; a note on the order records the change.
     *
     * @param int $order_id
     * @param string $field 'email' or 'phone'
     * @param string $value
     * @return array success, message
     */
    public function update_order_contact($order_id, $field, $value) {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
        if (!$order) {
            return array('success' => false, 'message' => 'Order not found');
        }
        $value = trim((string) $value);

        if ($field === 'email') {
            if ($value === '' || !is_email($value)) {
                return array('success' => false, 'message' => 'Enter a valid email address.');
            }
            $order->set_billing_email($value);
            $order->add_order_note('Livraria: recipient email added to the order (billing email) from the shipping panel.');
            $order->save();
            return array('success' => true, 'message' => 'Email saved on the order.');
        }

        if ($field === 'phone') {
            if (self::phone_problem(self::normalize_phone($value)) !== '') {
                return array('success' => false, 'message' => 'Enter a phone number couriers can use: a Romanian number (07XX XXX XXX) or an international one starting with + and its country code.');
            }
            $current = self::recipient_phone($order);
            if ($current['source'] === 'shipping' && method_exists($order, 'set_shipping_phone')) {
                $order->set_shipping_phone($value);
                $where = 'shipping phone';
            } else {
                $order->set_billing_phone($value);
                $where = 'billing phone';
            }
            $order->add_order_note('Livraria: recipient phone set on the order (' . $where . ') from the shipping panel.');
            $order->save();
            return array('success' => true, 'message' => 'Phone saved on the order.');
        }

        return array('success' => false, 'message' => 'This field cannot be filled in from the shipping panel.');
    }

    /**
     * Sanitize shop name for use in externalRef
     * 
     * @return string Sanitized shop name
     */
    private function sanitize_shop_name() {
        $shop_name = get_bloginfo('name');
        if (empty($shop_name)) {
            return 'shop';
        }
        
        // Convert to lowercase
        $sanitized = strtolower($shop_name);
        
        // Remove diacritics
        $sanitized = $this->remove_romanian_diacritics($sanitized);
        
        // Replace spaces and special characters with hyphens
        $sanitized = preg_replace('/[^a-z0-9]+/', '-', $sanitized);
        
        // Remove leading/trailing hyphens
        $sanitized = trim($sanitized, '-');
        
        // Limit length to 50 characters
        $sanitized = substr($sanitized, 0, 50);
        
        // If empty after sanitization, use fallback
        if (empty($sanitized)) {
            return 'shop';
        }
        
        return $sanitized;
    }
    
    /**
     * Prepare quote request data from WooCommerce order
     * 
     * @param WC_Order $order
     * @param array $custom_data Custom expedition data
     * @param array $courier_ids Available courier IDs
     * @param array|null $recipient build_recipient() result, built here when null
     * @return array
     */
    private function prepare_quote_request_data($order, $custom_data = array(), $courier_ids = array(), $recipient = null) {
        $sender_profile = $this->get_sender_profile_from_api();
        $sender_info = $this->get_sender_info();
        $packages = $this->calculate_packages_from_order($order, $custom_data);
        $service_options = $this->get_service_options($order, $custom_data);
        
        // Parse sender phone number
        $sender_phone = $sender_info['phone'] ?: '';
        if ($sender_profile && isset($sender_profile['phoneCountryCode']) && isset($sender_profile['phoneNSN'])) {
            $sender_phone_parsed = array(
                'phoneCountryCode' => $sender_profile['phoneCountryCode'],
                'phoneNSN' => $sender_profile['phoneNSN']
            );
        } else {
            $sender_phone_parsed = self::normalize_phone($sender_phone);
        }
        
        // Get sender address - use profile directly if available
        $sender_address = array();
        if ($sender_profile) {
            $sender_profile = (array) $sender_profile;
            
            // First, check if address fields are at top level (matching Shopify extension structure)
            if (isset($sender_profile['street']) || isset($sender_profile['city']) || isset($sender_profile['country'])) {
                // Address fields are at top level
                $sender_address = array(
                    'country' => $sender_profile['country'] ?? '',
                    'county' => $sender_profile['county'] ?? '',
                    'city' => $sender_profile['city'] ?? '',
                    'postcode' => $sender_profile['postcode'] ?? '',
                    'street' => $sender_profile['street'] ?? '',
                    'streetNumber' => $sender_profile['streetNumber'] ?? '',
                    'block' => $sender_profile['block'] ?? '',
                    'staircase' => $sender_profile['staircase'] ?? '',
                    'floor' => $sender_profile['floor'] ?? '',
                    'apartment' => $sender_profile['apartment'] ?? '',
                );
            } elseif (isset($sender_profile['address']) && (is_array($sender_profile['address']) || is_object($sender_profile['address']))) {
                // Address is nested in 'address' field
                $nested_address = (array) $sender_profile['address'];
                $sender_address = array(
                    'country' => $nested_address['country'] ?? '',
                    'county' => $nested_address['county'] ?? '',
                    'city' => $nested_address['city'] ?? '',
                    'postcode' => $nested_address['postcode'] ?? '',
                    'street' => $nested_address['street'] ?? '',
                    'streetNumber' => $nested_address['streetNumber'] ?? '',
                    'block' => $nested_address['block'] ?? '',
                    'staircase' => $nested_address['staircase'] ?? '',
                    'floor' => $nested_address['floor'] ?? '',
                    'apartment' => $nested_address['apartment'] ?? '',
                );
            } else {
                // Try to find address in any nested object
                foreach ($sender_profile as $field_key => $value) {
                    if (is_array($value) || is_object($value)) {
                        $value = (array) $value;
                        if (isset($value['street']) || isset($value['city']) || isset($value['country'])) {
                            $sender_address = array(
                                'country' => $value['country'] ?? '',
                                'county' => $value['county'] ?? '',
                                'city' => $value['city'] ?? '',
                                'postcode' => $value['postcode'] ?? '',
                                'street' => $value['street'] ?? '',
                                'streetNumber' => $value['streetNumber'] ?? '',
                                'block' => $value['block'] ?? '',
                                'staircase' => $value['staircase'] ?? '',
                                'floor' => $value['floor'] ?? '',
                                'apartment' => $value['apartment'] ?? '',
                            );
                            break;
                        }
                    }
                }
            }
        }
        
        // Fallback to get_sender_address() if profile doesn't have address fields
        if (empty($sender_address['street'])) {
            try {
                $fallback_address = $this->get_sender_address();
                $sender_address = array_merge($sender_address, $fallback_address);
            } catch (Exception $e) {
                // If get_sender_address throws exception, use settings (no defaults)
                $sender_address = array(
                    'country' => get_option('courier_sender_country', ''),
                    'county' => get_option('courier_sender_county', ''),
                    'city' => get_option('courier_sender_city', ''),
                    'postcode' => get_option('courier_sender_postcode', ''),
                    'street' => get_option('courier_sender_street', ''),
                    'streetNumber' => get_option('courier_sender_street_number', ''),
                    'block' => get_option('courier_sender_block', ''),
                    'staircase' => get_option('courier_sender_staircase', ''),
                    'floor' => get_option('courier_sender_floor', ''),
                    'apartment' => get_option('courier_sender_apartment', ''),
                );
            }
        }
        
        $sender_address['localityPostcode'] = $sender_address['postcode'] ?? '';
        
        // Build sender object - ensure proper data types
        $sender_is_company = isset($sender_info['isCompany']) ? (bool)$sender_info['isCompany'] : false;
        
        // Uppercase and remove diacritics from sender county and city
        $sender_county = !empty($sender_address['county']) 
            ? strtoupper($this->remove_romanian_diacritics($sender_address['county'])) 
            : '';
        $sender_city = !empty($sender_address['city']) 
            ? strtoupper($this->remove_romanian_diacritics($sender_address['city'])) 
            : '';
        
        $sender_obj = array(
            'name' => $sender_info['name'] ?? '',
            'phoneCountryCode' => $sender_phone_parsed['phoneCountryCode'],
            'phoneNSN' => $sender_phone_parsed['phoneNSN'],
            'email' => $sender_info['email'] ?? '',
            'address' => array(
                'country' => 'Romania',
                'county' => $sender_county,
                'city' => $sender_city,
                'postcode' => $sender_address['postcode'] ?? '',
                'street' => $sender_address['street'] ?? '',
                'streetNumber' => $sender_address['streetNumber'] ?? '',
                'block' => $sender_address['block'] ?? '',
                'staircase' => $sender_address['staircase'] ?? '',
                'floor' => $sender_address['floor'] ?? '',
                'apartment' => $sender_address['apartment'] ?? '',
            ),
            'isCompany' => $sender_is_company,
            'companyName' => $sender_is_company ? ($sender_info['name'] ?? '') : '',
            'cui' => $sender_info['cui'] ?? '',
        );
        
        // Build recipient object (build_recipient() stops on an unusable phone)
        $recipient_obj = $recipient !== null ? $recipient : $this->build_recipient($order);
        if (isset($recipient_obj['error'])) {
            throw new Exception($recipient_obj['error']);
        }
        
        // Debug: Log sender and recipient data (only in development mode)
        if (Livraria_API_Client::is_dev_mode()) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
            error_log('Livraria Debug: Sender object: ' . PHP_EOL . json_encode($sender_obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
            error_log('Livraria Debug: Recipient object: ' . PHP_EOL . json_encode($recipient_obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        
        return array(
                'sender' => $sender_obj,
                'recipient' => $recipient_obj,
                'content' => array(
                    'packages' => $packages
                ),
                'packageDescription' => !empty($custom_data['content_description']) ? 
                    $custom_data['content_description'] : 
                    $this->generate_package_description($order),
                'shipmentNote' => 'Order from ' . get_bloginfo('name') . ' - Order #' . $order->get_order_number(),
                'externalRef' => 'WP-' . $this->sanitize_shop_name() . '-' . $order->get_id(),
                'service' => $this->build_service($service_options, $sender_profile),
                'paymentInfo' => array(
                    'courierServicePayer' => 'THIRD_PARTY'
                )
        );
    }
    
    /**
     * Get sender profile from API
     * Falls back to settings if API profile not available
     * 
     * @return array|null Sender profile data or null if not available
     */
    private function get_sender_profile_from_api() {
        $sender_profile = $this->api_client->get_sender_profile();
        
        if ($sender_profile === false || is_wp_error($sender_profile)) {
            return null;
        }
        
        // Handle array of profiles - use first one
        if (is_array($sender_profile) && isset($sender_profile[0])) {
            return $sender_profile[0];
        }
        
        return is_array($sender_profile) ? $sender_profile : null;
    }
    
    /**
     * Get sender address from API sender profile
     * Uses the same logic as the admin page display to find address fields
     * Falls back to plugin settings if API profile not available
     * 
     * @return array
     * @throws Exception If required address fields are missing
     */
    private function get_sender_address() {
        $sender_profile = $this->get_sender_profile_from_api();
        
        // If we have a sender profile from API, use it
        if ($sender_profile) {
            $sender_profile = (array) $sender_profile;
            
            // Use the same logic as render_sender_profile() in admin page
            // Iterate through all fields and find address-like nested objects
            $address_data = null;
            
            // First, check if there's a direct 'address' field
            if (isset($sender_profile['address']) && (is_array($sender_profile['address']) || is_object($sender_profile['address']))) {
                $address_data = (array) $sender_profile['address'];
            } else {
                // Check if address fields are at top level
                if (isset($sender_profile['street']) || isset($sender_profile['city']) || isset($sender_profile['country'])) {
                    $address_data = $sender_profile;
                } else {
                    // Iterate through all fields to find nested address objects
                    // This matches the logic in render_sender_profile()
                    foreach ($sender_profile as $field_key => $value) {
                        if (is_array($value) || is_object($value)) {
                            $value = (array) $value;
                            // Check if this nested object looks like an address
                            // Same check as in render_sender_profile() line 328
                            if (isset($value['street']) || isset($value['city']) || isset($value['country'])) {
                                $address_data = $value;
                                break;
                            }
                        }
                    }
                }
            }
            
            if ($address_data) {
                $address_data = (array) $address_data;
                
                // Extract address fields - use same field names as render_sender_profile() expects
                $address = array(
                    'country' => $address_data['country'] ?? '',
                    'county' => $address_data['county'] ?? '',
                    'city' => $address_data['city'] ?? '',
                    'postcode' => $address_data['postcode'] ?? '',
                    'street' => $address_data['street'] ?? '',
                    'streetNumber' => $address_data['streetNumber'] ?? '',
                    'block' => $address_data['block'] ?? '',
                    'staircase' => $address_data['staircase'] ?? '',
                    'floor' => $address_data['floor'] ?? '',
                    'apartment' => $address_data['apartment'] ?? '',
                    'localityPostcode' => $address_data['localityPostcode'] ?? $address_data['postcode'] ?? ''
                );
                
                // Ensure all required fields are present
                $required_fields = array('country', 'county', 'city', 'postcode', 'street');
                $missing_fields = array();
                foreach ($required_fields as $field) {
                    if (empty($address[$field])) {
                        $missing_fields[] = $field;
                    }
                }
                
                if (empty($missing_fields)) {
                    if (Livraria_API_Client::is_dev_mode()) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
                        error_log('Livraria: Using sender profile address from API');
                    }
                    return $address;
                } else {
                    // Log what we got for debugging (only in development mode)
                    if (Livraria_API_Client::is_dev_mode()) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
                        error_log('Livraria: Sender profile address incomplete. Missing: ' . implode(', ', $missing_fields));
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug logging only in development mode
                        error_log('Livraria: Full sender profile: ' . print_r($sender_profile, true));
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug logging only in development mode
                        error_log('Livraria: Address data found: ' . print_r($address_data, true));
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug logging only in development mode
                        error_log('Livraria: Extracted address: ' . print_r($address, true));
                    }
                }
            } else {
                if (Livraria_API_Client::is_dev_mode()) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
                    error_log('Livraria: No address data found in sender profile');
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug logging only in development mode
                    error_log('Livraria: Full sender profile structure: ' . print_r($sender_profile, true));
                }
            }
        } else {
            if (Livraria_API_Client::is_dev_mode()) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
                error_log('Livraria: No sender profile available from API');
            }
        }
        
        // Fallback to settings if API profile not available or incomplete
        // Get individual address fields from settings
        $address = array(
            'country' => get_option('courier_sender_country', ''),
            'county' => get_option('courier_sender_county', ''),
            'city' => get_option('courier_sender_city', ''),
            'postcode' => get_option('courier_sender_postcode', ''),
            'street' => get_option('courier_sender_street', ''),
            'streetNumber' => get_option('courier_sender_street_number', ''),
            'block' => get_option('courier_sender_block', ''),
            'staircase' => get_option('courier_sender_staircase', ''),
            'floor' => get_option('courier_sender_floor', ''),
            'apartment' => get_option('courier_sender_apartment', ''),
            'localityPostcode' => get_option('courier_sender_postcode', '')
        );
        
        // Ensure all required fields are present
        $required_fields = array('country', 'county', 'city', 'postcode', 'street');
        $missing_fields = array();
        foreach ($required_fields as $field) {
            if (empty($address[$field])) {
                $missing_fields[] = $field;
            }
        }
        
        if (!empty($missing_fields)) {
            $error_msg = "Sender address missing required fields: " . implode(', ', array_map('esc_html', $missing_fields)) . ". ";
            $error_msg .= "Please ensure your sender profile in the API has a complete address with fields: country, county, city, postcode, and street. ";
            $error_msg .= "Check the 'Sender Profile' section in settings to see what address data is available from the API.";
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is sanitized above
            throw new Exception($error_msg);
        }
        
        if (Livraria_API_Client::is_dev_mode()) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
            error_log('Livraria: Using sender address from plugin settings (fallback)');
        }
        return $address;
    }
    
    /**
     * Get sender info (name, email, phone) from API sender profile
     * Falls back to settings if API profile not available
     * 
     * @return array
     */
    private function get_sender_info() {
        $sender_profile = $this->get_sender_profile_from_api();
        
        // If we have a sender profile from API, use it
        if ($sender_profile) {
            $sender_profile = (array) $sender_profile;
            return array(
                'name' => $sender_profile['name'] ?? '',
                'email' => $sender_profile['email'] ?? '',
                'phone' => $sender_profile['phone'] ?? '',
                'phoneCountryCode' => $sender_profile['phoneCountryCode'] ?? null,
                'phoneNSN' => $sender_profile['phoneNSN'] ?? null,
                'company' => $sender_profile['company'] ?? '',
                'isCompany' => isset($sender_profile['isCompany']) ? (bool)$sender_profile['isCompany'] : !empty($sender_profile['company']),
                'cui' => $sender_profile['cui'] ?? ''
            );
        }
        
        // Fallback to settings (no defaults)
        return array(
            'name' => trim(get_option('courier_default_sender_name', '')),
            'email' => trim(get_option('courier_default_sender_email', '')),
            'phone' => trim(get_option('courier_default_sender_phone', '')),
            'phoneCountryCode' => null,
            'phoneNSN' => null,
            'company' => '',
            'isCompany' => false,
            'cui' => ''
        );
    }
    
    /** The order panel's minimums: a parcel the plugin works out is never below them */
    const MIN_WEIGHT_KG = 1.0;
    const MIN_DIMENSION_CM = 10.0;

    /**
     * $value in kilograms, from WooCommerce's weight unit
     * (woocommerce_weight_unit: kg, g, lbs, oz). 0 for an empty value.
     *
     * @param mixed $value
     * @param string|null $unit Defaults to the shop's setting
     * @return float
     */
    public static function to_kilograms($value, $unit = null) {
        $value = (float) $value;
        if ($value <= 0) {
            return 0.0;
        }
        $unit = strtolower(trim((string) ($unit === null ? get_option('woocommerce_weight_unit', 'kg') : $unit)));
        switch ($unit) {
            case 'g':
                return $value / 1000;
            case 'lbs':
            case 'lb':
                return $value * 0.45359237;
            case 'oz':
                return $value * 0.0283495231;
            default: // kg and anything unrecognised
                return $value;
        }
    }

    /**
     * $value in centimetres, from WooCommerce's dimension unit
     * (woocommerce_dimension_unit: m, cm, mm, in, yd). 0 for an empty value.
     *
     * @param mixed $value
     * @param string|null $unit Defaults to the shop's setting
     * @return float
     */
    public static function to_centimetres($value, $unit = null) {
        $value = (float) $value;
        if ($value <= 0) {
            return 0.0;
        }
        $unit = strtolower(trim((string) ($unit === null ? get_option('woocommerce_dimension_unit', 'cm') : $unit)));
        switch ($unit) {
            case 'm':
                return $value * 100;
            case 'mm':
                return $value / 10;
            case 'in':
                return $value * 2.54;
            case 'yd':
                return $value * 91.44;
            default: // cm and anything unrecognised
                return $value;
        }
    }

    /**
     * The parcels to quote.
     *
     * - Packages typed in the order panel are sent as typed.
     * - Otherwise ONE parcel for the whole order (couriers price per parcel):
     *   the summed weight of the lines, the largest line's length and width,
     *   the summed height; a line without a weight or a dimension counts with
     *   the default package's value. Never below MIN_WEIGHT_KG / MIN_DIMENSION_CM.
     * - The option livraria_package_mode = "per_line" keeps one parcel per
     *   order line (the 1.1.0 behaviour, now in kg and cm).
     * Weights and dimensions are converted from the shop's units.
     *
     * @param WC_Order $order
     * @param array $custom_data Custom expedition data
     * @return array
     */
    public function calculate_packages_from_order($order, $custom_data = array()) {
        if (!empty($custom_data['packages'])) {
            $packages = array();
            foreach ($custom_data['packages'] as $package) {
                $packages[] = array(
                    'weight' => $package['weight'],
                    'width' => $package['width'],
                    'height' => $package['height'],
                    'length' => $package['length'],
                    'packageType' => 'box'
                );
            }
            return $packages;
        }

        $defaults = array(
            'weight' => floatval(get_option('courier_default_package_weight', 1)) ?: 1.0,
            'width' => floatval(get_option('courier_default_package_width', 10)) ?: 10.0,
            'height' => floatval(get_option('courier_default_package_height', 10)) ?: 10.0,
            'length' => floatval(get_option('courier_default_package_length', 10)) ?: 10.0,
        );

        $lines = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            $weight = self::to_kilograms($product->get_weight());
            $length = self::to_centimetres($product->get_length());
            $width = self::to_centimetres($product->get_width());
            $height = self::to_centimetres($product->get_height());
            $lines[] = array(
                'quantity' => max(1, (int) $item->get_quantity()),
                'weight' => $weight > 0 ? $weight : $defaults['weight'],
                'length' => $length > 0 ? $length : $defaults['length'],
                'width' => $width > 0 ? $width : $defaults['width'],
                'height' => $height > 0 ? $height : $defaults['height'],
            );
        }

        if (empty($lines)) {
            return array(self::parcel($defaults['weight'], $defaults['width'], $defaults['height'], $defaults['length']));
        }

        if (get_option('livraria_package_mode', 'order') === 'per_line') {
            $packages = array();
            foreach ($lines as $line) {
                $packages[] = self::parcel($line['weight'] * $line['quantity'], $line['width'], $line['height'], $line['length']);
            }
            return $packages;
        }

        $weight = 0.0;
        $length = 0.0;
        $width = 0.0;
        $height = 0.0;
        foreach ($lines as $line) {
            $weight += $line['weight'] * $line['quantity'];
            $length = max($length, $line['length']);
            $width = max($width, $line['width']);
            $height += $line['height'] * $line['quantity'];
        }
        return array(self::parcel($weight, $width, $height, $length));
    }

    /**
     * One parcel the plugin worked out, rounded to 0.01 and never below the minimums
     */
    private static function parcel($weight, $width, $height, $length) {
        return array(
            'weight' => round(max(self::MIN_WEIGHT_KG, $weight), 2),
            'width' => round(max(self::MIN_DIMENSION_CM, $width), 2),
            'height' => round(max(self::MIN_DIMENSION_CM, $height), 2),
            'length' => round(max(self::MIN_DIMENSION_CM, $length), 2),
            'packageType' => 'package'
        );
    }

    /**
     * Get service options for the order
     * 
     * @param WC_Order $order
     * @param array $custom_data Custom expedition data
     * @return array
     */
    private function get_service_options($order, $custom_data = array()) {
        // Ensure proper data types for validation
        $saturday_delivery = isset($custom_data['saturday_delivery']) ? 
            $custom_data['saturday_delivery'] : false;
        $open_on_delivery = isset($custom_data['open_on_delivery']) ? 
            $custom_data['open_on_delivery'] : get_option('courier_default_open_on_delivery', false);
        $insurance_amount = isset($custom_data['insurance_amount']) ? 
            floatval($custom_data['insurance_amount']) : floatval(get_option('courier_default_insurance_amount', 0));
        
        // Amount to collect: what the merchant typed in the panel, else the
        // order total on a COD order, else nothing
        if (isset($custom_data['cod_amount'])) {
            $cod_amount = max(0, floatval($custom_data['cod_amount']));
        } elseif (self::is_cod_order($order)) {
            $cod_amount = max(0, floatval($order->get_total()));
        } else {
            $cod_amount = 0;
        }

        return array(
            'saturday_delivery' => (bool)$saturday_delivery,
            'open_on_delivery' => (bool)$open_on_delivery,
            'insurance_amount' => round($insurance_amount, 2),
            'cod_amount' => round($cod_amount, 2)
        );
    }
    
    /**
     * Calculate pickup date (next business day)
     * 
     * @return string ISO 8601 formatted date
     */
    private function calculate_pickup_date() {
        $pickup_days_offset = intval(get_option('courier_pickup_days_offset', 1));
        $pickup_date = new DateTime();
        $pickup_date->add(new DateInterval('P' . $pickup_days_offset . 'D'));
        
        // Skip weekends if configured
        if (get_option('courier_skip_weekends', true)) {
            while ($pickup_date->format('N') >= 6) { // 6 = Saturday, 7 = Sunday
                $pickup_date->add(new DateInterval('P1D'));
            }
        }
        
        return $pickup_date->format('Y-m-d\TH:i:s.v\Z');
    }
    
    /**
     * Generate package description from order
     * 
     * @param WC_Order $order
     * @return string
     */
    private function generate_package_description($order) {
        $items = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $items[] = $product->get_name() . ' (x' . $item->get_quantity() . ')';
            }
        }
        
        $description = 'WooCommerce Order #' . $order->get_order_number();
        if (!empty($items)) {
            $description .= ': ' . implode(', ', array_slice($items, 0, 3));
            if (count($items) > 3) {
                $description .= ' and ' . (count($items) - 3) . ' more items';
            }
        }
        
        return substr($description, 0, 250); // Limit length
    }
    
    /**
     * The quotes the order panel shows: every door-to-door quote, disabled
     * ones included. Each keeps the API's fields and gains:
     * - usable: false when the API disabled it (isDisabled), it has no price
     *   (amount <= 0) or no id; such a quote is shown greyed, without a price,
     *   and can never be selected or auto-picked;
     * - disabledReason: the API's error.message (or why it is unusable);
     * - errorCode, missingRecipientFields, needsEmail (RECIPIENT_FIELDS_REQUIRED
     *   naming "email": the panel offers "Add email").
     * Usable quotes keep the API's order; unusable ones go last. Locker
     * quotes are left out: this version ships door-to-door only.
     *
     * @param mixed $quotes courierQuotes from the API
     * @return array
     */
    public static function normalize_quotes($quotes) {
        if (!is_array($quotes)) {
            return array();
        }
        $usable = array();
        $unusable = array();
        foreach ($quotes as $quote) {
            if (!is_array($quote) || !empty($quote['isLockerQuote'])) {
                continue;
            }
            $error = (isset($quote['error']) && is_array($quote['error'])) ? $quote['error'] : array();
            $fields = array();
            if (isset($error['missingRecipientFields']) && is_array($error['missingRecipientFields'])) {
                $fields = $error['missingRecipientFields'];
            } elseif (isset($quote['missingRecipientFields']) && is_array($quote['missingRecipientFields'])) {
                $fields = $quote['missingRecipientFields'];
            }
            $id = isset($quote['id']) ? (string) $quote['id'] : '';
            $amount = (isset($quote['amount']) && is_numeric($quote['amount'])) ? (float) $quote['amount'] : 0.0;
            $disabled = !empty($quote['isDisabled']);

            $quote['isDisabled'] = $disabled;
            $quote['usable'] = !$disabled && $amount > 0 && $id !== '';
            $quote['errorCode'] = isset($error['code']) ? (string) $error['code'] : '';
            $quote['missingRecipientFields'] = array_values(array_filter($fields, 'is_string'));
            $quote['needsEmail'] = $disabled && $quote['errorCode'] === 'RECIPIENT_FIELDS_REQUIRED' && in_array('email', $quote['missingRecipientFields'], true);
            $quote['disabledReason'] = '';
            if (!$quote['usable']) {
                $message = isset($error['message']) && is_string($error['message']) ? trim($error['message']) : '';
                if ($message === '') {
                    $message = $disabled ? 'Unavailable for this shipment.' : 'No price was returned for this courier.';
                }
                $quote['disabledReason'] = $message;
            }

            if ($quote['usable']) {
                $usable[] = $quote;
            } else {
                $unusable[] = $quote;
            }
        }
        return array_merge($usable, $unusable);
    }

    /**
     * The quote an unattended run ships with: the cheapest usable one
     * (normalize_quotes()), never a disabled, unpriced or id-less quote
     *
     * @param array $quotes normalize_quotes() result
     * @return array|null
     */
    public static function select_best_quote($quotes) {
        $best = null;
        foreach ((array) $quotes as $quote) {
            if (empty($quote['usable'])) {
                continue;
            }
            if ($best === null || (float) $quote['amount'] < (float) $best['amount']) {
                $best = $quote;
            }
        }
        return $best;
    }

    /**
     * "Courier: reason · Courier: reason" for the unusable quotes, '' if none
     *
     * @param array $quotes normalize_quotes() result
     * @return string
     */
    public static function summarise_unusable_quotes($quotes) {
        $reasons = array();
        foreach ((array) $quotes as $quote) {
            if (!empty($quote['usable'])) {
                continue;
            }
            $reasons[] = (isset($quote['courierName']) && $quote['courierName'] !== '' ? $quote['courierName'] : 'A courier') . ': ' . $quote['disabledReason'];
        }
        return implode(' · ', $reasons);
    }
    
    /**
     * Prepare billing info for expedition
     * 
     * @param WC_Order $order
     * @return array
     */
    private function prepare_billing_info($order) {
        return array(
            'name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'email' => $order->get_billing_email(),
            'street' => $order->get_billing_address_1(),
            'city' => $order->get_billing_city(),
            'county' => $order->get_billing_state()
        );
    }
    
    /**
     * Save expedition data to order meta
     * 
     * @param int $order_id
     * @param array $expedition_response
     * @param array $selected_quote
     */
    public function save_expedition_data($order_id, $expedition_response, $selected_quote) {
        // Debug: Log the full response structure (only in development mode)
        if (Livraria_API_Client::is_dev_mode()) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug logging only in development mode
            error_log('Livraria Debug Save: Full expedition_response: ' . print_r($expedition_response, true));
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug logging only in development mode
            error_log('Livraria Debug Save: Full selected_quote: ' . print_r($selected_quote, true));
        }

        $values = array(
            '_courier_expedition_id' => $expedition_response['id'],
            '_courier_quote_id' => $selected_quote['id'],
        );

        // Get courier name from expedition response (multiple possible locations)
        // Priority: expedition response > selected quote (since expedition response is authoritative)
        $courier_name = '';

        // Check expedition response first (this is what the API returns after creating expedition)
        if (!empty($expedition_response['courierQuote']['courierName'])) {
            $courier_name = $expedition_response['courierQuote']['courierName'];
            if (Livraria_API_Client::is_dev_mode()) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
                error_log('Livraria Debug: Courier name from expedition_response[courierQuote][courierName]: ' . $courier_name);
            }
        } elseif (!empty($expedition_response['courier']['name'])) {
            $courier_name = $expedition_response['courier']['name'];
            if (Livraria_API_Client::is_dev_mode()) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
                error_log('Livraria Debug: Courier name from expedition_response[courier][name]: ' . $courier_name);
            }
        } elseif (!empty($expedition_response['courierName'])) {
            // Public tracking endpoint format
            $courier_name = $expedition_response['courierName'];
            if (Livraria_API_Client::is_dev_mode()) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
                error_log('Livraria Debug: Courier name from expedition_response[courierName]: ' . $courier_name);
            }
        } elseif (!empty($selected_quote['courierName'])) {
            // Fallback to selected quote (for auto-create flow)
            $courier_name = $selected_quote['courierName'];
            if (Livraria_API_Client::is_dev_mode()) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
                error_log('Livraria Debug: Courier name from selected_quote[courierName]: ' . $courier_name);
            }
        } else {
            if (Livraria_API_Client::is_dev_mode()) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
                error_log('Livraria Debug: Courier name not found in any expected location!');
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
                error_log('Livraria Debug: expedition_response keys: ' . implode(', ', array_keys($expedition_response)));
            }
        }

        if (Livraria_API_Client::is_dev_mode()) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
            error_log('Livraria Debug: Saving courier name: "' . $courier_name . '" for order ' . $order_id);
        }
        $values['_courier_name'] = $courier_name;
        $values['_courier_price'] = $selected_quote['amount'] ?? 0;

        if (isset($expedition_response['awbNumber'])) {
            $values['_courier_awb_number'] = $expedition_response['awbNumber'];
        }
        
        if (isset($expedition_response['status'])) {
            $values['_courier_status'] = $expedition_response['status']['name'] ?? 'Created';
        }
        
        // Save creation timestamp
        $values['_courier_expedition_created'] = current_time('mysql');
        self::set_order_meta($order_id, $values);
    }
    
    /**
     * Add order note about expedition creation
     * 
     * @param WC_Order $order
     * @param array $expedition_response
     */
    public function add_expedition_order_note($order, $expedition_response) {
        $note = sprintf(
            'Courier expedition created successfully. ID: %s',
            $expedition_response['id']
        );
        
        if (isset($expedition_response['awbNumber'])) {
            $note .= sprintf(', AWB: %s', $expedition_response['awbNumber']);
        }
        
        if (isset($expedition_response['status']['name'])) {
            $note .= sprintf(', Status: %s', $expedition_response['status']['name']);
        }
        
        $order->add_order_note($note);
    }
    
    /**
     * Get expedition data for order
     * 
     * @param int $order_id
     * @return array|null
     */
    public function get_order_expedition_data($order_id) {
        $expedition_id = self::get_order_meta($order_id, '_courier_expedition_id');
        
        if (!$expedition_id) {
            return null;
        }
        
        return array(
            'expedition_id' => $expedition_id,
            'awb_number' => self::get_order_meta($order_id, '_courier_awb_number'),
            'courier_name' => self::get_order_meta($order_id, '_courier_name'),
            'price' => self::get_order_meta($order_id, '_courier_price'),
            'status' => self::get_order_meta($order_id, '_courier_status'),
            'created' => self::get_order_meta($order_id, '_courier_expedition_created')
        );
    }
    
    /**
     * Update expedition status for order
     * 
     * @param int $order_id
     * @param string $awb_number
     * @return array
     */
    public function update_expedition_status($order_id, $awb_number = null) {
        if (!$awb_number) {
            $awb_number = self::get_order_meta($order_id, '_courier_awb_number');
        }
        
        if (!$awb_number) {
            return array('success' => false, 'message' => 'No AWB number found');
        }
        
        $expedition_data = $this->api_client->get_expedition_by_tracking($awb_number);
        
        if (!$expedition_data) {
            return array('success' => false, 'message' => 'Failed to get expedition status');
        }
        
        // Update stored status
        if (isset($expedition_data['status']['name'])) {
            self::set_order_meta($order_id, array('_courier_status' => $expedition_data['status']['name']));
        }
        
        return array(
            'success' => true,
            'status' => $expedition_data['status']['name'] ?? 'Unknown',
            'data' => $expedition_data
        );
    }
    
    // -------------------------------------------------------------------------
    // Order meta, HPOS-safe
    // -------------------------------------------------------------------------

    /**
     * The order behind $order_or_id (a WC_Order-like object is used as is)
     *
     * @param mixed $order_or_id
     * @return object|null
     */
    private static function order_object($order_or_id) {
        if (is_object($order_or_id)) {
            return $order_or_id;
        }
        if (!function_exists('wc_get_order')) {
            return null;
        }
        $order = wc_get_order((int) $order_or_id);
        return $order ? $order : null;
    }

    /**
     * One order meta value through the order object ($order->get_meta()), so
     * it works with High-Performance Order Storage. A value 1.1.0 or earlier
     * wrote with update_post_meta() on an HPOS store lives in wp_postmeta: when
     * the order has nothing under $key, that legacy row is read (never written).
     *
     * @param mixed $order_or_id WC_Order or order id
     * @param string $key Meta key
     * @return mixed '' when unset
     */
    public static function get_order_meta($order_or_id, $key) {
        $order = self::order_object($order_or_id);
        $value = ($order && method_exists($order, 'get_meta')) ? $order->get_meta($key, true) : '';
        if (($value === '' || $value === null || $value === false) && function_exists('get_post_meta')) {
            $id = $order ? (int) $order->get_id() : (int) $order_or_id;
            if ($id > 0) {
                $value = get_post_meta($id, $key, true);
            }
        }
        return ($value === null || $value === false) ? '' : $value;
    }

    /**
     * Write order meta through the order object and save once
     *
     * @param mixed $order_or_id WC_Order or order id
     * @param array $values key => value
     * @return bool False when the order cannot be loaded
     */
    public static function set_order_meta($order_or_id, array $values) {
        $order = self::order_object($order_or_id);
        if (!$order) {
            return false;
        }
        foreach ($values as $key => $value) {
            $order->update_meta_data($key, $value);
        }
        $order->save();
        return true;
    }

    /**
     * Delete order meta through the order object and save once; the legacy
     * wp_postmeta copy (see get_order_meta()) goes too
     *
     * @param mixed $order_or_id WC_Order or order id
     * @param string[] $keys
     */
    public static function delete_order_meta($order_or_id, array $keys) {
        $order = self::order_object($order_or_id);
        $id = $order ? (int) $order->get_id() : (int) $order_or_id;
        if ($order) {
            foreach ($keys as $key) {
                $order->delete_meta_data($key);
            }
            $order->save();
        }
        if ($id > 0 && function_exists('delete_post_meta')) {
            foreach ($keys as $key) {
                delete_post_meta($id, $key);
            }
        }
    }

    /**
     * Get shipping address field, fallback to billing if shipping is empty
     * 
     * @param WC_Order $order
     * @param string $field Field name (without shipping_/billing_ prefix)
     * @return string
     */
    private function get_shipping_or_billing($order, $field) {
        $shipping_method = 'get_shipping_' . $field;
        $billing_method = 'get_billing_' . $field;
        
        $shipping_value = method_exists($order, $shipping_method) ? $order->$shipping_method() : '';
        
        // If shipping is empty, fallback to billing
        if (empty($shipping_value) && method_exists($order, $billing_method)) {
            return $order->$billing_method();
        }
        
        return $shipping_value;
    }
    
    /**
     * Get available courier IDs with caching
     * 
     * @return array Array of courier IDs
     */
    private function get_available_courier_ids() {
        // Check if we have cached courier IDs (cache for 1 hour)
        $cached_courier_ids = get_transient('livraria_courier_ids');
        if ($cached_courier_ids !== false) {
            return $cached_courier_ids;
        }
        
        // Fetch available couriers from API
        $available_couriers = $this->api_client->get_available_couriers();
        if (!$available_couriers || empty($available_couriers)) {
            if (Livraria_API_Client::is_dev_mode()) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
                error_log('Livraria Debug: No couriers available from API');
            }
            return array();
        }
        
        // Extract courier IDs from the response
        $courier_ids = array();
        foreach ($available_couriers as $courier) {
            if (isset($courier['_id'])) {
                $courier_ids[] = $courier['_id'];
            }
        }
        
        if (Livraria_API_Client::is_dev_mode()) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug logging only in development mode
            error_log('Livraria Debug: Found courier IDs: ' . print_r($courier_ids, true));
        }
        
        // Cache the courier IDs for 1 hour
        if (!empty($courier_ids)) {
            set_transient('livraria_courier_ids', $courier_ids, HOUR_IN_SECONDS);
        }
        
        return $courier_ids;
    }
}