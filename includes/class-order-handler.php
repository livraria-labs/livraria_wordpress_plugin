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
     * Prepare quote request data from WooCommerce order
     * 
     * @param WC_Order $order
     * @param array $custom_data Custom expedition data
     * @param array $courier_ids Available courier IDs
     * @return array
     */
    private function prepare_quote_request_data($order, $custom_data = array(), $courier_ids = array()) {
        $sender_address = $this->get_sender_address();
        $packages = $this->calculate_packages_from_order($order, $custom_data);
        $service_options = $this->get_service_options($order, $custom_data);
        
        // Convert date to proper format for the API
        $pickup_date = new DateTime($this->calculate_pickup_date());
        
        return array(
            'createQuoteRequestDto' => array(
                'sender' => array(
                    'name' => trim(get_option('courier_default_sender_name', get_bloginfo('name'))) ?: 'Default Sender',
                    'email' => trim(get_option('courier_default_sender_email', get_option('admin_email'))) ?: 'admin@example.com',
                    'phone' => trim(get_option('courier_default_sender_phone', '')) ?: '0000000000',
                    'isCompany' => true,
                    'address' => array(
                        'country' => $sender_address['country'] ?: 'Romania',
                        'county' => $sender_address['county'] ?: 'Bucuresti',
                        'city' => $sender_address['city'] ?: 'Sector 1',
                        'postcode' => $sender_address['postcode'] ?: '000000',
                        'street' => $sender_address['street'] ?: 'Default Street',
                        'streetNumber' => $sender_address['streetNumber'] ?: '1'
                    )
                ),
                'recipient' => array(
                    'name' => trim($this->get_shipping_or_billing($order, 'first_name') . ' ' . $this->get_shipping_or_billing($order, 'last_name')) ?: 'Customer',
                    'email' => trim($order->get_billing_email()) ?: 'customer@example.com',
                    'phone' => trim($order->get_billing_phone()) ?: '0000000000',
                    'isCompany' => false,
                    'address' => array(
                        'country' => $this->get_shipping_or_billing($order, 'country') ?: 'Romania',
                        'county' => $this->get_shipping_or_billing($order, 'state') ?: 'Bucuresti',
                        'city' => $this->get_shipping_or_billing($order, 'city') ?: 'Sector 1',
                        'postcode' => $this->get_shipping_or_billing($order, 'postcode') ?: '000000',
                        'street' => $this->get_shipping_or_billing($order, 'address_1') ?: 'Default Street',
                        'streetNumber' => '1'
                    )
                ),
                'content' => array(
                    'packages' => $packages
                ),
                'packageDescription' => !empty($custom_data['content_description']) ? 
                    $custom_data['content_description'] : 
                    $this->generate_package_description($order),
                'shipmentNote' => 'Order from ' . get_bloginfo('name') . ' - Order #' . $order->get_order_number(),
                'service' => array(
                    'pickupDate' => $pickup_date->format('c'), // ISO 8601 format
                    'earliestPickupTime' => '09:00',
                    'latestPickupTime' => '18:00',
                    'saturdayDelivery' => $service_options['saturday_delivery'],
                    'openOnDelivery' => $service_options['open_on_delivery'],
                    'cod' => array(
                        'amount' => $service_options['cod_amount'],
                        'collector' => 'CLIENT'
                    ),
                    'insurance' => array(
                        'amount' => $service_options['insurance_amount']
                    )
                ),
                'paymentInfo' => array(
                    'courierServicePayer' => 'THIRD_PARTY'
                )
            ),
            'courierNames' => !empty($courier_ids) ? $courier_ids : array()
        );
    }
    
    /**
     * Get sender address from plugin settings
     * 
     * @return array
     */
    private function get_sender_address() {
        // Get individual address fields
        $address = array(
            'country' => get_option('courier_sender_country', 'Romania'),
            'county' => get_option('courier_sender_county', 'Bucharest'),
            'city' => get_option('courier_sender_city', 'Bucharest'),
            'postcode' => get_option('courier_sender_postcode', '010101'),
            'street' => get_option('courier_sender_street', 'Default Street'),
            'streetNumber' => get_option('courier_sender_street_number', '1'),
            'block' => get_option('courier_sender_block', ''),
            'staircase' => get_option('courier_sender_staircase', ''),
            'floor' => get_option('courier_sender_floor', ''),
            'apartment' => get_option('courier_sender_apartment', ''),
            'localityPostcode' => get_option('courier_sender_postcode', '010101')
        );
        
        // Ensure all required fields are present
        $required_fields = array('country', 'county', 'city', 'postcode', 'street');
        foreach ($required_fields as $field) {
            if (empty($address[$field])) {
                throw new Exception("Sender address missing required field: $field");
            }
        }
        
        return $address;
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
        $default_weight = floatval(get_option('courier_default_package_weight', 0.5));
        $default_dimensions = array(
            'width' => floatval(get_option('courier_default_package_width', 20)),
            'height' => floatval(get_option('courier_default_package_height', 10)),
            'length' => floatval(get_option('courier_default_package_length', 30))
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
        $cod_amount = isset($custom_data['cod_amount']) ? 
            floatval($custom_data['cod_amount']) : floatval($order->get_total());

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
    private function save_expedition_data($order_id, $expedition_response, $selected_quote) {
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
    private function add_expedition_order_note($order, $expedition_response) {
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