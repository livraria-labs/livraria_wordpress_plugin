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
    
    /**
     * Get quotes for WooCommerce order (creates quote request)
     * 
     * @param int $order_id WooCommerce order ID
     * @param array $custom_data Custom expedition data from admin interface
     * @return array Result array with success status, quotes, and quoteRequestId
     */
    public function get_quotes_for_order($order_id, $custom_data = array()) {
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
        
        try {
            // Get available couriers first (with caching)
            $courier_ids = $this->get_available_courier_ids();
            if (empty($courier_ids)) {
                return array('success' => false, 'message' => 'No couriers available');
            }
            
            // Create quote request and get courier quotes
            $quote_request_data = $this->prepare_quote_request_data($order, $custom_data, $courier_ids);
            
            // Debug: Log the data being sent
            error_log('Livraria Debug: Quote request data structure: ' . print_r($quote_request_data, true));
            error_log('Livraria Debug: Has sender: ' . (isset($quote_request_data['createQuoteRequestDto']['sender']) ? 'yes' : 'no'));
            error_log('Livraria Debug: Has recipient: ' . (isset($quote_request_data['createQuoteRequestDto']['recipient']) ? 'yes' : 'no'));
            
            $quotes_response = $this->api_client->create_quote_request($quote_request_data);
            
            if (!$quotes_response) {
                return array('success' => false, 'message' => 'Failed to create quote request');
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
            
            // Filter out locker quotes
            $filtered_quotes = array_filter($courier_quotes, function($quote) {
                return !isset($quote['isLockerQuote']) || !$quote['isLockerQuote'];
            });
            
            if (empty($filtered_quotes)) {
                return array('success' => false, 'message' => 'No courier quotes available');
            }
            
            if (!$quote_request_id) {
                return array('success' => false, 'message' => 'Quote request ID not found in response');
            }
            
            // Store quote request ID in order meta temporarily
            update_post_meta($order_id, '_courier_quote_request_id', $quote_request_id);
            
            return array(
                'success' => true,
                'quotes' => array_values($filtered_quotes), // Re-index array
                'quoteRequestId' => $quote_request_id,
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
        if (get_post_meta($order_id, '_courier_expedition_id', true)) {
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
            
            // Debug: Log the data being sent
            error_log('Livraria Debug: Quote request data: ' . print_r($quote_request_data, true));
            error_log('Livraria Debug: Available courier IDs: ' . print_r($courier_ids, true));
            
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
            $selected_quote = $this->select_best_quote($courier_quotes);
            
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
            'billing_email' => 'Email Address',
            'billing_phone' => 'Phone Number'
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
     * Parse phone number into country code and NSN
     * 
     * @param string $phone Phone number (e.g., +40123456789)
     * @return array Array with phoneCountryCode and phoneNSN
     */
    private function parse_phone_number($phone) {
        $phone = trim($phone);
        $phone = preg_replace('/\s+/', '', $phone); // Remove spaces
        
        if (empty($phone)) {
            return array('phoneCountryCode' => '', 'phoneNSN' => '');
        }
        
        if (preg_match('/^(\+\d{1,3})(.+)$/', $phone, $matches)) {
            return array(
                'phoneCountryCode' => $matches[1],
                'phoneNSN' => $matches[2]
            );
        }
        
        // If no country code, return as-is (no fallback)
        return array(
            'phoneCountryCode' => '',
            'phoneNSN' => $phone
        );
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
     * @return array
     */
    private function prepare_quote_request_data($order, $custom_data = array(), $courier_ids = array()) {
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
            $sender_phone_parsed = $this->parse_phone_number($sender_phone);
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
        
        // Parse recipient phone number
        $recipient_phone = trim($order->get_billing_phone());
        $recipient_phone_parsed = $this->parse_phone_number($recipient_phone);
        
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
        
        // Build recipient object
        $recipient_name = trim($this->get_shipping_or_billing($order, 'first_name') . ' ' . $this->get_shipping_or_billing($order, 'last_name'));
        
        // Get state and convert code to full name if needed
        $recipient_state = trim($this->get_shipping_or_billing($order, 'state'));
        $recipient_county = $recipient_state;
        
        // Try to convert state code to full name using WooCommerce state mapping
        if (!empty($recipient_state) && function_exists('WC') && WC()->countries) {
            $romanian_states = WC()->countries->get_states('RO');
            if ($romanian_states && isset($romanian_states[$recipient_state])) {
                $recipient_county = $romanian_states[$recipient_state];
            }
        }
        
        // Remove diacritics from county and uppercase
        $recipient_county = strtoupper($this->remove_romanian_diacritics($recipient_county));
        
        // Uppercase and remove diacritics from recipient city
        $recipient_city = $this->get_shipping_or_billing($order, 'city');
        $recipient_city = !empty($recipient_city) 
            ? strtoupper($this->remove_romanian_diacritics($recipient_city)) 
            : '';
        
        // Default phoneCountryCode to +40 if empty
        $recipient_phone_country_code = !empty($recipient_phone_parsed['phoneCountryCode']) 
            ? $recipient_phone_parsed['phoneCountryCode'] 
            : '+40';
        
        $recipient_obj = array(
            'name' => $recipient_name,
            'phoneCountryCode' => $recipient_phone_country_code,
            'phoneNSN' => $recipient_phone_parsed['phoneNSN'],
            'email' => trim($order->get_billing_email()),
            'isCompany' => false,
            'address' => array(
                'country' => 'Romania',
                'county' => $recipient_county,
                'city' => $recipient_city,
                'postcode' => $this->get_shipping_or_billing($order, 'postcode'),
                'street' => $this->get_shipping_or_billing($order, 'address_1'),
                'streetNumber' => $this->get_shipping_or_billing($order, 'address_2'),
                'block' => '',
                'staircase' => '',
                'floor' => '',
                'apartment' => '',
            )
        );
        
        // Debug: Log sender and recipient data
        error_log('Livraria Debug: Sender object: ' . PHP_EOL . json_encode($sender_obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        error_log('Livraria Debug: Recipient object: ' . PHP_EOL . json_encode($recipient_obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        
        return array(
            'createQuoteRequestDto' => array(
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
                'service' => array(
                    'saturdayDelivery' => $service_options['saturday_delivery'],
                    'openOnDelivery' => $service_options['open_on_delivery'],
                    'cod' => $this->build_cod_object($service_options['cod_amount'], $sender_profile),
                    'insurance' => array(
                        'amount' => $service_options['insurance_amount']
                    )
                ),
                'paymentInfo' => array(
                    'courierServicePayer' => 'THIRD_PARTY'
                )
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
                    error_log('Livraria: Using sender profile address from API');
                    return $address;
                } else {
                    // Log what we got for debugging
                    error_log('Livraria: Sender profile address incomplete. Missing: ' . implode(', ', $missing_fields));
                    error_log('Livraria: Full sender profile: ' . print_r($sender_profile, true));
                    error_log('Livraria: Address data found: ' . print_r($address_data, true));
                    error_log('Livraria: Extracted address: ' . print_r($address, true));
                }
            } else {
                error_log('Livraria: No address data found in sender profile');
                error_log('Livraria: Full sender profile structure: ' . print_r($sender_profile, true));
            }
        } else {
            error_log('Livraria: No sender profile available from API');
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
            $error_msg = "Sender address missing required fields: " . implode(', ', $missing_fields) . ". ";
            $error_msg .= "Please ensure your sender profile in the API has a complete address with fields: country, county, city, postcode, and street. ";
            $error_msg .= "Check the 'Sender Profile' section in settings to see what address data is available from the API.";
            throw new Exception($error_msg);
        }
        
        error_log('Livraria: Using sender address from plugin settings (fallback)');
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
    
    /**
     * Calculate packages from order items
     * 
     * @param WC_Order $order
     * @param array $custom_data Custom expedition data
     * @return array
     */
    private function calculate_packages_from_order($order, $custom_data = array()) {
        // If custom packages are provided, use them
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
        
        // Otherwise, calculate from order items
        $total_weight = 0;
        $packages = array();
        $default_weight = floatval(get_option('courier_default_package_weight', 1));
        $default_dimensions = array(
            'width' => floatval(get_option('courier_default_package_width', 10)),
            'height' => floatval(get_option('courier_default_package_height', 10)),
            'length' => floatval(get_option('courier_default_package_length', 10))
        );
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            
            $weight = $product->get_weight() ? floatval($product->get_weight()) : $default_weight;
            $quantity = $item->get_quantity();
            
            // Get product dimensions or use defaults
            $width = $product->get_width() ? floatval($product->get_width()) : $default_dimensions['width'];
            $height = $product->get_height() ? floatval($product->get_height()) : $default_dimensions['height'];
            $length = $product->get_length() ? floatval($product->get_length()) : $default_dimensions['length'];
            
            $total_weight += $weight * $quantity;
            
            // For now, create one package per product type
            $packages[] = array(
                'weight' => round(floatval($weight * $quantity), 2),
                'width' => round(floatval($width), 2),
                'height' => round(floatval($height), 2),
                'length' => round(floatval($length), 2),
                'packageType' => 'package'
            );
        }
        
        // If no packages calculated, create a default one
        if (empty($packages)) {
            $packages[] = array(
                'weight' => round(max(floatval($default_weight), 0.1), 2),
                'width' => round(floatval($default_dimensions['width']), 2),
                'height' => round(floatval($default_dimensions['height']), 2),
                'length' => round(floatval($default_dimensions['length']), 2),
                'packageType' => 'package'
            );
        }
        
        return $packages;
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
        
        // Check if payment method is Cash on Delivery
        $payment_method = $order->get_payment_method();
        $payment_method_title = $order->get_payment_method_title();
        $is_cod = false;
        
        // Primary check: WooCommerce COD payment gateway ID is 'cod'
        if ($payment_method === 'cod') {
            $is_cod = true;
        } else {
            // Fallback: check payment method title for COD indicators (case-insensitive)
            $payment_method_title_lower = strtolower($payment_method_title);
            $cod_indicators = array('cash on delivery', 'payment on delivery', 'pay on delivery', 'cod');
            
            foreach ($cod_indicators as $indicator) {
                if (strpos($payment_method_title_lower, strtolower($indicator)) !== false) {
                    $is_cod = true;
                    break;
                }
            }
        }
        
        // Set COD amount based on payment method or custom data
        $cod_amount = 0;
        if ($is_cod) {
            // If COD payment method, use order total (or custom amount if provided)
            $cod_amount = isset($custom_data['cod_amount']) ? 
                floatval($custom_data['cod_amount']) : floatval($order->get_total());
        } else if (isset($custom_data['cod_amount'])) {
            // If custom data has COD amount but payment method is not COD, use custom amount
            $cod_amount = floatval($custom_data['cod_amount']);
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
     * Select the best quote from available quotes
     * 
     * @param array $quotes
     * @return array
     */
    private function select_best_quote($quotes) {
        $selection_method = get_option('courier_quote_selection', 'first');
        
        switch ($selection_method) {
            case 'cheapest':
                return array_reduce($quotes, function($carry, $quote) {
                    return (!$carry || $quote['amount'] < $carry['amount']) ? $quote : $carry;
                });
                
            case 'fastest':
                // Assuming quotes have a 'deliveryDays' field
                return array_reduce($quotes, function($carry, $quote) {
                    $carry_days = isset($carry['deliveryDays']) ? $carry['deliveryDays'] : 999;
                    $quote_days = isset($quote['deliveryDays']) ? $quote['deliveryDays'] : 999;
                    return (!$carry || $quote_days < $carry_days) ? $quote : $carry;
                });
                
            default: // 'first'
                return $quotes[0];
        }
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
        update_post_meta($order_id, '_courier_expedition_id', $expedition_response['id']);
        update_post_meta($order_id, '_courier_quote_id', $selected_quote['id']);
        update_post_meta($order_id, '_courier_name', $selected_quote['courierName'] ?? '');
        update_post_meta($order_id, '_courier_price', $selected_quote['amount'] ?? 0);
        
        if (isset($expedition_response['awbNumber'])) {
            update_post_meta($order_id, '_courier_awb_number', $expedition_response['awbNumber']);
        }
        
        if (isset($expedition_response['status'])) {
            update_post_meta($order_id, '_courier_status', $expedition_response['status']['name'] ?? 'Created');
        }
        
        // Save creation timestamp
        update_post_meta($order_id, '_courier_expedition_created', current_time('mysql'));
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
        $expedition_id = get_post_meta($order_id, '_courier_expedition_id', true);
        
        if (!$expedition_id) {
            return null;
        }
        
        return array(
            'expedition_id' => $expedition_id,
            'awb_number' => get_post_meta($order_id, '_courier_awb_number', true),
            'courier_name' => get_post_meta($order_id, '_courier_name', true),
            'price' => get_post_meta($order_id, '_courier_price', true),
            'status' => get_post_meta($order_id, '_courier_status', true),
            'created' => get_post_meta($order_id, '_courier_expedition_created', true)
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
            $awb_number = get_post_meta($order_id, '_courier_awb_number', true);
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
            update_post_meta($order_id, '_courier_status', $expedition_data['status']['name']);
        }
        
        return array(
            'success' => true,
            'status' => $expedition_data['status']['name'] ?? 'Unknown',
            'data' => $expedition_data
        );
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
            error_log('Livraria Debug: No couriers available from API');
            return array();
        }
        
        // Extract courier IDs from the response
        $courier_ids = array();
        foreach ($available_couriers as $courier) {
            if (isset($courier['_id'])) {
                $courier_ids[] = $courier['_id'];
            }
        }
        
        error_log('Livraria Debug: Found courier IDs: ' . print_r($courier_ids, true));
        
        // Cache the courier IDs for 1 hour
        if (!empty($courier_ids)) {
            set_transient('livraria_courier_ids', $courier_ids, HOUR_IN_SECONDS);
        }
        
        return $courier_ids;
    }
}