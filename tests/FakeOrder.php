<?php
/**
 * A stand-in WooCommerce order: the getters and setters the plugin calls, and
 * order meta kept on the object (as WC_Order does under HPOS). Fixtures use
 * invented people, phones 0700000000-0700000999 and example.com emails.
 */
class Livraria_Fake_Order {

    /** @var array field => value, e.g. billing_phone, shipping_city */
    public $fields = array();
    /** @var array meta key => value */
    public $meta = array();
    /** @var int */
    public $saves = 0;
    /** @var string[] */
    public $notes = array();
    /** @var array */
    public $items = array();

    private $id;

    public function __construct($id = 101, array $fields = array()) {
        $this->id = (int) $id;
        $this->fields = array_merge(array(
            'billing_first_name' => 'Ioana',
            'billing_last_name' => 'Exemplu',
            'billing_company' => '',
            'billing_address_1' => 'Strada Florilor 12',
            'billing_address_2' => '',
            'billing_city' => 'Cluj-Napoca',
            'billing_state' => 'CJ',
            'billing_postcode' => '400001',
            'billing_country' => 'RO',
            'billing_phone' => '0700000101',
            'billing_email' => 'ioana@example.com',
            'shipping_first_name' => '',
            'shipping_last_name' => '',
            'shipping_company' => '',
            'shipping_address_1' => '',
            'shipping_address_2' => '',
            'shipping_city' => '',
            'shipping_state' => '',
            'shipping_postcode' => '',
            'shipping_country' => '',
            'shipping_phone' => '',
            'payment_method' => 'bacs',
            'payment_method_title' => 'Direct bank transfer',
            'total' => '149.90',
        ), $fields);
    }

    public function get_id() { return $this->id; }
    public function get_status() { return isset($this->fields['status']) ? $this->fields['status'] : 'processing'; }
    public function get_order_number() { return (string) $this->id; }
    public function get_items() { return $this->items; }
    public function get_total() { return $this->fields['total']; }
    public function get_payment_method() { return $this->fields['payment_method']; }
    public function get_payment_method_title() { return $this->fields['payment_method_title']; }

    public function get_meta($key, $single = true) {
        return array_key_exists($key, $this->meta) ? $this->meta[$key] : '';
    }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function delete_meta_data($key) { unset($this->meta[$key]); }
    public function save() { $this->saves++; return $this->id; }
    public function add_order_note($note) { $this->notes[] = $note; return count($this->notes); }

    public function get_billing_first_name() { return $this->fields['billing_first_name']; }
    public function get_billing_last_name() { return $this->fields['billing_last_name']; }
    public function get_billing_company() { return $this->fields['billing_company']; }
    public function get_billing_address_1() { return $this->fields['billing_address_1']; }
    public function get_billing_address_2() { return $this->fields['billing_address_2']; }
    public function get_billing_city() { return $this->fields['billing_city']; }
    public function get_billing_state() { return $this->fields['billing_state']; }
    public function get_billing_postcode() { return $this->fields['billing_postcode']; }
    public function get_billing_country() { return $this->fields['billing_country']; }
    public function get_billing_phone() { return $this->fields['billing_phone']; }
    public function get_billing_email() { return $this->fields['billing_email']; }

    public function get_shipping_first_name() { return $this->fields['shipping_first_name']; }
    public function get_shipping_last_name() { return $this->fields['shipping_last_name']; }
    public function get_shipping_company() { return $this->fields['shipping_company']; }
    public function get_shipping_address_1() { return $this->fields['shipping_address_1']; }
    public function get_shipping_address_2() { return $this->fields['shipping_address_2']; }
    public function get_shipping_city() { return $this->fields['shipping_city']; }
    public function get_shipping_state() { return $this->fields['shipping_state']; }
    public function get_shipping_postcode() { return $this->fields['shipping_postcode']; }
    public function get_shipping_country() { return $this->fields['shipping_country']; }
    public function get_shipping_phone() { return $this->fields['shipping_phone']; }

    public function set_billing_email($value) { $this->fields['billing_email'] = $value; }
    public function set_billing_phone($value) { $this->fields['billing_phone'] = $value; }
    public function set_shipping_phone($value) { $this->fields['shipping_phone'] = $value; }
    public function set_shipping_postcode($value) { $this->fields['shipping_postcode'] = $value; }
    public function set_shipping_address_1($value) { $this->fields['shipping_address_1'] = $value; }
    public function set_shipping_city($value) { $this->fields['shipping_city'] = $value; }
    public function set_billing_postcode($value) { $this->fields['billing_postcode'] = $value; }
    public function set_billing_address_1($value) { $this->fields['billing_address_1'] = $value; }
    public function set_billing_city($value) { $this->fields['billing_city'] = $value; }
}

/** A stand-in product: weight and dimensions in the shop's units ('' = not set) */
class Livraria_Fake_Product {
    private $data;
    public function __construct($weight = '', $length = '', $width = '', $height = '', $name = 'Produs exemplu') {
        $this->data = compact('weight', 'length', 'width', 'height', 'name');
    }
    public function get_weight() { return $this->data['weight']; }
    public function get_length() { return $this->data['length']; }
    public function get_width() { return $this->data['width']; }
    public function get_height() { return $this->data['height']; }
    public function get_name() { return $this->data['name']; }
}

/** A stand-in order line */
class Livraria_Fake_Item {
    private $product;
    private $quantity;
    public function __construct($product, $quantity = 1) {
        $this->product = $product;
        $this->quantity = $quantity;
    }
    public function get_product() { return $this->product; }
    public function get_quantity() { return $this->quantity; }
}
