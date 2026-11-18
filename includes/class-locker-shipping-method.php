<?php
/**
 * "Livrare la locker": a shipping method the merchant adds to a shipping zone
 * like any other. When the buyer chooses it, the locker modal opens with every
 * courier's lockers and each one's price; the rate then costs what the chosen
 * locker costs (Livraria_Locker_Pricing). Loaded on woocommerce_shipping_init.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Livraria_Locker_Shipping_Method extends WC_Shipping_Method {

    public function __construct($instance_id = 0) {
        $this->id = Livraria_Locker_Pricing::METHOD_ID;
        $this->instance_id = absint($instance_id);
        $this->method_title = 'Livraria locker delivery';
        $this->method_description = 'The buyer picks a locker from one list of every Livraria courier (easybox Sameday, DPD and any other courier Livraria opens), each with its price: yours, or your live Livraria price with your markup.';
        $this->supports = array('shipping-zones', 'instance-settings', 'instance-settings-modal');
        $this->instance_form_fields = Livraria_Locker_Pricing::form_fields();
        $this->title = $this->get_option('title', Livraria_Locker_Pricing::DEFAULT_TITLE);
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    /**
     * @return array Livraria_Locker_Pricing::settings() of this instance
     */
    public function pricing_settings() {
        $values = array();
        foreach (Livraria_Locker_Pricing::defaults() as $key => $default) {
            $values[$key] = $this->get_option($key, $default);
        }
        return Livraria_Locker_Pricing::settings($values);
    }

    /**
     * @param array $package
     */
    public function calculate_shipping($package = array()) {
        $rate = Livraria_Locker_Pricing::rate_for_package($this->pricing_settings(), $package);
        if ($rate === null) {
            return;
        }
        $this->add_rate(array(
            'id' => $this->get_rate_id(),
            'label' => $rate['label'],
            'cost' => $rate['cost'],
            'package' => $package,
            'meta_data' => $rate['meta'],
        ));
    }
}
