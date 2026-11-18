<?php
// Stand-in for WC_Shipping_Method: instance settings from
// $wp_test_shipping_settings[instance id], rates recorded in $rates.
if (!class_exists('WC_Shipping_Method')) {
    class WC_Shipping_Method {
        public $id = '';
        public $instance_id = 0;
        public $title = '';
        public $method_title = '';
        public $method_description = '';
        public $supports = array();
        public $instance_form_fields = array();
        public $rates = array();

        public function get_option($key, $empty_value = null) {
            global $wp_test_shipping_settings;
            $settings = isset($wp_test_shipping_settings[$this->instance_id]) ? $wp_test_shipping_settings[$this->instance_id] : array();
            return isset($settings[$key]) ? $settings[$key] : $empty_value;
        }

        public function get_rate_id($suffix = '') {
            return $this->id . ':' . $this->instance_id . ($suffix !== '' ? ':' . $suffix : '');
        }

        public function add_rate($args = array()) {
            $this->rates[] = $args;
        }

        public function process_admin_options() {
            return true;
        }
    }
}
