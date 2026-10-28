<?php
/**
 * API Client for Livraria Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Livraria_API_Client {

    /**
     * Default API base URL for a fresh install (the production API).
     */
    const PRODUCTION_API_URL = 'https://www.api.livraria.ro/';

    /**
     * The Livraria API hosts (production and uat). Outside development mode
     * the plugin talks to an https:// URL on one of these hosts and nothing
     * else. The apex hosts answer 301 to their www form.
     */
    const ALLOWED_API_HOSTS = array('www.api.livraria.ro', 'api.livraria.ro', 'www.api-uat.livraria.ro', 'api-uat.livraria.ro');

    private $api_base_url;
    private $api_token;
    private $token_expires_at;
    
    public function __construct($api_base_url = null) {
        $this->api_base_url = self::resolve_base_url($api_base_url);
        $this->api_token = get_option('livraria_api_token', '');
        $this->token_expires_at = get_option('livraria_token_expires_at', 0);
    }

    /**
     * Development mode: wp-config.php enables WP_DEBUG and also defines
     * LIVRARIA_DEV_MODE as boolean true. Only then may the API base URL be
     * changed and debug lines be logged. Nothing a shop can set from wp-admin
     * turns it on.
     *
     * @return bool
     */
    public static function is_dev_mode() {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return false;
        }
        return defined('LIVRARIA_DEV_MODE') && LIVRARIA_DEV_MODE === true;
    }

    /**
     * The API base URL to use: $candidate, else the stored
     * courier_api_base_url, else PRODUCTION_API_URL. It is not checked here;
     * every request checks is_allowed_api_url() first.
     *
     * @param string|null $candidate URL asked for by the caller
     * @return string
     */
    public static function resolve_base_url($candidate = null) {
        $candidate = (string) $candidate;
        if ($candidate === '') {
            $candidate = (string) get_option('courier_api_base_url', '');
        }
        return $candidate !== '' ? $candidate : self::PRODUCTION_API_URL;
    }

    /**
     * Whether the plugin may talk to $url. In development mode any http(s)
     * URL; otherwise https:// on an ALLOWED_API_HOSTS host, default port, no
     * credentials in the URL.
     *
     * @param string $url
     * @return bool
     */
    public static function is_allowed_api_url($url) {
        $parts = wp_parse_url((string) $url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if (self::is_dev_mode()) {
            return $scheme === 'https' || $scheme === 'http';
        }
        if ($scheme !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }
        return in_array(strtolower($parts['host']), self::ALLOWED_API_HOSTS, true);
    }

    /**
     * Host of $url for messages and logs, reduced to host characters
     *
     * @param string $url
     * @return string
     */
    public static function url_host($url) {
        $host = wp_parse_url((string) $url, PHP_URL_HOST);
        return is_string($host) ? preg_replace('/[^A-Za-z0-9.:\-]/', '', $host) : '';
    }

    /**
     * The stored courier_api_base_url when it is not a Livraria API address
     * (outside development mode); '' when it is fine.
     *
     * @return string
     */
    public static function blocked_api_url() {
        if (self::is_dev_mode()) {
            return '';
        }
        $stored = (string) get_option('courier_api_base_url', '');
        return ($stored !== '' && !self::is_allowed_api_url($stored)) ? $stored : '';
    }

    /**
     * Check the stored API base URL on load. A stored URL is never changed:
     * - none stored (fresh install): store PRODUCTION_API_URL;
     * - on an allowed host: nothing to do;
     * - anything else (outside development mode): the plugin stops. Every
     *   request refuses to run, the API session is dropped, wp-admin shows a
     *   notice, and one line is logged per offending URL. A successful login
     *   from the settings page clears it.
     */
    public static function check_stored_api_url() {
        $stored = (string) get_option('courier_api_base_url', '');
        if ($stored === '') {
            update_option('courier_api_base_url', self::PRODUCTION_API_URL);
            return;
        }

        $blocked = self::blocked_api_url();
        if ($blocked === '') {
            if ((string) get_option('livraria_api_url_blocked', '') !== '') {
                delete_option('livraria_api_url_blocked');
            }
            return;
        }
        if ((string) get_option('livraria_api_url_blocked', '') === $blocked) {
            return; // already handled and logged
        }

        update_option('livraria_api_url_blocked', $blocked);
        delete_option('livraria_api_token');
        delete_option('livraria_token_expires_at');

        $host = self::url_host($blocked);
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- One security line: the stored API URL is not a Livraria host
        error_log('Livraria: the stored API base URL points at "' . ($host !== '' ? $host : 'an unparseable URL') . '", which is not a Livraria API host; API calls are stopped and the shop is logged out of the API until an administrator logs in again from Settings > Livraria. Check who changed the plugin settings.');
    }

    /**
     * Guard at the start of every request
     *
     * @return bool True when this client's base URL may be used
     */
    private function base_url_allowed() {
        if (empty($this->api_base_url)) {
            $this->log_error('API base URL not configured');
            return false;
        }
        if (!self::is_allowed_api_url($this->api_base_url)) {
            $this->log_error('API base URL is not a Livraria API host (' . self::url_host($this->api_base_url) . '); request refused');
            return false;
        }
        return true;
    }
    
    /**
     * Login to get JWT token
     * 
     * @param string $username Username/email
     * @param string $password Password
     * @return array Result with success status and message
     */
    public function login($username, $password) {
        // Debug: Log the attempt (never the username, password or response)
        $this->log_debug('Login attempt to URL: ' . $this->api_base_url);
        
        $response = $this->raw_api_request('POST', '/auth/login', array(
            'email' => $username,
            'password' => $password
        ));
        
        // Handle your backend's response format
        $token = null;
        $expires_in = 3600; // default
        
        if ($response && isset($response['success']) && $response['success'] && isset($response['data'])) {
            // Your backend format: {"success": true, "data": {"accessToken": "...", "expiresIn": 3600}}
            $data = $response['data'];
            if (isset($data['accessToken'])) {
                $token = $data['accessToken'];
                $expires_in = isset($data['expiresIn']) ? $data['expiresIn'] : 3600;
            }
        } else if ($response) {
            // Fallback: check for other possible formats
            if (isset($response['access_token'])) {
                $token = $response['access_token'];
                $expires_in = isset($response['expires_in']) ? $response['expires_in'] : 3600;
            } else if (isset($response['accessToken'])) {
                $token = $response['accessToken'];
                $expires_in = isset($response['expiresIn']) ? $response['expiresIn'] : 3600;
            } else if (isset($response['token'])) {
                $token = $response['token'];
            }
        }
        
        if ($response && $token) {
            $this->api_token = $token;
            $this->token_expires_at = time() + $expires_in;
            
            // Store token and expiration in WordPress options
            update_option('livraria_api_token', $this->api_token);
            update_option('livraria_token_expires_at', $this->token_expires_at);
            
            return array(
                'success' => true,
                'message' => 'Login successful',
                'token' => $this->api_token,
                'expires_at' => $this->token_expires_at
            );
        } else {
            // Clear existing tokens on failed login to prevent confusion
            // This ensures that if a new login fails, the user isn't still authenticated with old credentials
            $this->api_token = '';
            $this->token_expires_at = 0;
            delete_option('livraria_api_token');
            delete_option('livraria_token_expires_at');
            
            // Enhanced error message with debug info
            $error_details = array(
                'response_received' => $response !== false,
                'response_data' => $response,
                'api_url' => $this->api_base_url
            );
            
            $this->log_debug('Login failed', array(
                'response_received' => $error_details['response_received'],
                'api_url' => $error_details['api_url']
            ));
            
            $error_msg = 'Login failed: ';
            if ($response === false) {
                $error_msg .= 'No response from server';
            } else if (isset($response['message'])) {
                $error_msg .= $response['message'];
            } else {
                $error_msg .= 'Invalid credentials or server error';
            }
            
            return array(
                'success' => false,
                'message' => $error_msg,
                'debug' => $error_details
            );
        }
    }
    
    /**
     * Check if current token is valid and refresh if needed
     * 
     * @return bool True if token is valid, false if login failed
     */
    private function ensure_valid_token() {
        // Check if token exists and is not expired (with 5 minute buffer)
        if ($this->api_token && $this->token_expires_at > (time() + 300)) {
            return true;
        }
        
        // Try to refresh token using stored credentials (only if "Remember me" is enabled)
        if (!get_option('livraria_remember_credentials', false)) {
            $this->log_error('Credentials not stored (Remember me not enabled)');
            return false;
        }
        
        $username = $this->get_encrypted_option('courier_api_username', '');
        $password = $this->get_encrypted_option('courier_api_password', '');
        
        if (empty($username) || empty($password)) {
            $this->log_error('No credentials stored for token refresh');
            return false;
        }
        
        $result = $this->login($username, $password);
        return $result['success'];
    }
    
    /**
     * Test login with given credentials and verify API access
     * 
     * @param string $username Username/email  
     * @param string $password Password
     * @return array Result with success status and message
     */
    public function test_login($username, $password) {
        if (empty($username) || empty($password)) {
            return array(
                'success' => false,
                'message' => 'Username and password are required'
            );
        }
        
        // Step 1: Try to login and get token
        $login_result = $this->login($username, $password);
        
        if (!$login_result['success']) {
            // Include debug info in the response for troubleshooting
            $error_msg = $login_result['message'];
            if (isset($login_result['debug'])) {
                $debug = $login_result['debug'];
                if (!$debug['response_received']) {
                    $error_msg .= ' (No response from server - check URL and network)';
                } else if ($debug['response_data'] === null) {
                    $error_msg .= ' (Empty response from server)';
                }
            }
            
            return array(
                'success' => false,
                'message' => $error_msg
            );
        }
        
        // Step 2: Test the token by making an authenticated API call
        try {
            $test_response = $this->api_request('GET', '/expedition');
            
            if ($test_response !== false) {
                return array(
                    'success' => true,
                    'message' => 'Authentication successful! Login works and API access confirmed.',
                    'token_expires_at' => gmdate('Y-m-d H:i:s', $this->token_expires_at)
                );
            } else {
                return array(
                    'success' => false,
                    'message' => 'Login successful but API access failed. Check API permissions.'
                );
            }
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Login successful but API test failed: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Create a quote request
     * 
     * @param array $quote_data Quote request data
     * @return array|false API response or false on failure
     */
    public function create_quote_request($quote_data) {
        return $this->api_request('POST', '/public/awb/quotes', $quote_data);
    }
    
    /**
     * Get courier quotes for a quote request
     * NOTE: This method is deprecated - quotes are now returned directly from create_quote_request()
     * 
     * @param string $quote_request_id Quote request ID
     * @return array|false API response or false on failure
     */
    public function get_courier_quotes($quote_request_id) {
        // This method is no longer used - quotes come back from /public/awb/quotes directly
        return false;
    }
    
    /**
     * Select a courier quote for a quote request
     * 
     * @param string $quote_request_id Quote request ID
     * @param string $courier_quote_id Courier quote ID to select
     * @return array|false API response or false on failure
     */
    public function select_courier_quote($quote_request_id, $courier_quote_id) {
        return $this->api_request('PUT', '/quote-request/' . $quote_request_id . '/selected-courier-quote', array(
            'courierQuoteId' => $courier_quote_id
        ));
    }
    
    /**
     * Attach billing info from sender profile to a quote request
     * 
     * @param string $quote_request_id Quote request ID
     * @param string $sender_profile_id Sender profile ID
     * @return array|false API response or false on failure
     */
    public function attach_billing_info_from_sender_profile($quote_request_id, $sender_profile_id) {
        return $this->api_request('POST', '/quote-request/' . $quote_request_id . '/attach-billing-info-from-sender-profile', array(
            'senderProfileId' => $sender_profile_id
        ));
    }
    
    /**
     * Create an expedition
     * 
     * @param array $expedition_data Expedition data
     * @return array|false API response or false on failure
     */
    public function create_expedition($expedition_data) {
        return $this->api_request('POST', '/expedition', $expedition_data);
    }
    
    /**
     * Get expedition by ID
     * 
     * @param string $expedition_id Expedition ID
     * @return array|false API response or false on failure
     */
    public function get_expedition($expedition_id) {
        return $this->api_request('GET', '/expedition/' . $expedition_id);
    }
    
    /**
     * Get expedition by tracking number
     * 
     * @param string $tracking_number AWB/tracking number
     * @return array|false API response or false on failure
     */
    public function get_expedition_by_tracking($tracking_number) {
        return $this->api_request('GET', '/expedition/tracking?trackingNumber=' . $tracking_number);
    }
    
    /**
     * Get user expeditions
     * 
     * @return array|false API response or false on failure
     */
    public function get_user_expeditions() {
        return $this->api_request('GET', '/expedition');
    }
    
    /**
     * Get current user information
     * 
     * @return array|false API response or false on failure
     */
    public function get_user_info() {
        return $this->api_request('GET', '/users/me');
    }
    
    /**
     * Get sender profile information
     *
     * @return array|false API response or false on failure
     */
    public function get_sender_profile() {
        return $this->api_request('GET', '/users/me/sender-profile');
    }

    /**
     * Download AWB PDF
     *
     * @param string $awb_number AWB number
     * @param array $options Download options (format, paperSize, language)
     * @return array|false Binary PDF data with headers or false on failure
     */
    public function download_awb_pdf($awb_number, $options = array()) {
        // Default options
        $defaults = array(
            'format' => 'pdf',
            'paperSize' => 'A4',
            'language' => 'EN'
        );
        $options = array_merge($defaults, $options);

        // Build query string
        $query_params = http_build_query(array(
            'format' => $options['format'],
            'paperSize' => $options['paperSize'],
            'language' => $options['language']
        ));

        $endpoint = '/public/awb/print/' . urlencode($awb_number) . '?' . $query_params;

        $this->log_debug('Downloading AWB PDF: ' . $awb_number . ' with options: ' . json_encode($options));

        // Use special method for binary download
        return $this->api_request_binary('GET', $endpoint);
    }

    /**
     * Make API request for binary data (PDFs, images, etc.)
     * Similar to api_request() but handles binary responses
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @return array|false Array with 'body', 'headers', 'content_type', 'content_length' or false on failure
     */
    private function api_request_binary($method, $endpoint) {
        if (!$this->base_url_allowed()) {
            return false;
        }

        // Ensure valid token
        if (!$this->ensure_valid_token()) {
            $this->log_error('Unable to obtain valid API token for binary request');
            return false;
        }

        $url = rtrim($this->api_base_url, '/') . $endpoint;
        $url = $this->convert_localhost_for_docker($url);

        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Accept' => 'application/pdf',
                'X-Livraria-Client' => $this->client_header()
            ),
            'timeout' => 60, // Longer timeout for PDF generation
            'user-agent' => 'WordPress-Livraria-Plugin/1.0',
            'sslverify' => self::ssl_verify()
        );

        $this->log_debug('Binary API Request: ' . $method . ' ' . $url);

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->log_error('Binary API Request Failed: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);

        if ($response_code >= 200 && $response_code < 300) {
            $body = wp_remote_retrieve_body($response);
            $headers = wp_remote_retrieve_headers($response);

            $this->log_debug('Binary API Request Successful: ' . strlen($body) . ' bytes received');

            return array(
                'body' => $body,
                'headers' => $headers,
                'content_type' => $headers['content-type'] ?? 'application/pdf',
                'content_length' => $headers['content-length'] ?? strlen($body)
            );
        } else {
            $body = wp_remote_retrieve_body($response);
            $this->log_error('Binary API Error: HTTP ' . $response_code . ' (' . strlen($body) . ' bytes)');
            return false;
        }
    }


    /**
     * Value of the X-Livraria-Client header sent on every request to the
     * Livraria API: this plugin's version, then the WordPress, WooCommerce and
     * PHP versions, e.g.
     * "Livraria-WordPress-Plugin/1.0.3; WordPress/6.7.1; WooCommerce/9.4.0; PHP/8.2.10".
     *
     * Each token is "name/version"; the version keeps only A-Z a-z 0-9 . + _ -
     * and at most 40 characters (the backend ignores a token outside that
     * rule), "unknown" when nothing is left. The User-Agent stays
     * "WordPress-Livraria-Plugin/1.0": the backend reads the platform from it.
     *
     * @return string Header value
     */
    private function client_header() {
        $versions = array(
            'Livraria-WordPress-Plugin' => defined('LIVRARIA_WC_PLUGIN_VERSION') ? LIVRARIA_WC_PLUGIN_VERSION : '',
            'WordPress' => function_exists('get_bloginfo') ? get_bloginfo('version') : '',
            'WooCommerce' => defined('WC_VERSION') ? WC_VERSION : '',
            'PHP' => PHP_VERSION,
        );

        $tokens = array();
        foreach ($versions as $name => $version) {
            $clean = substr(preg_replace('/[^A-Za-z0-9.+_-]/', '', (string) $version), 0, 40);
            $tokens[] = $name . '/' . ($clean !== '' ? $clean : 'unknown');
        }

        return implode('; ', $tokens);
    }

    /**
     * Whether requests verify the API's TLS certificate. Always, except on a
     * development install whose wp-config.php enables WP_DEBUG and also
     * defines LIVRARIA_ALLOW_INSECURE_TLS as boolean true (for a local API
     * with a self-signed certificate). A shop has no setting for it.
     *
     * @return bool
     */
    public static function ssl_verify() {
        return !self::allow_insecure_tls();
    }

    /**
     * @return bool Whether this install may skip TLS verification (both constants above, set by hand)
     */
    public static function allow_insecure_tls() {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return false;
        }
        return defined('LIVRARIA_ALLOW_INSECURE_TLS') && LIVRARIA_ALLOW_INSECURE_TLS === true;
    }

    /**
     * Convert localhost/127.0.0.1 to host.docker.internal for Docker
     * 
     * @param string $url Original URL
     * @return string Converted URL
     */
    private function convert_localhost_for_docker($url) {
        // Check if we're likely in Docker
        $is_docker = (
            file_exists('/.dockerenv') || 
            getenv('DOCKER_CONTAINER') !== false ||
            (function_exists('gethostname') && strpos(gethostname(), 'docker') !== false)
        );
        
        if ($is_docker) {
            // Replace localhost/127.0.0.1 with host.docker.internal
            $url = preg_replace(
                '/^(https?:\/\/)(127\.0\.0\.1|localhost)(:(\d+))?/i',
                '$1host.docker.internal$3',
                $url
            );
        }
        
        return $url;
    }
    
    /**
     * Make a raw API request (without authentication)
     * Used for login and other non-authenticated endpoints
     * 
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array|null $data Request data
     * @return array|false API response or false on failure
     */
    private function raw_api_request($method, $endpoint, $data = null) {
        if (!$this->base_url_allowed()) {
            return false;
        }
        
        $url = rtrim($this->api_base_url, '/') . $endpoint;
        $url = $this->convert_localhost_for_docker($url);
        
        // Debug: Log the full URL being called
        $this->log_debug('Making request to URL: ' . $url);
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Livraria-Client' => $this->client_header()
            ),
            'timeout' => 30,
            'user-agent' => 'WordPress-Livraria-Plugin/1.0',
            'sslverify' => self::ssl_verify()
        );
        
        if ($data && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = json_encode($data);
            
            // Log request for debugging (dev mode only, password redacted)
            $this->log_debug('Raw API Request: ' . $method . ' ' . $url, $data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_error('Raw API Request Failed: ' . $error_message);
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Log response for debugging
        $this->log_debug('Raw API Response: ' . $response_code, $response_body);
        
        if ($response_code >= 200 && $response_code < 300) {
            $decoded = json_decode($response_body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log_error('Invalid JSON response: ' . json_last_error_msg());
                return false;
            }
            return $decoded;
        } else {
            $error_message = 'HTTP ' . $response_code;
            if ($response_body) {
                $error_data = json_decode($response_body, true);
                if (isset($error_data['message'])) {
                    $error_message .= ': ' . $error_data['message'];
                } else {
                    // Never the body itself: it can echo what was sent
                    $error_message .= ' (' . strlen($response_body) . ' bytes, no message field)';
                }
            }
            $this->log_error('Raw API Error: ' . $error_message);
            return false;
        }
    }
    
    /**
     * Make an API request
     * 
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array|null $data Request data
     * @return array|false API response or false on failure
     */
    private function api_request($method, $endpoint, $data = null) {
        if (!$this->base_url_allowed()) {
            return false;
        }
        
        // Ensure we have a valid token before making the request
        if (!$this->ensure_valid_token()) {
            $this->log_error('Unable to obtain valid API token');
            return false;
        }
        
        $url = rtrim($this->api_base_url, '/') . $endpoint;
        $url = $this->convert_localhost_for_docker($url);
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Livraria-Client' => $this->client_header()
            ),
            'timeout' => 30,
            'user-agent' => 'WordPress-Livraria-Plugin/1.0',
            'sslverify' => self::ssl_verify()
        );
        
        if ($data && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $json_body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $args['body'] = $json_body;

            // Dev mode only, redacted (this body was logged on every shop)
            $this->log_debug('API Request: ' . $method . ' ' . $url, $data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_error('API Request Failed: ' . $error_message);
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Log response for debugging
        $this->log_debug('API Response: ' . $response_code, $response_body);
        
        if ($response_code >= 200 && $response_code < 300) {
            $decoded = json_decode($response_body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log_error('Invalid JSON response: ' . json_last_error_msg());
                return false;
            }
            return $decoded;
        } else {
            $error_message = 'HTTP ' . $response_code;
            if ($response_body) {
                $error_data = json_decode($response_body, true);
                if (isset($error_data['message'])) {
                    $message = $error_data['message'];
                    // Handle case where message might be an array
                    if (is_array($message)) {
                        $message = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    }
                    $error_message .= ': ' . $message;
                } else {
                    // Never the body itself: it can echo what was sent
                    $error_message .= ' (' . strlen($response_body) . ' bytes, no message field)';
                }
            }
            $this->log_error('API Error: ' . $error_message);
            return false;
        }
    }
    
    /**
     * Log error message: when WP_DEBUG is on. Callers pass an HTTP status,
     * the API's message field or a WordPress error, never a request or
     * response body, a header, a token or a password.
     * 
     * @param string $message Error message
     */
    private function log_error($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Error logging only when WP_DEBUG is enabled, no secrets
            error_log('Livraria API Error: ' . $message);
        }
    }
    
    /**
     * Log debug message: development mode only (is_dev_mode()), with
     * passwords and tokens masked in $data. A shop's debug.log is often
     * readable by others (backups, support, a public wp-content/debug.log).
     * 
     * @param string $message Debug message
     * @param mixed $data Additional data to log (array or JSON string)
     */
    private function log_debug($message, $data = null) {
        if (!self::is_dev_mode()) {
            return;
        }
        $log_message = 'Livraria API Debug: ' . $message;
        if ($data !== null) {
            $log_message .= ' - ' . wp_json_encode(self::redact($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode, redacted
        error_log($log_message);
    }

    /**
     * Keys whose values are never logged (compared case-insensitively)
     */
    const REDACTED_KEYS = array('password', 'accesstoken', 'access_token', 'refreshtoken', 'refresh_token', 'idtoken', 'id_token', 'token', 'authorization');

    /**
     * $data with every REDACTED_KEYS value replaced by "***", at any depth.
     * A JSON string is decoded first; any other string is reduced to its length.
     *
     * @param mixed $data
     * @return mixed
     */
    public static function redact($data) {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                return self::redact($decoded);
            }
            return '[' . strlen($data) . ' bytes]';
        }
        if (!is_array($data)) {
            return $data;
        }
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }
        return $data;
    }
    
    /**
     * Get API base URL
     * 
     * @return string
     */
    public function get_api_base_url() {
        return $this->api_base_url;
    }
    
    /**
     * Set API base URL
     * 
     * @param string $url
     */
    public function set_api_base_url($url) {
        $this->api_base_url = rtrim(self::resolve_base_url($url), '/');
    }
    
    /**
     * Set API token
     * 
     * @param string $token
     */
    public function set_api_token($token) {
        $this->api_token = $token;
    }
    
    /**
     * Get available couriers from public endpoint
     * 
     * @return array|false Array of courier objects or false on failure
     */
    public function get_available_couriers() {
        return $this->raw_api_request('GET', '/public/couriers');
    }
    
    /**
     * Check if API is configured
     * 
     * @return bool
     */
    public function is_configured() {
        return !empty($this->api_base_url) && !empty($this->api_token);
    }
    
    /**
     * Test basic connectivity to the API server
     * 
     * @return array Result with connectivity info
     */
    public function test_connectivity() {
        if (!$this->base_url_allowed()) {
            return array(
                'success' => false,
                'message' => 'The configured API address is not a Livraria API host'
            );
        }
        
        // Convert localhost/127.0.0.1 to host.docker.internal for Docker environments
        $original_url = $this->api_base_url;
        $test_url = $this->convert_localhost_for_docker($original_url);
        
        if ($test_url !== $original_url) {
            $this->log_debug('Docker detected: Converted ' . $original_url . ' to ' . $test_url);
        }
        
        // Try a simple GET request to the base URL or a health check endpoint
        $test_url = rtrim($test_url, '/') . '/';
        
        $args = array(
            'method' => 'GET',
            'headers' => array(
                'X-Livraria-Client' => $this->client_header()
            ),
            'timeout' => 10,
            'user-agent' => 'WordPress-Livraria-Plugin/1.0',
            'sslverify' => self::ssl_verify()
        );
        
        $this->log_debug('Testing connectivity to: ' . $test_url);
        
        $response = wp_remote_request($test_url, $args);
        
        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            $this->log_debug('Connectivity test failed: ' . $error);
            
            $error_message = 'Network error: ' . $error;
            
            // Provide helpful Docker-specific guidance
            if ($test_url !== $original_url && (strpos($original_url, 'localhost') !== false || strpos($original_url, '127.0.0.1') !== false)) {
                $error_message .= '. Tip: In Docker, use "host.docker.internal" instead of "localhost". The URL was automatically converted to: ' . $test_url;
            }
            
            return array(
                'success' => false,
                'message' => $error_message,
                'details' => array(
                    'url' => $test_url,
                    'original_url' => $original_url,
                    'error_code' => $response->get_error_code(),
                    'error_message' => $error
                )
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $this->log_debug('Connectivity test response code: ' . $response_code);
        
        return array(
            'success' => true,
            'message' => 'Server is reachable (HTTP ' . $response_code . ')',
            'details' => array(
                'url' => $test_url,
                'original_url' => $original_url,
                'response_code' => $response_code,
                'headers' => wp_remote_retrieve_headers($response)
            )
        );
    }
    
    /**
     * Get encrypted option value (public method for use outside class)
     * Uses WordPress encryption if available, falls back to plain text for backwards compatibility
     * 
     * @param string $option_name Option name
     * @param mixed $default Default value
     * @return string Decrypted value
     */
    public function get_encrypted_option($option_name, $default = '') {
        $encrypted_value = get_option($option_name, $default);
        
        // If empty, return default
        if (empty($encrypted_value)) {
            return $default;
        }
        
        // Check if value is encrypted (starts with encrypted prefix)
        if (strpos($encrypted_value, 'livraria_encrypted:') === 0) {
            // Extract encrypted data
            $encrypted_data = substr($encrypted_value, strlen('livraria_encrypted:'));
            
            // Try to decrypt using WordPress functions (WordPress 5.2+)
            if (function_exists('wp_salt') && function_exists('openssl_decrypt')) {
                $key = wp_salt('nonce');
                $iv_length = openssl_cipher_iv_length('AES-256-CBC');
                
                // Extract IV and encrypted data
                $data = base64_decode($encrypted_data);
                if ($data !== false && strlen($data) > $iv_length) {
                    $iv = substr($data, 0, $iv_length);
                    $encrypted = substr($data, $iv_length);
                    
                    $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
                    if ($decrypted !== false) {
                        return $decrypted;
                    }
                }
            }
            
            // If decryption fails, return empty (credentials need to be re-entered)
            return '';
        }
        
        // Not encrypted (backwards compatibility) - return as-is
        return $encrypted_value;
    }
    
    /**
     * Whether this server can encrypt stored credentials (PHP OpenSSL)
     *
     * @return bool
     */
    public static function can_encrypt() {
        return function_exists('wp_salt') && function_exists('openssl_encrypt') && function_exists('openssl_random_pseudo_bytes');
    }

    /**
     * Set encrypted option value (public method for use outside class)
     * Encrypts sensitive data before storing. Without encryption nothing is
     * stored: a password in clear text is worse than no stored password.
     * 
     * @param string $option_name Option name
     * @param string $value Value to encrypt and store
     * @return bool Success; false when the value could not be encrypted (nothing stored)
     */
    public function set_encrypted_option($option_name, $value) {
        // If value is empty, just store it
        if (empty($value)) {
            return update_option($option_name, '');
        }
        
        // Try to encrypt using WordPress functions (WordPress 5.2+)
        if (self::can_encrypt()) {
            $key = wp_salt('nonce');
            $iv_length = openssl_cipher_iv_length('AES-256-CBC');
            $iv = openssl_random_pseudo_bytes($iv_length);
            
            $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv);
            if ($encrypted !== false) {
                // Store with prefix and IV
                $encrypted_data = base64_encode($iv . $encrypted);
                return update_option($option_name, 'livraria_encrypted:' . $encrypted_data);
            }
        }
        
        // Encryption unavailable or failed: refuse to store
        $this->log_error('Could not encrypt ' . $option_name . ' (is PHP OpenSSL enabled?); not stored.');
        return false;
    }
}