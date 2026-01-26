<?php
/**
 * PHPUnit bootstrap for Livraria Plugin tests
 * 
 * This bootstrap uses mocked WordPress functions, allowing tests to run
 * without the WordPress test suite. Perfect for unit testing class logic.
 */

// Define WordPress constants and functions needed for plugin to load
if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
}

// Mock essential WordPress functions
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $wp_test_options;
        if (!isset($wp_test_options)) {
            $wp_test_options = array();
        }
        return isset($wp_test_options[$option]) ? $wp_test_options[$option] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value) {
        global $wp_test_options;
        if (!isset($wp_test_options)) {
            $wp_test_options = array();
        }
        $wp_test_options[$option] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option) {
        global $wp_test_options;
        if (isset($wp_test_options[$option])) {
            unset($wp_test_options[$option]);
        }
        return true;
    }
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = array()) {
        // This will be mocked in tests using pre_http_request filter
        $preempt = false;
        $result = apply_filters('pre_http_request', $preempt, $args, $url);
        // If filter returns a value (not false), use it
        if ($result !== false && $result !== $preempt) {
            return $result;
        }
        // Otherwise return false (will be treated as error in tests)
        return false;
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        if (is_wp_error($response)) {
            return 0;
        }
        if (isset($response['response']['code'])) {
            return $response['response']['code'];
        }
        return 0;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        if (is_wp_error($response)) {
            return '';
        }
        if (isset($response['body'])) {
            return $response['body'];
        }
        return '';
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args) {
        global $wp_test_filters;
        if (!isset($wp_test_filters)) {
            $wp_test_filters = array();
        }
        if (isset($wp_test_filters[$tag])) {
            foreach ($wp_test_filters[$tag] as $callback) {
                $value = call_user_func_array($callback, array_merge(array($value), $args));
            }
        }
        return $value;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {
        global $wp_test_filters;
        if (!isset($wp_test_filters)) {
            $wp_test_filters = array();
        }
        if (!isset($wp_test_filters[$tag])) {
            $wp_test_filters[$tag] = array();
        }
        $wp_test_filters[$tag][] = $callback;
        return true;
    }
}

if (!function_exists('remove_filter')) {
    function remove_filter($tag, $callback) {
        global $wp_test_filters;
        if (isset($wp_test_filters[$tag])) {
            $wp_test_filters[$tag] = array_filter($wp_test_filters[$tag], function($cb) use ($callback) {
                return $cb !== $callback;
            });
        }
        return true;
    }
}

if (!function_exists('error_log')) {
    function error_log($message) {
        // Silently ignore in tests
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return ($thing instanceof WP_Error);
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $errors = array();
        public $error_data = array();
        
        public function __construct($code = '', $message = '', $data = '') {
            if (empty($code)) {
                return;
            }
            $this->errors[$code][] = $message;
            if (!empty($data)) {
                $this->error_data[$code] = $data;
            }
        }
        
        public function get_error_code() {
            $codes = array_keys($this->errors);
            if (empty($codes)) {
                return '';
            }
            return $codes[0];
        }
        
        public function get_error_message($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            if (isset($this->errors[$code])) {
                return $this->errors[$code][0];
            }
            return '';
        }
    }
}

// Load the plugin classes directly
require_once dirname(dirname(__FILE__)) . '/includes/class-api-client.php';
require_once dirname(dirname(__FILE__)) . '/includes/class-order-handler.php';
