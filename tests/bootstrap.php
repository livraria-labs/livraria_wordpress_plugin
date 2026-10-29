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
    function update_option($option, $value, $autoload = null) {
        global $wp_test_options;
        if (!isset($wp_test_options)) {
            $wp_test_options = array();
        }
        // As WordPress: false when the value is unchanged
        if (array_key_exists($option, $wp_test_options) && $wp_test_options[$option] === $value) {
            return false;
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

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '') {
        global $wp_test_bloginfo_version;
        if ($show === 'version') {
            return isset($wp_test_bloginfo_version) ? $wp_test_bloginfo_version : '6.7.1';
        }
        return '';
    }
}

// Stubs the main plugin file and the settings page need. AJAX responses
// throw Livraria_Test_Json_Response, which stops the handler the way
// wp_send_json_*() stops the request in WordPress.
class Livraria_Test_Json_Response extends Exception {
    public $success;
    public $data;
    public $status;

    public function __construct($success, $data, $status) {
        parent::__construct($success ? 'json success' : 'json error');
        $this->success = $success;
        $this->data = $data;
        $this->status = $status;
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status_code = null) {
        throw new Livraria_Test_Json_Response(true, $data, $status_code);
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status_code = null) {
        throw new Livraria_Test_Json_Response(false, $data, $status_code);
    }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action = -1, $query_arg = false, $stop = true) {
        return 1; // nonce checks are WordPress's; tests cover what follows them
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability, ...$args) {
        global $wp_test_user_caps;
        return is_array($wp_test_user_caps) && in_array($capability, $wp_test_user_caps, true);
    }
}

if (!function_exists('register_setting')) {
    function register_setting($option_group, $option_name, $args = array()) {
        global $wp_test_registered_settings;
        $wp_test_registered_settings[$option_name] = $option_group;
    }
}

if (!function_exists('wp_mail')) {
    function wp_mail($to, $subject, $message, $headers = '', $attachments = array()) {
        global $wp_test_mails;
        $wp_test_mails[] = array('to' => $to, 'subject' => $subject, 'message' => $message);
        return true;
    }
}

// A stand-in WooCommerce order for the meta box test
class Livraria_Test_Order {
}

if (!function_exists('wc_get_order')) {
    function wc_get_order($order_id) {
        global $wp_test_orders;
        return isset($wp_test_orders[$order_id]) ? $wp_test_orders[$order_id] : false;
    }
}

if (!function_exists('add_action')) {
    function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {
        global $wp_test_actions;
        $wp_test_actions[$tag][] = $callback;
        return true;
    }
}

// Legacy post meta (wp_postmeta): what 1.1.0 and earlier wrote. The plugin
// now only reads it (fallback) and deletes it; writing it is a test failure.
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) {
        global $wp_test_post_meta;
        return isset($wp_test_post_meta[$post_id][$key]) ? $wp_test_post_meta[$post_id][$key] : '';
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta($post_id, $key) {
        global $wp_test_post_meta;
        unset($wp_test_post_meta[$post_id][$key]);
        return true;
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $key, $value) {
        throw new LogicException('update_post_meta(' . $key . ') is not HPOS-safe; write through the order object');
    }
}

if (!function_exists('get_transient')) {
    function get_transient($key) {
        global $wp_test_transients;
        return isset($wp_test_transients[$key]) ? $wp_test_transients[$key] : false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration = 0) {
        global $wp_test_transients;
        $wp_test_transients[$key] = $value;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($key) {
        global $wp_test_transients;
        unset($wp_test_transients[$key]);
        return true;
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action = -1, $name = '_wpnonce') {
        echo '<input type="hidden" id="' . $name . '" name="' . $name . '" value="test-nonce" />';
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) {
        return 'test-nonce';
    }
}

if (!function_exists('selected')) {
    function selected($selected, $current = true, $echo = true) {
        $result = ((string) $selected === (string) $current) ? " selected='selected'" : '';
        if ($echo) {
            echo $result;
        }
        return $result;
    }
}

if (!function_exists('wc_add_notice')) {
    function wc_add_notice($message, $type = 'success') {
        global $wp_test_notices;
        $wp_test_notices[] = array('message' => $message, 'type' => $type);
    }
}

// Admin script registry, for the order-screen enqueue test
foreach (array('wp_enqueue_style', 'wp_enqueue_script', 'wp_register_script', 'wp_register_style', 'wp_add_inline_style') as $livraria_stub) {
    if (!function_exists($livraria_stub)) {
        eval('function ' . $livraria_stub . '($handle = "") { $GLOBALS["wp_test_enqueued"][] = "' . $livraria_stub . ':" . $handle; return true; }');
    }
}
if (!function_exists('is_checkout')) {
    function is_checkout() { return !empty($GLOBALS['wp_test_is_checkout']); }
}
if (!function_exists('wp_localize_script')) {
    function wp_localize_script($handle, $name, $data) {
        global $wp_test_localized;
        $wp_test_localized[$handle] = array('name' => $name, 'data' => $data);
        return true;
    }
}
if (!function_exists('add_meta_box')) {
    function add_meta_box($id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default') {
        global $wp_test_meta_boxes;
        $wp_test_meta_boxes[] = compact('id', 'screen', 'context', 'priority');
    }
}
if (!function_exists('wc_get_page_screen_id')) {
    function wc_get_page_screen_id($for) {
        return 'woocommerce_page_wc-orders';
    }
}
if (!function_exists('get_current_screen')) {
    function get_current_screen() {
        global $wp_test_screen;
        return $wp_test_screen;
    }
}

// Translation functions: English as written
if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = 'default') { echo htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr_e')) {
    function esc_attr_e($text, $domain = 'default') { echo htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = 'default') { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url = '') { return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args); }
}

if (!function_exists('absint')) {
    function absint($value) {
        return abs((int) $value);
    }
}

if (!function_exists('current_time')) {
    function current_time($type) {
        return $type === 'mysql' ? gmdate('Y-m-d H:i:s') : time();
    }
}

if (!function_exists('is_email')) {
    function is_email($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : false;
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email($email) {
        return trim((string) $email);
    }
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

// WooCommerce: the class the handler checks for, and WC()->countries with
// the Romanian states a test sets in $wp_test_ro_states
if (!class_exists('WooCommerce')) {
    class WooCommerce {
        public $countries;
        public $session;
        public function __construct() {
            $this->countries = new Livraria_Test_Countries();
            $this->session = new Livraria_Test_Session();
        }
    }
    // WC()->session: what a test puts in $wp_test_wc_session
    class Livraria_Test_Session {
        public function get($key, $default = null) {
            global $wp_test_wc_session;
            return isset($wp_test_wc_session[$key]) ? $wp_test_wc_session[$key] : $default;
        }
    }
    class Livraria_Test_Countries {
        public function get_states($country) {
            global $wp_test_ro_states;
            return $country === 'RO' && is_array($wp_test_ro_states) ? $wp_test_ro_states : array();
        }
    }
}

if (!function_exists('WC')) {
    function WC() {
        static $wc = null;
        if ($wc === null) {
            $wc = new WooCommerce();
        }
        return $wc;
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) {
        return rtrim(dirname($file), '/') . '/';
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) {
        return 'https://shop.example.com/wp-content/plugins/livraria/';
    }
}

if (!function_exists('plugin_basename')) {
    function plugin_basename($file) {
        return 'livraria/' . basename($file);
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $str)));
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) {
        return preg_match('#^https?://#i', (string) $url) ? (string) $url : '';
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) {
        return parse_url($url, $component);
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '') {
        return 'https://shop.example.com/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('checked')) {
    function checked($checked, $current = true, $echo = true) {
        $result = ((string) $checked === (string) $current) ? " checked='checked'" : '';
        if ($echo) {
            echo $result;
        }
        return $result;
    }
}

if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth') {
        return 'unit-test-salt-' . $scheme . '-not-a-real-key';
    }
}

require_once __DIR__ . '/FakeOrder.php';

// Load the main plugin file (it defines LIVRARIA_WC_PLUGIN_VERSION and
// requires the classes under includes/), then the classes directly.
require_once dirname(dirname(__FILE__)) . '/courier-expedition-wp-plugin.php';
require_once dirname(dirname(__FILE__)) . '/includes/class-api-client.php';
require_once dirname(dirname(__FILE__)) . '/includes/class-order-handler.php';
require_once dirname(dirname(__FILE__)) . '/includes/class-admin-page.php';
require_once dirname(dirname(__FILE__)) . '/includes/class-lockers.php';
require_once dirname(dirname(__FILE__)) . '/includes/class-checkout.php';
