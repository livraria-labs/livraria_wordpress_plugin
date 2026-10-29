<?php
// Stand-ins for the WooCommerce Blocks / Store API pieces the checkout code touches.
namespace Automattic\WooCommerce\Blocks\Integrations {
    if (!interface_exists(IntegrationInterface::class)) {
        interface IntegrationInterface {
            public function get_name();
            public function initialize();
            public function get_script_handles();
            public function get_editor_script_handles();
            public function get_script_data();
        }
    }
}

namespace {
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }
    if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
        function woocommerce_store_api_register_endpoint_data($args) {
            global $wp_test_store_api;
            $wp_test_store_api[] = $args;
        }
    }
}
