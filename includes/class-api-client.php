<?php
/**
 * API Client for Livraria Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Livraria_API_Client {
    
    private $api_base_url;
    private $api_token;
    private $token_expires_at;
    
    public function __construct($api_base_url = null) {
        $this->api_base_url = $api_base_url ?: get_option('courier_api_base_url', '');
        $this->api_token = get_option('livraria_api_token', '');
        $this->token_expires_at = get_option('livraria_token_expires_at', 0);
    }
    
    /**
     * Login to get JWT token
     * 
     * @param string $username Username/email
     * @param string $password Password
     * @return array Result with success status and message
     */
    public function login($username, $password) {
        // Debug: Log the attempt
        $this->log_debug('Login attempt for: ' . $username . ' to URL: ' . $this->api_base_url);
        
        $response = $this->raw_api_request('POST', '/auth/login', array(
            'email' => $username,
            'password' => $password
        ));
        
        // Debug: Log response details
        $this->log_debug('Login response received', $response);
        
        // Debug: Check what fields are in the response
        if ($response) {
            $this->log_debug('Response keys available', array_keys($response));
        }
        
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
            // Enhanced error message with debug info
            $error_details = array(
                'response_received' => $response !== false,
                'response_data' => $response,
                'api_url' => $this->api_base_url
            );
            
            $this->log_debug('Login failed with details', $error_details);
            
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
        
        // Try to refresh token using stored credentials
        $username = get_option('courier_api_username', '');
        $password = get_option('courier_api_password', '');
        
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
                    'token_expires_at' => date('Y-m-d H:i:s', $this->token_expires_at)
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
        if (empty($this->api_base_url)) {
            $this->log_error('API base URL not configured');
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
                'Accept' => 'application/json'
            ),
            'timeout' => 30,
            'user-agent' => 'WordPress-Livraria-Plugin/1.0',
            'sslverify' => false // For development/testing - set to true in production
        );
        
        if ($data && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = json_encode($data);
            
            // Log request for debugging
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
                    $error_message .= ': ' . $response_body;
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
        if (empty($this->api_base_url)) {
            $this->log_error('API base URL not configured');
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
                'Accept' => 'application/json'
            ),
            'timeout' => 30,
            'user-agent' => 'WordPress-Livraria-Plugin/1.0',
            'sslverify' => false // For development/testing - set to true in production
        );
        
        if ($data && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = json_encode($data);
            
            // Log request for debugging
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
                    $error_message .= ': ' . $error_data['message'];
                } else {
                    $error_message .= ': ' . $response_body;
                }
            }
            $this->log_error('API Error: ' . $error_message);
            return false;
        }
    }
    
    /**
     * Log error message
     * 
     * @param string $message Error message
     */
    private function log_error($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Livraria API Error: ' . $message);
        }
    }
    
    /**
     * Log debug message
     * 
     * @param string $message Debug message
     * @param mixed $data Additional data to log
     */
    private function log_debug($message, $data = null) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $log_message = 'Livraria API Debug: ' . $message;
            if ($data !== null) {
                $log_message .= ' - ' . print_r($data, true);
            }
            error_log($log_message);
        }
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
        $this->api_base_url = rtrim($url, '/');
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
        if (empty($this->api_base_url)) {
            return array(
                'success' => false,
                'message' => 'API base URL not configured'
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
            'timeout' => 10,
            'user-agent' => 'WordPress-Livraria-Plugin/1.0',
            'sslverify' => false
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
}