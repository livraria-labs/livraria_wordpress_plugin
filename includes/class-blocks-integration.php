<?php
/**
 * Blocks checkout integration: loads the locker picker into the checkout
 * block (order summary slot) and hands it its settings. Loaded only when
 * WooCommerce Blocks provides IntegrationInterface.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Livraria_Blocks_Integration implements \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface {

    const BLOCKS_SCRIPT = 'livraria-checkout-blocks';

    public function get_name() {
        return 'livraria-lockers';
    }

    public function initialize() {
        Livraria_Checkout::register_picker_assets();
        wp_register_script(
            self::BLOCKS_SCRIPT,
            plugin_dir_url(dirname(dirname(__FILE__)) . '/courier-expedition-wp-plugin.php') . 'assets/checkout-lockers-blocks.js',
            array(Livraria_Checkout::SCRIPT, 'wp-element', 'wp-plugins', 'wp-data', 'wc-blocks-checkout', 'wc-settings'),
            LIVRARIA_WC_PLUGIN_VERSION,
            true
        );
        wp_enqueue_style(Livraria_Checkout::SCRIPT);
    }

    public function get_script_handles() {
        return empty(Livraria_Lockers::enabled_couriers()) ? array() : array(self::BLOCKS_SCRIPT);
    }

    public function get_editor_script_handles() {
        return array();
    }

    /**
     * Read in the script with wc.wcSettings.getSetting('livraria-lockers_data')
     */
    public function get_script_data() {
        return Livraria_Lockers::checkout_config();
    }
}
