<?php
/**
 * Plugin Name: Livraria Shipping for WooCommerce
 * Description: Automatically create and manage shipping expeditions for WooCommerce orders via the Livraria courier API.
 * Version: 1.1.0
 * Author: Livraria S.R.L.
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: livraria-shipping-for-woocommerce
 * Requires Plugins: woocommerce
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Must equal the plugin header Version line above; deploy.sh refuses to
// build when the two differ. Sent to the Livraria API in X-Livraria-Client.
define('LIVRARIA_WC_PLUGIN_VERSION', '1.1.0');

// Include required classes
require_once plugin_dir_path(__FILE__) . 'includes/class-api-client.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-order-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-admin-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-lockers.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-checkout.php';

/**
 * Tell WooCommerce the plugin works with High-Performance Order Storage:
 * every order meta read and write goes through the order object
 * (Livraria_Order_Handler::get_order_meta() / set_order_meta()).
 */
function livraria_declare_hpos_compatibility() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
}
add_action('before_woocommerce_init', 'livraria_declare_hpos_compatibility');

class LivrariaPlugin {
    
    private $api_base_url;
    private $api_token;
    private $api_client;
    private $order_handler;
    private $admin_page;
    
    /**
     * Check if plugin is running in development mode: WP_DEBUG on and
     * LIVRARIA_DEV_MODE defined as boolean true in wp-config.php (see
     * Livraria_API_Client::is_dev_mode()). The site's address, the presence
     * of dev-scripts/ or WP_DEBUG alone no longer count: development mode
     * unlocks the API base URL, so only the site owner may turn it on.
     * 
     * @return bool True if in development mode, false if in production
     */
    public static function is_development_mode() {
        return Livraria_API_Client::is_dev_mode();
    }
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
        add_action('admin_notices', array($this, 'render_blocked_api_url_notice'));
        add_action('admin_notices', array($this, 'render_session_expired_notice'));
        
        // Add settings link to plugin page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_plugin_action_links'));
        
        // WooCommerce integration
        // Hook into order status change - but use a flag to prevent immediate execution
        // JavaScript will handle the actual auto-create to prevent page refresh
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 3);
        add_action('add_meta_boxes', array($this, 'add_expedition_meta_box'));
        add_action('add_meta_boxes_shop_order', array($this, 'add_expedition_meta_box'));
        add_action('add_meta_boxes_woocommerce_page_wc-orders', array($this, 'add_expedition_meta_box'));
        add_action('save_post', array($this, 'save_expedition_meta'));
        
        // AJAX handlers
        add_action('wp_ajax_livraria_get_quotes_for_order', array($this, 'ajax_get_quotes_for_order'));
        add_action('wp_ajax_livraria_select_quote', array($this, 'ajax_select_quote'));
        add_action('wp_ajax_livraria_generate_label', array($this, 'ajax_generate_label'));
        add_action('wp_ajax_livraria_auto_create_expedition', array($this, 'ajax_auto_create_expedition'));
        // order.js clears the 60 s "auto-create pending" flag with this action
        add_action('wp_ajax_clear_auto_create_flag', array($this, 'ajax_clear_auto_create_flag'));
        add_action('wp_ajax_livraria_test_courier_api_connection', array($this, 'ajax_test_api_connection'));
        add_action('wp_ajax_livraria_test_connectivity', array($this, 'ajax_test_connectivity'));
        add_action('wp_ajax_livraria_logout', array($this, 'ajax_logout'));
        add_action('wp_ajax_livraria_login', array($this, 'ajax_login'));
        add_action('wp_ajax_livraria_update_option', array($this, 'ajax_update_option'));
        add_action('wp_ajax_livraria_download_awb_pdf', array($this, 'ajax_download_awb_pdf'));
        add_action('wp_ajax_livraria_delete_expedition', array($this, 'ajax_delete_expedition'));
        add_action('wp_ajax_livraria_update_order_contact', array($this, 'ajax_update_order_contact'));
        add_action('wp_ajax_livraria_reload_sender_profiles', array($this, 'ajax_reload_sender_profiles'));
        // Locker list: the order panel (merchant) and the checkout (buyer, logged in or not)
        add_action('wp_ajax_livraria_lockers', array($this, 'ajax_admin_lockers'));
        add_action('wp_ajax_livraria_closest_locker', array($this, 'ajax_closest_locker'));
        add_action('wp_ajax_livraria_checkout_lockers', array($this, 'ajax_checkout_lockers'));
        add_action('wp_ajax_nopriv_livraria_checkout_lockers', array($this, 'ajax_checkout_lockers'));
        add_action('updated_option', array($this, 'forget_sender_profiles_on_settings_save'), 10, 1);

        // A stored API URL off the Livraria allow-list stops all API calls
        Livraria_API_Client::check_stored_api_url();
        $this->api_base_url = Livraria_API_Client::resolve_base_url();
        
        // Initialize API client and order handler  
        $this->api_client = new Livraria_API_Client($this->api_base_url);
        $this->order_handler = new Livraria_Order_Handler($this->api_client);
        $this->admin_page = new Livraria_Admin_Page($this->api_client);

        // The buyer's locker choice on the classic and the blocks checkout
        (new Livraria_Checkout())->register();
    }
    
    public function init() {
        // Debug: Log current screen info for troubleshooting
        add_action('current_screen', array($this, 'debug_current_screen'));
    }
    
    public function debug_current_screen($screen) {
        if ($screen && (strpos($screen->id, 'order') !== false || strpos($screen->id, 'shop') !== false)) {
            if (self::is_development_mode()) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
                error_log('Livraria Debug: Current screen ID: ' . $screen->id . ', Base: ' . $screen->base . ', Post Type: ' . ($screen->post_type ?? 'none'));
            }
        }
    }
    
    public function add_plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=courier-api-settings') . '">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    public function add_admin_menu() {
        add_options_page(
            'Livraria Settings',
            'Livraria',
            'manage_options',
            'courier-api-settings',
            array($this->admin_page, 'render')
        );
    }
    
    public function admin_init() {
        // courier_api_base_url and courier_api_username are deliberately not
        // registered: options.php must not write them (another API host).
        // The login is their only writer. The password is never stored.
        register_setting('courier_api_settings', 'courier_auto_create', array('sanitize_callback' => array($this, 'sanitize_boolean')));
        foreach (Livraria_Lockers::setting_names() as $locker_setting) {
            register_setting('courier_api_settings', $locker_setting, array('sanitize_callback' => array($this, 'sanitize_flag')));
        }
        register_setting('courier_api_settings', 'courier_default_sender_name', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('courier_api_settings', 'courier_default_sender_email', array('sanitize_callback' => 'sanitize_email'));
        register_setting('courier_api_settings', 'courier_default_sender_phone', array('sanitize_callback' => 'sanitize_text_field'));
        
        // Individual address fields
        register_setting('courier_api_settings', 'courier_sender_country', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('courier_api_settings', 'courier_sender_county', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('courier_api_settings', 'courier_sender_city', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('courier_api_settings', 'courier_sender_postcode', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('courier_api_settings', 'courier_sender_street', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('courier_api_settings', 'courier_sender_street_number', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('courier_api_settings', 'courier_sender_block', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('courier_api_settings', 'courier_sender_staircase', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('courier_api_settings', 'courier_sender_floor', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('courier_api_settings', 'courier_sender_apartment', array('sanitize_callback' => 'sanitize_text_field'));
    }
    
    /**
     * Sanitize boolean setting value
     * 
     * @param mixed $value The value to sanitize
     * @return int 1 for true, 0 for false
     */
    public function sanitize_boolean($value) {
        return absint($value) ? 1 : 0;
    }

    /**
     * Sanitize an on/off setting stored as '1' / '0' (locker settings: unset means on)
     *
     * @param mixed $value
     * @return string
     */
    public function sanitize_flag($value) {
        return absint($value) ? '1' : '0';
    }
    
    /**
     * Get metabox title with logo
     * 
     * @return string HTML for metabox title
     */
    private function get_metabox_title() {
        $logo_url = plugin_dir_url(__FILE__) . 'assets/logo.png';
        $logo_path = plugin_dir_path(__FILE__) . 'assets/logo.png';
        
        // Check if logo exists, otherwise use a placeholder or just text
        if (file_exists($logo_path)) {
            return '<span class="livraria-box-title"><img class="livraria-box-logo" src="' . esc_url($logo_url) . '" alt="" /> ' . esc_html__('Deliver with Livraria', 'livraria-shipping-for-woocommerce') . '</span>';
        }
        
        // Fallback: return text only if logo doesn't exist
        return 'Deliver with Livraria';
    }
    
    /**
     * The order screen box. A new id (it was "livraria-expedition" in the
     * sidebar): WordPress keeps each user's dragged box positions by id, so
     * the old id would have kept the box in the sidebar for anyone who ever
     * rearranged the order screen.
     */
    const META_BOX_ID = 'livraria-shipping';

    public function add_expedition_meta_box() {
        // Debug: Log when this method is called (only in development mode)
        if (self::is_development_mode()) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
            error_log('Livraria Debug: add_expedition_meta_box called');
        }
        
        $metabox_title = $this->get_metabox_title();
        
        // For traditional WooCommerce order pages (post-based)
        add_meta_box(
            self::META_BOX_ID,
            $metabox_title,
            array($this, 'expedition_meta_box_callback'),
            'shop_order',
            'normal',
            'high'
        );
        if (self::is_development_mode()) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
            error_log('Livraria Debug: Added metabox for shop_order');
        }
        
        // For WooCommerce High-Performance Order Storage (HPOS)
        if (class_exists('Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore')) {
            add_meta_box(
                self::META_BOX_ID,
                $metabox_title,
                array($this, 'expedition_meta_box_callback'),
                wc_get_page_screen_id('shop-order'),
                'normal',
                'high'
            );
        }
        
        // Alternative screen IDs that WooCommerce might use
        $screen = get_current_screen();
        if ($screen && in_array($screen->id, array('woocommerce_page_wc-orders', 'shop_order'))) {
            add_meta_box(
                self::META_BOX_ID,
                $metabox_title,
                array($this, 'expedition_meta_box_callback'),
                $screen->id,
                'normal',
                'high'
            );
        }
    }
    
    public function expedition_meta_box_callback($post_or_order) {
        // Handle both post-based orders and HPOS (order object)
        $order_id = null;
        $order = null;
        
        // Check if it's an order object (HPOS)
        if (is_a($post_or_order, 'WC_Order')) {
            $order = $post_or_order;
            $order_id = $order->get_id();
        } 
        // Check if it's a post object with ID
        elseif (isset($post_or_order->ID)) {
            $order_id = $post_or_order->ID;
            $order = wc_get_order($order_id);
        }
        // Fallback: try to get order ID from GET parameter (HPOS)
        elseif (isset($_GET['id'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET param used only to identify the current order for display, no state change
            $order_id = absint(wp_unslash($_GET['id'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $order = wc_get_order($order_id);
        }
        // Another fallback: try post parameter
        elseif (isset($_GET['post'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET param used only to identify the current order for display, no state change
            $order_id = absint(wp_unslash($_GET['post'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $order = wc_get_order($order_id);
        }
        
        // Debug log (only in development mode)
        if (self::is_development_mode()) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
            error_log('Livraria Debug: Metabox callback - Order ID: ' . ($order_id ?? 'unknown') . ', Order object: ' . ($order ? 'yes' : 'no'));
        }
        
        if (!$order_id || !$order) {
            echo '<p>Error: Could not load order information.</p>';
            return;
        }

        if (self::is_draft_order($order)) {
            // WooCommerce > Orders > Add order: nothing to quote until the order is saved
            echo '<p>' . esc_html('Save the order first, then quote it here.') . '</p>';
            return;
        }

        if (Livraria_API_Client::blocked_api_url() !== '') {
            echo '<p class="livraria-panel-error">' . esc_html('The configured Livraria API address is not a Livraria API host, so shipments cannot be created or downloaded. An administrator must log in again from Settings > Livraria.') . '</p>';
            return;
        }

        if (Livraria_API_Client::is_session_expired()) {
            echo '<p class="livraria-panel-error">' . esc_html('Livraria session expired, log in again: an administrator must log in from Settings > Livraria before shipments can be created or downloaded.') . '</p>';
            return;
        }
        
        wp_nonce_field('courier_expedition_nonce', 'courier_expedition_nonce_field');
        
        $expedition_id = Livraria_Order_Handler::get_order_meta($order_id, '_courier_expedition_id');
        $awb_number = Livraria_Order_Handler::get_order_meta($order_id, '_courier_awb_number');
        $courier_name = Livraria_Order_Handler::get_order_meta($order_id, '_courier_name');

        // Debug: Log what we're getting from meta (only in development mode)
        if ($expedition_id && self::is_development_mode()) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
            error_log('Livraria Debug Display: Order ' . $order_id . ' - Expedition ID: ' . $expedition_id . ', AWB: ' . $awb_number . ', Courier: "' . $courier_name . '"');
        }

        // COD orders (Livraria_Order_Handler::is_cod_order()) start with the
        // order total as the amount to collect; the merchant can change it
        $is_cod = Livraria_Order_Handler::is_cod_order($order);
        $cod_amount_default = $is_cod ? $order->get_total() : '0';
        
        echo '<div id="courier-expedition-meta" class="livraria-panel">';
        $this->render_status_line((bool) $expedition_id);
        if ($expedition_id) {
            $this->render_shipment_card($order_id, $expedition_id, $awb_number, $courier_name);
        } else {
            $this->render_route_section($order);
            $this->render_parcels_section($order);
            $this->render_options_section($order_id, $is_cod, $cod_amount_default);
            $this->render_quotes_section();
        }
        echo '</div>';
    }

    /**
     * The status line on top of the box: which Livraria API (a badge when
     * it is not production), the connection, and "AWB created"
     *
     * @param bool $has_shipment
     */
    private function render_status_line($has_shipment) {
        $environment = self::environment_badge($this->api_client->get_api_base_url());
        echo '<div class="lp-status">';
        if ($environment !== '') {
            echo '<span class="lp-badge lp-badge--env livraria-env-badge">' . esc_html($environment) . '</span>';
        }
        if ($has_shipment) {
            echo '<span class="lp-badge lp-badge--ok">✓ ' . esc_html__('AWB created', 'livraria-shipping-for-woocommerce') . '</span>';
        }
        if ($environment !== '') {
            echo '<span class="lp-status__text">' . esc_html($environment === 'UAT'
                ? __('Connected to the Livraria test API (uat): AWBs created here are real on uat couriers.', 'livraria-shipping-for-woocommerce')
                : __('Connected to a Livraria development API.', 'livraria-shipping-for-woocommerce')) . '</span>';
        } else {
            echo '<span class="lp-status__text">' . esc_html__('Connected to Livraria', 'livraria-shipping-for-woocommerce') . '</span>';
        }
        echo '</div>';
    }

    /**
     * An order with a shipment: courier and AWB, the expedition id, the AWB
     * actions, and removing the local record (which cancels nothing)
     */
    private function render_shipment_card($order_id, $expedition_id, $awb_number, $courier_name) {
        $download = function($size) use ($order_id, $awb_number) {
            return add_query_arg(array(
                'action' => 'livraria_download_awb_pdf',
                'order_id' => $order_id,
                'awb_number' => $awb_number,
                'paperSize' => $size,
                'nonce' => wp_create_nonce('courier_expedition_nonce'),
            ), admin_url('admin-ajax.php'));
        };
        ?>
        <div class="lp-card">
            <p class="lp-card__title">
                <?php if ($courier_name): ?><span class="lp-card__courier"><?php echo esc_html($courier_name); ?></span><?php endif; ?>
                <?php if ($awb_number): ?><span class="lp-card__awb"><?php esc_html_e('AWB', 'livraria-shipping-for-woocommerce'); ?> <code><?php echo esc_html($awb_number); ?></code></span><?php endif; ?>
            </p>
            <p class="lp-card__meta"><?php esc_html_e('Expedition', 'livraria-shipping-for-woocommerce'); ?> <code><?php echo esc_html($expedition_id); ?></code></p>
            <?php if ($awb_number): ?>
                <div class="lp-card__actions awb-actions">
                    <a href="<?php echo esc_url('https://livraria.ro/dashboard/track?awbNumber=' . urlencode($awb_number)); ?>" target="_blank" rel="noopener" class="button"><?php esc_html_e('Track', 'livraria-shipping-for-woocommerce'); ?></a>
                    <a href="<?php echo esc_url($download('A4')); ?>" class="button download-awb-pdf" data-awb="<?php echo esc_attr($awb_number); ?>" data-size="A4"><?php esc_html_e('Download A4', 'livraria-shipping-for-woocommerce'); ?></a>
                    <a href="<?php echo esc_url($download('A6')); ?>" class="button download-awb-pdf" data-awb="<?php echo esc_attr($awb_number); ?>" data-size="A6"><?php esc_html_e('Download A6', 'livraria-shipping-for-woocommerce'); ?></a>
                </div>
            <?php endif; ?>
            <div class="lp-card__danger">
                <button type="button" id="livraria-delete-expedition-btn" class="button-link button-link-delete"
                        data-order-id="<?php echo absint($order_id); ?>"
                        data-nonce="<?php echo esc_attr(wp_create_nonce('courier_expedition_nonce')); ?>"><?php esc_html_e('Remove local record', 'livraria-shipping-for-woocommerce'); ?></button>
                <p class="description"><?php esc_html_e('This only removes the record from this order. It does not cancel the AWB with the courier.', 'livraria-shipping-for-woocommerce'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Parcels: one row per parcel (weight, length, width, height), worked
     * out from the products as one parcel; the merchant can change or add rows
     *
     * @param WC_Order $order
     */
    private function render_parcels_section($order) {
        $parcels = $this->order_handler->calculate_packages_from_order($order);
        $parcel = $parcels[0];
        $columns = array(
            'package_weight' => array(__('Weight (kg)', 'livraria-shipping-for-woocommerce'), 'weight', '0.01', '1'),
            'package_length' => array(__('Length (cm)', 'livraria-shipping-for-woocommerce'), 'length', '1', '10'),
            'package_width' => array(__('Width (cm)', 'livraria-shipping-for-woocommerce'), 'width', '1', '10'),
            'package_height' => array(__('Height (cm)', 'livraria-shipping-for-woocommerce'), 'height', '1', '10'),
        );
        ?>
        <section class="lp-section livraria-packages-section" aria-labelledby="lp-parcels-title">
            <h3 class="lp-label" id="lp-parcels-title"><?php esc_html_e('Parcels', 'livraria-shipping-for-woocommerce'); ?></h3>
            <table class="widefat livraria-packages">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Parcel', 'livraria-shipping-for-woocommerce'); ?></th>
                        <?php foreach ($columns as $column): ?><th scope="col"><?php echo esc_html($column[0]); ?></th><?php endforeach; ?>
                        <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Remove', 'livraria-shipping-for-woocommerce'); ?></span></th>
                    </tr>
                </thead>
                <tbody id="packages-container">
                    <tr class="package-item" data-package="1">
                        <th scope="row" class="livraria-package-label"><?php esc_html_e('Parcel 1', 'livraria-shipping-for-woocommerce'); ?></th>
                        <?php foreach ($columns as $name => $column): ?>
                            <td data-label="<?php echo esc_attr($column[0]); ?>"><input type="number" name="<?php echo esc_attr($name); ?>[]" step="<?php echo esc_attr($column[2]); ?>" min="<?php echo esc_attr($column[3]); ?>" value="<?php echo esc_attr($parcel[$column[1]]); ?>" aria-label="<?php echo esc_attr($column[0]); ?>"></td>
                        <?php endforeach; ?>
                        <td class="lp-remove-cell"><button type="button" class="button-link button-link-delete remove-package" hidden><?php esc_html_e('Remove', 'livraria-shipping-for-woocommerce'); ?></button></td>
                    </tr>
                </tbody>
            </table>
            <p class="lp-row-actions"><button type="button" id="add-package-btn" class="button"><?php esc_html_e('Add parcel', 'livraria-shipping-for-woocommerce'); ?></button></p>
            <p class="lp-field">
                <label for="livraria-content-description"><?php esc_html_e('Content description', 'livraria-shipping-for-woocommerce'); ?></label>
                <textarea id="livraria-content-description" name="content_description" rows="2" placeholder="<?php esc_attr_e('Describe the parcel contents', 'livraria-shipping-for-woocommerce'); ?>"></textarea>
            </p>
        </section>
        <?php
    }

    /**
     * Options: COD amount, insurance, open on delivery, Saturday delivery,
     * sender profile
     */
    private function render_options_section($order_id, $is_cod, $cod_amount_default) {
        ?>
        <section class="lp-section livraria-options-section" aria-labelledby="lp-options-title">
            <h3 class="lp-label" id="lp-options-title"><?php esc_html_e('Options', 'livraria-shipping-for-woocommerce'); ?>
                <?php if ($is_cod): ?>
                    <span class="lp-badge lp-badge--info service-badge cod"><?php esc_html_e('Cash on delivery', 'livraria-shipping-for-woocommerce'); ?></span>
                <?php else: ?>
                    <span class="lp-badge service-badge no-cod"><?php esc_html_e('No COD', 'livraria-shipping-for-woocommerce'); ?></span>
                <?php endif; ?>
            </h3>
            <div class="livraria-options">
                <p class="lp-field lp-field--amount">
                    <label for="livraria-cod-amount"><?php esc_html_e('COD amount (RON)', 'livraria-shipping-for-woocommerce'); ?></label>
                    <input type="number" id="livraria-cod-amount" name="cod_amount" step="0.01" min="0" value="<?php echo esc_attr($cod_amount_default); ?>">
                </p>
                <p class="lp-field lp-field--amount">
                    <label for="livraria-insurance-amount"><?php esc_html_e('Insurance (RON)', 'livraria-shipping-for-woocommerce'); ?></label>
                    <input type="number" id="livraria-insurance-amount" name="insurance_amount" step="0.01" min="0" value="0">
                </p>
                <p class="lp-field lp-field--check">
                    <label><input type="checkbox" name="open_on_delivery" value="1"> <?php esc_html_e('Open on delivery', 'livraria-shipping-for-woocommerce'); ?></label>
                    <label><input type="checkbox" name="saturday_delivery" value="1"> <?php esc_html_e('Saturday delivery', 'livraria-shipping-for-woocommerce'); ?></label>
                </p>
                <div class="lp-field lp-field--profile">
                    <?php if (Livraria_API_Client::cached_sender_profiles() !== false): ?>
                        <label for="livraria-sender-profile-select"><?php esc_html_e('Sender profile', 'livraria-shipping-for-woocommerce'); ?></label>
                    <?php else: ?>
                        <span class="lp-field__label"><?php esc_html_e('Sender profile', 'livraria-shipping-for-woocommerce'); ?></span>
                    <?php endif; ?>
                    <?php $this->render_sender_profile_select($order_id); ?>
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * Quotes (filled by order.js) and the one primary action: "Get quotes",
     * then "Create AWB · <courier> · <price>" once a quote is selected
     */
    private function render_quotes_section() {
        ?>
        <section id="quotes-section" class="lp-section" aria-labelledby="lp-quotes-title" hidden>
            <h3 class="lp-label" id="lp-quotes-title"><?php esc_html_e('Quotes', 'livraria-shipping-for-woocommerce'); ?></h3>
            <div id="quotes-container"></div>
            <div id="quote-selection-result" class="lp-messages" aria-live="polite"></div>
        </section>
        <div class="lp-actions livraria-actions">
            <div id="expedition-result" class="lp-messages" aria-live="polite"></div>
            <div id="label-result" class="lp-messages" aria-live="polite"></div>
            <div class="lp-actions__bar">
                <span id="expedition-loading" class="lp-busy" hidden><span class="spinner is-active"></span><?php esc_html_e('Getting quotes…', 'livraria-shipping-for-woocommerce'); ?></span>
                <span id="label-loading" class="lp-busy" hidden><span class="spinner is-active"></span><?php esc_html_e('Creating the AWB…', 'livraria-shipping-for-woocommerce'); ?></span>
                <button type="button" id="create-expedition-btn" class="button button-primary"><?php esc_html_e('Get quotes', 'livraria-shipping-for-woocommerce'); ?></button>
                <span id="generate-label-section" hidden><button type="button" id="generate-label-btn" class="button button-primary"><?php esc_html_e('Create AWB', 'livraria-shipping-for-woocommerce'); ?></button></span>
            </div>
        </div>
        <?php
    }


    /**
     * What order.js needs for the Route control, from the order's data only:
     * the buyer's pick and the locker picker's settings (English)
     *
     * @param int $order_id
     * @return array buyerPick, lockerPicker
     */
    public static function route_script_data($order_id) {
        $order = $order_id && function_exists('wc_get_order') ? wc_get_order($order_id) : false;
        $pick = $order ? Livraria_Order_Handler::read_buyer_pick($order) : null;
        if ($pick) {
            $pick['display'] = $pick['supported'] ? Livraria_Lockers::display($pick) : $pick['name'];
            $pick['message'] = Livraria_Order_Handler::unusable_pick_message($pick);
        }
        $couriers = array();
        foreach (Livraria_Lockers::enabled_couriers() as $slug) {
            $couriers[] = array('slug' => $slug, 'label' => Livraria_Lockers::COURIERS[$slug]['product']);
        }
        return array(
            'buyerPick' => $pick,
            'lockerPicker' => array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'action' => 'livraria_lockers',
                'nonce' => wp_create_nonce('courier_expedition_nonce'),
                'couriers' => $couriers,
                'showModeChoice' => false,
                'strings' => array(
                    'filterAll' => 'All', 'countyLabel' => 'County', 'countyAll' => 'All counties', 'countyPick' => 'Choose the county', 'cityLabel' => 'City', 'cityAll' => 'All cities',
                    'search' => 'Search by name or address', 'loading' => 'Loading lockers…', 'error' => 'Could not load the lockers.', 'retry' => 'Retry',
                    'select' => 'Select', 'change' => 'Change', 'clearFilters' => 'Clear filters', 'chosen' => 'Chosen:', 'gate' => 'Choose a locker.',
                    'showingFirst' => 'Showing the first {cap} of {total} lockers: narrow the search.', 'none' => 'No locker matches.',
                ),
            ),
        );
    }

    /**
     * The order the current order screen shows: ?id= (HPOS) or ?post=
     * (classic), else WooCommerce's global $theorder (set on screens such as
     * HPOS "Add order"), else 0
     *
     * @return int
     */
    public static function current_order_id() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET params used only to identify the order for script localization, no state change
        $order_id = isset($_GET['id']) ? absint($_GET['id']) : (isset($_GET['post']) ? absint($_GET['post']) : 0);
        if ($order_id) {
            return $order_id;
        }
        $theorder = isset($GLOBALS['theorder']) ? $GLOBALS['theorder'] : null;
        return is_a($theorder, 'WC_Order') ? (int) $theorder->get_id() : 0;
    }

    /**
     * An order not saved yet (the "Add order" screen): auto-draft, or a
     * checkout draft
     *
     * @param WC_Order $order
     * @return bool
     */
    public static function is_draft_order($order) {
        if (!is_object($order) || !method_exists($order, 'get_status')) {
            return false;
        }
        return in_array((string) $order->get_status(), array('auto-draft', 'checkout-draft'), true);
    }

    /**
     * "UAT" / "DEV" when the API is not production, '' for production
     *
     * @param string $base_url
     * @return string
     */
    public static function environment_badge($base_url) {
        $host = strtolower(Livraria_API_Client::url_host($base_url));
        if ($host === 'www.api.livraria.ro' || $host === 'api.livraria.ro') {
            return '';
        }
        return strpos($host, 'api-uat.livraria.ro') !== false ? 'UAT' : 'DEV';
    }

    /**
     * The Route control of the order panel, from the order's data only:
     * origin (courier pickup, or drop-off at a locker), destination (the
     * buyer's pick, the order address, or a locker), and the banner about the
     * buyer's pick. order.js fills the lockers in and keeps it in step.
     *
     * @param WC_Order $order
     */
    private function render_route_section($order) {
        $pick = Livraria_Order_Handler::read_buyer_pick($order);
        $banner = Livraria_Order_Handler::route_banner($pick, $pick ? 'buyer' : 'order');
        ?>
        <section class="lp-section" id="livraria-route" aria-labelledby="lp-route-title">
            <h3 class="lp-label" id="lp-route-title"><?php esc_html_e('Route', 'livraria-shipping-for-woocommerce'); ?></h3>
            <div id="livraria-route-banner" aria-live="polite">
                <?php if ($banner): ?>
                    <div class="notice notice-<?php echo esc_attr($banner['type'] === 'buyer' ? 'info' : 'warning'); ?> inline lp-notice"><p><?php echo esc_html($banner['message']); ?></p></div>
                <?php endif; ?>
            </div>
            <div class="livraria-route-grid lp-route">
                <div class="lp-field livraria-route-end">
                    <label for="livraria-origin-mode"><?php esc_html_e('From', 'livraria-shipping-for-woocommerce'); ?></label>
                    <select id="livraria-origin-mode">
                        <option value="pickup"><?php esc_html_e('Courier pickup at the sender address', 'livraria-shipping-for-woocommerce'); ?></option>
                        <option value="locker"><?php esc_html_e('Drop-off at a locker', 'livraria-shipping-for-woocommerce'); ?></option>
                    </select>
                    <div id="livraria-origin-locker" class="lp-end-detail"></div>
                </div>
                <div class="lp-field livraria-route-end">
                    <label for="livraria-destination-mode"><?php esc_html_e('To', 'livraria-shipping-for-woocommerce'); ?></label>
                    <select id="livraria-destination-mode">
                        <?php if ($pick): ?>
                            <option value="buyer" selected="selected"><?php echo esc_html(__("The buyer's locker:", 'livraria-shipping-for-woocommerce') . ' ' . ($pick['supported'] ? Livraria_Lockers::display($pick) : $pick['name'] . ' ' . __('(cannot be used)', 'livraria-shipping-for-woocommerce'))); ?></option>
                        <?php endif; ?>
                        <option value="order"<?php echo $pick ? '' : ' selected="selected"'; ?>><?php esc_html_e("The order's shipping address", 'livraria-shipping-for-woocommerce'); ?></option>
                        <option value="locker"><?php esc_html_e('A locker', 'livraria-shipping-for-woocommerce'); ?></option>
                    </select>
                    <div id="livraria-destination-locker" class="lp-end-detail"></div>
                </div>
            </div>
            <div id="livraria-route-picker" class="lp-picker" hidden></div>
        </section>
        <?php
    }

    /**
     * AJAX (Route): the nearest supported locker to the sender (origin) or
     * to the order's address (destination), as a suggestion
     */
    public function ajax_closest_locker() {
        check_ajax_referer('courier_expedition_nonce', 'nonce');
        $this->require_capability('edit_shop_orders');
        $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $end = isset($_POST['end']) && $_POST['end'] === 'origin' ? 'origin' : 'destination';
        $suggestion = $this->order_handler->suggest_locker($order_id, $end);
        if ($suggestion === null) {
            $why = $this->api_client->last_error_message();
            wp_send_json_error('No locker could be suggested' . ($why !== '' ? ': ' . $why : ' near this address') . '. Choose one from the list.');
        }
        wp_send_json_success($suggestion);
    }

    /**
     * The Route the panel posts, cleaned: origin.mode pickup|locker,
     * destination.mode buyer|order|locker, each locker {id, courier, name}
     *
     * @param mixed $route
     * @return array
     */
    public static function sanitize_route($route) {
        $route = is_array($route) ? $route : array();
        $locker = function($value) {
            $value = is_array($value) ? $value : array();
            return array(
                'id' => sanitize_text_field((string) ($value['id'] ?? '')),
                'courier' => (string) Livraria_Lockers::parse_courier($value['courier'] ?? ''),
                'name' => sanitize_text_field((string) ($value['name'] ?? '')),
            );
        };
        $origin = isset($route['origin']) && is_array($route['origin']) ? $route['origin'] : array();
        $destination = isset($route['destination']) && is_array($route['destination']) ? $route['destination'] : array();
        $clean = array(
            'origin' => array('mode' => (($origin['mode'] ?? '') === 'locker') ? 'locker' : 'pickup'),
            'destination' => array('mode' => in_array($destination['mode'] ?? '', array('buyer', 'order', 'locker'), true) ? $destination['mode'] : 'buyer'),
        );
        if ($clean['origin']['mode'] === 'locker') {
            $clean['origin']['locker'] = $locker($origin['locker'] ?? array());
        }
        if ($clean['destination']['mode'] === 'locker') {
            $clean['destination']['locker'] = $locker($destination['locker'] ?? array());
        }
        return $clean;
    }

    /**
     * The sender profile select of the order panel, from the cached profile
     * list only: rendering the panel never calls the API. With nothing cached
     * it shows a "Reload profiles" control (ajax_reload_sender_profiles()).
     *
     * @param int $order_id
     */
    private function render_sender_profile_select($order_id) {
        $profiles = Livraria_API_Client::cached_sender_profiles();
        echo '<div id="livraria-sender-profiles">';
        if ($profiles === false) {
            echo '<p class="description">' . esc_html__('Sender profiles are not loaded yet.', 'livraria-shipping-for-woocommerce') . '</p>';
            echo '<button type="button" class="button" id="livraria-reload-profiles">' . esc_html__('Reload profiles', 'livraria-shipping-for-woocommerce') . '</button>';
            echo '<span id="livraria-reload-profiles-result" class="lp-inline-result" aria-live="polite"></span>';
        } else {
            $selected_id = $this->selected_sender_profile_id($order_id);
            echo '<select id="livraria-sender-profile-select" name="livraria_sender_profile_id">';
            foreach (self::sender_profile_options($profiles) as $option) {
                echo '<option value="' . esc_attr($option['id']) . '"' . ($option['id'] === (string) $selected_id ? ' selected="selected"' : '') . '>' . esc_html($option['label']) . '</option>';
            }
            echo '</select>';
            echo '<p class="description">' . esc_html__('Billing and pickup use this profile.', 'livraria-shipping-for-woocommerce') . '</p>';
        }
        echo '</div>';
    }

    /**
     * The profile chosen for this order before, else the default one
     *
     * @param int $order_id
     * @return string
     */
    private function selected_sender_profile_id($order_id) {
        $selected_id = Livraria_Order_Handler::get_order_meta($order_id, '_livraria_sender_profile_id');
        return (string) (empty($selected_id) ? get_option('livraria_default_sender_profile_id', '') : $selected_id);
    }

    /**
     * id and label of each sender profile in a GET /users/me/sender-profile
     * answer (one profile or a list)
     *
     * @param mixed $profiles
     * @return array[] id, label
     */
    public static function sender_profile_options($profiles) {
        if (!is_array($profiles) || $profiles === array()) {
            return array();
        }
        if (!array_key_exists(0, $profiles)) {
            $profiles = array($profiles); // a single profile
        }
        $options = array();
        foreach (array_values($profiles) as $index => $profile) {
            $profile = (array) $profile;
            $label = 'Profile ' . ($index + 1);
            if (!empty($profile['name'])) {
                $label = $profile['name'];
            } elseif (!empty($profile['companyName'])) {
                $label = $profile['companyName'];
            } elseif (!empty($profile['company'])) {
                if (is_array($profile['company']) || is_object($profile['company'])) {
                    $company = (array) $profile['company'];
                    if (isset($company['name'])) {
                        $label = $company['name'];
                    } elseif (isset($company['companyName'])) {
                        $label = $company['companyName'];
                    }
                } else {
                    $label = $profile['company'];
                }
            }
            $options[] = array(
                'id' => (string) (isset($profile['id']) ? $profile['id'] : ('profile-' . $index)),
                'label' => (string) $label,
            );
        }
        return $options;
    }

    /**
     * AJAX ("Reload profiles" in the order panel): fetch the sender profiles,
     * which also caches them for the next render
     */
    public function ajax_reload_sender_profiles() {
        check_ajax_referer('courier_expedition_nonce', 'nonce');
        $this->require_capability('edit_shop_orders');

        $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $profiles = $this->api_client->get_sender_profile();
        if ($profiles === false) {
            wp_send_json_error($this->order_handler->api_error_message('Could not load the sender profiles'));
        }
        wp_send_json_success(array(
            'options' => self::sender_profile_options($profiles),
            'selected' => $this->selected_sender_profile_id($order_id),
        ));
    }

    /**
     * AJAX (order panel): the merged locker list with addresses, for the
     * Route pickers. ?courier=sameday|dpd narrows it.
     */
    public function ajax_admin_lockers() {
        check_ajax_referer('courier_expedition_nonce', 'nonce');
        $this->require_capability('edit_shop_orders');
        $this->send_locker_proxy(false);
    }

    /**
     * AJAX (checkout, nonce livraria_checkout): the merged locker list the
     * buyer picks from, trimmed to id, name, county, city, street, courier.
     * The Livraria session stays on the server.
     */
    public function ajax_checkout_lockers() {
        check_ajax_referer('livraria_checkout', 'nonce');
        $this->send_locker_proxy(true);
    }

    /**
     * @param bool $storefront
     */
    private function send_locker_proxy($storefront) {
        $courier = isset($_REQUEST['courier']) ? sanitize_text_field(wp_unslash($_REQUEST['courier'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Callers check the nonce first
        // The checkout always narrows: no county = the county list, a county = its lockers.
        // The order panel gets every locker unless it names a county too.
        $county = isset($_REQUEST['county']) ? sanitize_text_field(wp_unslash($_REQUEST['county'])) : ($storefront ? '' : null); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Callers check the nonce first
        $answer = Livraria_Lockers::proxy_response($this->api_client, $courier, $storefront, $county);
        if ($answer['cache'] !== '' && !headers_sent()) {
            header('X-Livraria-Lockers: ' . $answer['cache']);
        }
        if ($answer['code'] === 200) {
            wp_send_json_success($answer['body']);
        }
        wp_send_json_error($answer['body'], $answer['code']);
    }

    /**
     * A plugin setting changed (Settings > Livraria, options.php): the cached
     * sender profiles may no longer fit
     *
     * @param string $option
     */
    public function forget_sender_profiles_on_settings_save($option) {
        if (strpos((string) $option, 'courier_') === 0 || $option === 'livraria_default_sender_profile_id'
            || in_array($option, Livraria_Lockers::setting_names(), true)) {
            Livraria_API_Client::forget_sender_profiles();
        }
    }

    /**
     * Stop an AJAX request (HTTP 403) unless the current user has $capability.
     * Every handler calls it right after its nonce check: the nonce proves the
     * request came from wp-admin, not that this user may do what it asks.
     * Settings, login, logout and the connection tests need manage_options;
     * order actions need edit_shop_orders.
     *
     * @param string $capability WordPress capability
     */
    private function require_capability($capability) {
        if (!current_user_can($capability)) {
            wp_send_json_error('Insufficient permissions', 403);
        }
    }

    /**
     * AJAX handler to clear auto-create flag
     */
    public function ajax_clear_auto_create_flag() {
        check_ajax_referer('courier_expedition_nonce', 'nonce');
        $this->require_capability('edit_shop_orders');
        
        if (!isset($_POST['order_id'])) {
            wp_send_json_error('Missing order_id');
            return;
        }
        
        $order_id = intval(wp_unslash($_POST['order_id']));
        if ($order_id) {
            delete_transient('livraria_auto_create_order_' . $order_id);
        }
        wp_send_json_success();
    }
    
    /**
     * AJAX handler for auto-creating expedition
     * Called from JavaScript when order status changes to "completed"
     */
    public function ajax_auto_create_expedition() {
        check_ajax_referer('courier_expedition_nonce', 'nonce');
        $this->require_capability('edit_shop_orders');
        
        if (!isset($_POST['order_id'])) {
            wp_send_json_error('Missing order_id');
            return;
        }
        
        $order_id = intval(wp_unslash($_POST['order_id']));
        
        if (!$order_id) {
            wp_send_json_error('Order ID is required');
        }
        
        // Run the auto-create process
        $this->run_auto_create_expedition($order_id);
    }
    
    /**
     * Run auto-create expedition process
     * This is the actual implementation that can be called from hook or AJAX
     */
    private function run_auto_create_expedition($order_id) {
        if (!get_option('courier_auto_create')) {
            return;
        }

        // Claims the order: a second run, or an order with a shipment, stops here
        $quotes_result = $this->order_handler->get_quotes_for_order($order_id, array(), true);
        if (!$quotes_result['success']) {
            $this->auto_create_stop($quotes_result['message']);
            return;
        }

        $quote_request_id = $quotes_result['quoteRequestId'];
        $selected_quote = Livraria_Order_Handler::select_best_quote($quotes_result['quotes']);
        $selected_quote_id = $selected_quote['id'];
        Livraria_Order_Handler::set_order_meta($order_id, array('_courier_selected_quote_id' => $selected_quote_id));

        if ($this->api_client->select_courier_quote($quote_request_id, $selected_quote_id) === false) {
            $message = $this->order_handler->api_error_message('Could not select the quote');
            $this->order_handler->auto_create_failed($order_id, $message, true);
            $this->auto_create_stop($message);
            return;
        }

        $default_sender_profile_id = get_option('livraria_default_sender_profile_id', '');
        if (empty($default_sender_profile_id)) {
            $message = 'No default sender profile is set under Settings > Livraria.';
            $this->order_handler->auto_create_failed($order_id, $message, true);
            $this->auto_create_stop($message);
            return;
        }

        if ($this->api_client->attach_billing_info_from_sender_profile($quote_request_id, $default_sender_profile_id) === false) {
            $message = $this->order_handler->api_error_message('Could not attach the billing information');
            $this->order_handler->auto_create_failed($order_id, $message, true);
            $this->auto_create_stop($message);
            return;
        }

        $expedition_response = $this->api_client->create_expedition(array('quoteRequestId' => $quote_request_id));
        if ($expedition_response === false || !isset($expedition_response['id'])) {
            $message = $expedition_response === false
                ? $this->order_handler->api_error_message('Could not create the shipment')
                : 'Could not create the shipment: the Livraria API answered without a shipment id.';
            // No response: the shipment may exist, so the claim holds until it expires
            $release = !($expedition_response === false && $this->api_client->last_error_status() === 0);
            $this->order_handler->auto_create_failed($order_id, $message, $release);
            $this->auto_create_stop($message);
            return;
        }

        $order = wc_get_order($order_id);
        if ($order) {
            $this->order_handler->save_expedition_data($order_id, $expedition_response, $selected_quote);
            $this->order_handler->add_expedition_order_note($order, $expedition_response);
        }
        Livraria_Order_Handler::delete_order_meta($order_id, array('_courier_quote_request_id', '_courier_selected_quote_id', '_courier_selected_quote_name', Livraria_Order_Handler::AUTO_CLAIM_META));

        if (defined('DOING_AJAX') && DOING_AJAX) {
            wp_send_json_success(array(
                'message' => 'Expedition created successfully',
                'expedition_id' => $expedition_response['id'],
                'awb_number' => $expedition_response['awbNumber'] ?? null
            ));
        }
    }

    /**
     * End an unattended run that did not create a shipment: the AJAX caller
     * gets the reason (the order already has it as a note)
     *
     * @param string $message
     */
    private function auto_create_stop($message) {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            wp_send_json_error($message);
        }
    }
    
    /**
     * Handle order status change
     * Sets a flag that JavaScript can check to trigger auto-create
     */
    public function handle_order_status_change($order_id, $old_status, $new_status) {
        if ($new_status === 'completed' && get_option('courier_auto_create')) {
            // Set a transient flag that JavaScript can check
            // This allows JS to run auto-create without page refresh interrupting
            set_transient('livraria_auto_create_order_' . $order_id, true, 60); // Expires in 60 seconds
            if (self::is_development_mode()) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
                error_log('Livraria Debug: Order ' . $order_id . ' status changed to completed, flag set for JS');
            }
        }
    }
    
    /**
     * Legacy method - kept for backward compatibility
     */
    public function auto_create_expedition($order_id) {
        // Don't run automatically on hook - let JavaScript handle it
        // This prevents page refresh from interrupting the flow
        return;
    }
    
    /**
     * AJAX handler to get quotes for an order (creates quote request)
     */
    public function ajax_get_quotes_for_order() {
        check_ajax_referer('courier_expedition_nonce', 'nonce');
        $this->require_capability('edit_shop_orders');
        
        if (!isset($_POST['order_id'])) {
            wp_send_json_error('Missing order_id');
            return;
        }
        
        $order_id = intval(wp_unslash($_POST['order_id']));
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Will be sanitized by sanitize_expedition_data() method below
        $custom_data = isset($_POST['expedition_data']) ? wp_unslash($_POST['expedition_data']) : array();
        
        // Sanitize custom data
        if (!empty($custom_data)) {
            $custom_data = $this->sanitize_expedition_data($custom_data);
        }
        
        // The unattended run (order.js after a status change) claims the order
        $auto = !empty($_POST['auto']);
        $result = $this->order_handler->get_quotes_for_order($order_id, $custom_data, $auto);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } elseif (!empty($result['fix'])) {
            // The panel offers "Add <fix>" next to the message
            wp_send_json_error(array('message' => $result['message'], 'fix' => $result['fix']));
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * AJAX: fill in the recipient email or phone on the WooCommerce order
     * ("Add email" / "Add phone" in the order panel). The panel then quotes
     * again.
     */
    public function ajax_update_order_contact() {
        check_ajax_referer('courier_expedition_nonce', 'nonce');
        $this->require_capability('edit_shop_orders');

        $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $field = isset($_POST['field']) ? sanitize_text_field(wp_unslash($_POST['field'])) : '';
        $value = isset($_POST['value']) ? sanitize_text_field(wp_unslash($_POST['value'])) : '';
        if (!$order_id || !in_array($field, array('email', 'phone', 'postcode', 'street', 'city'), true)) {
            wp_send_json_error('Missing required parameters');
        }

        $result = $this->order_handler->update_order_contact($order_id, $field, $value);
        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        }
        wp_send_json_error($result['message']);
    }
    
    /**
     * AJAX handler to select a quote
     */
    public function ajax_select_quote() {
        check_ajax_referer('courier_expedition_nonce', 'nonce');
        $this->require_capability('edit_shop_orders');

        if (!isset($_POST['order_id']) || !isset($_POST['quote_request_id']) || !isset($_POST['courier_quote_id'])) {
            wp_send_json_error('Missing required parameters');
            return;
        }

        $order_id = intval(wp_unslash($_POST['order_id']));
        $quote_request_id = sanitize_text_field(wp_unslash($_POST['quote_request_id']));
        $courier_quote_id = sanitize_text_field(wp_unslash($_POST['courier_quote_id']));
        $courier_name = isset($_POST['courier_name']) ? sanitize_text_field(wp_unslash($_POST['courier_name'])) : '';
        $sender_profile_id = isset($_POST['sender_profile_id']) ? sanitize_text_field(wp_unslash($_POST['sender_profile_id'])) : '';

        // Select the quote
        $result = $this->api_client->select_courier_quote($quote_request_id, $courier_quote_id);

        if ($result !== false) {
            // Store selected quote info in order meta
            Livraria_Order_Handler::set_order_meta($order_id, array(
                '_courier_quote_request_id' => $quote_request_id,
                '_courier_selected_quote_id' => $courier_quote_id,
            ));

            // Store courier name for later use
            if (!empty($courier_name)) {
                Livraria_Order_Handler::set_order_meta($order_id, array('_courier_selected_quote_name' => $courier_name));
            }
            
            // If sender profile ID is provided, attach billing info from sender profile
            if (!empty($sender_profile_id)) {
                // Save sender profile ID for this order
                Livraria_Order_Handler::set_order_meta($order_id, array('_livraria_sender_profile_id' => $sender_profile_id));
                
                // Attach billing info from sender profile
                $billing_result = $this->api_client->attach_billing_info_from_sender_profile($quote_request_id, $sender_profile_id);
                
                if ($billing_result === false) {
                    // Log error but don't fail the quote selection (only in development mode)
                    if (self::is_development_mode()) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode
                        error_log('Livraria Debug: Failed to attach billing info from sender profile, but quote selection succeeded');
                    }
                }
            }
            
            wp_send_json_success(array('message' => 'Quote selected successfully'));
        } else {
            $message = $this->order_handler->api_error_message('Could not select the quote');
            if (!empty($_POST['auto'])) {
                $this->order_handler->auto_create_failed($order_id, $message, true);
            }
            wp_send_json_error($message);
        }
    }
    
    /**
     * AJAX handler to generate label (create expedition after quote selection)
     */
    public function ajax_generate_label() {
        check_ajax_referer('courier_expedition_nonce', 'nonce');
        $this->require_capability('edit_shop_orders');
        
        if (!isset($_POST['order_id'])) {
            wp_send_json_error('Missing order_id');
            return;
        }
        
        $order_id = intval(wp_unslash($_POST['order_id']));
        $auto = !empty($_POST['auto']);

        // One shipment per order, whatever the screen says
        $existing_awb = Livraria_Order_Handler::get_order_meta($order_id, '_courier_expedition_id');
        if ($existing_awb) {
            wp_send_json_error('This order already has a shipment; no second one was created. Reload the page to see it.');
        }

        $quote_request_id = Livraria_Order_Handler::get_order_meta($order_id, '_courier_quote_request_id');
        $courier_quote_id = Livraria_Order_Handler::get_order_meta($order_id, '_courier_selected_quote_id');
        
        if (!$quote_request_id || !$courier_quote_id) {
            $this->label_failed($order_id, $auto, 'Quote request ID or selected quote ID not found', true);
        }
        
        // Attach billing info from default sender profile
        $default_sender_profile_id = get_option('livraria_default_sender_profile_id', '');
        
        if (empty($default_sender_profile_id)) {
            $this->label_failed($order_id, $auto, 'No default sender profile configured', true);
        }
        
        $billing_response = $this->api_client->attach_billing_info_from_sender_profile($quote_request_id, $default_sender_profile_id);
        if ($billing_response === false) {
            $this->label_failed($order_id, $auto, $this->order_handler->api_error_message('Could not attach the billing information'), true);
        }
        if (!isset($billing_response['id'])) {
            $this->label_failed($order_id, $auto, 'Could not attach the billing information: the Livraria API answered without a billing record.', true);
        }
        
        // Create expedition
        $expedition_data = array(
            'quoteRequestId' => $quote_request_id,
        );
        
        $expedition_response = $this->api_client->create_expedition($expedition_data);
        
        if ($expedition_response !== false && isset($expedition_response['id'])) {
            // Save expedition data
            $order = wc_get_order($order_id);
            if ($order) {
                // Retrieve stored courier name
                $courier_name = Livraria_Order_Handler::get_order_meta($order_id, '_courier_selected_quote_name');

                // Pass courier name along with quote ID to save_expedition_data
                $selected_quote = array('id' => $courier_quote_id);
                if (!empty($courier_name)) {
                    $selected_quote['courierName'] = $courier_name;
                }

                $this->order_handler->save_expedition_data($order_id, $expedition_response, $selected_quote);
                $this->order_handler->add_expedition_order_note($order, $expedition_response);
            }

            // Clean up temporary meta and the unattended run's claim
            Livraria_Order_Handler::delete_order_meta($order_id, array('_courier_quote_request_id', '_courier_selected_quote_id', '_courier_selected_quote_name', Livraria_Order_Handler::AUTO_CLAIM_META));
            
            wp_send_json_success(array(
                'message' => 'Expedition created successfully',
                'expedition_id' => $expedition_response['id'],
                'awb_number' => $expedition_response['awbNumber'] ?? null
            ));
        } elseif ($expedition_response === false) {
            $error = $this->api_client->get_last_error();
            $message = $this->order_handler->api_error_message('Could not create the shipment');
            // No response at all: the shipment may exist, so an unattended run keeps its claim
            $release = $this->api_client->last_error_status() !== 0;
            if (is_array($error) && $error['code'] === 'RECIPIENT_FIELDS_REQUIRED' && in_array('email', $error['missingRecipientFields'], true)) {
                $this->label_failed($order_id, $auto, $message, $release, 'email');
            }
            $this->label_failed($order_id, $auto, $message, $release);
        } else {
            $this->label_failed($order_id, $auto, 'Could not create the shipment: the Livraria API answered without a shipment id.', false);
        }
    }

    /**
     * Stop ajax_generate_label() with $message; for the unattended run, also
     * write it on the order and (when nothing can have been bought) release
     * the claim
     *
     * @param int $order_id
     * @param bool $auto
     * @param string $message
     * @param bool $release
     * @param string $fix 'email' when the panel should offer "Add email"
     */
    private function label_failed($order_id, $auto, $message, $release, $fix = '') {
        if ($auto) {
            $this->order_handler->auto_create_failed($order_id, $message, $release);
        }
        if ($fix !== '') {
            wp_send_json_error(array('message' => $message, 'fix' => $fix));
        }
        wp_send_json_error($message);
    }
    
    private function sanitize_expedition_data($data) {
        $sanitized = array();
        
        // Sanitize packages
        if (isset($data['packages']) && is_array($data['packages'])) {
            $sanitized['packages'] = array();
            foreach ($data['packages'] as $package) {
                if (!is_array($package)) {
                    continue;
                }
                $sanitized['packages'][] = array(
                    'weight' => isset($package['weight']) ? floatval($package['weight']) : 1,
                    'width' => isset($package['width']) ? floatval($package['width']) : 10,
                    'height' => isset($package['height']) ? floatval($package['height']) : 10,
                    'length' => isset($package['length']) ? floatval($package['length']) : 10
                );
            }
        }
        
        // Sanitize other fields
        $sanitized['content_description'] = sanitize_textarea_field($data['content_description'] ?? '');
        $sanitized['cod_amount'] = floatval($data['cod_amount'] ?? 0);
        $sanitized['insurance_amount'] = floatval($data['insurance_amount'] ?? 0);
        $sanitized['open_on_delivery'] = filter_var($data['open_on_delivery'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $sanitized['saturday_delivery'] = filter_var($data['saturday_delivery'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (isset($data['route'])) {
            $sanitized['route'] = self::sanitize_route($data['route']);
        }
        
        return $sanitized;
    }
    
    public function ajax_test_api_connection() {
        check_ajax_referer('livraria_admin_nonce', 'nonce');
        $this->require_capability('manage_options');
        
        if (isset($_POST['use_session']) && $_POST['use_session'] === '1') {
            // No password typed: test the stored session (refreshing it if needed)
            $result = (new Livraria_API_Client())->test_session();
            if ($result['success']) {
                wp_send_json_success($result);
            }
            wp_send_json_error($result['message']);
            return;
        }
        if (!isset($_POST['username']) || !isset($_POST['password'])) {
            wp_send_json_error('Missing required parameters');
            return;
        }
        // Get test credentials from POST data
        $username = sanitize_text_field(wp_unslash($_POST['username']));
        $password = sanitize_text_field(wp_unslash($_POST['password']));
        
        // Create temporary API client and test login (allowed hosts only
        // outside development mode)
        $test_client = new Livraria_API_Client($this->request_api_url());
        $result = $test_client->test_login($username, $password);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    public function ajax_test_connectivity() {
        check_ajax_referer('livraria_admin_nonce', 'nonce');
        $this->require_capability('manage_options');
        
        // Create temporary API client and test connectivity (allowed hosts
        // only outside development mode)
        $test_client = new Livraria_API_Client($this->request_api_url());
        $result = $test_client->test_connectivity();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * The API URL a login or connection test uses: the posted api_url if
     * any, else the stored URL if it is allowed, else PRODUCTION_API_URL.
     * Outside development mode a posted URL off the allow-list stops the
     * request with an error and nothing is saved.
     *
     * @return string
     */
    private function request_api_url() {
        $posted = isset($_POST['api_url']) ? esc_url_raw(wp_unslash($_POST['api_url'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers check the nonce first
        if ($posted !== '') {
            if (!Livraria_API_Client::is_allowed_api_url($posted)) {
                $host = Livraria_API_Client::url_host($posted);
                wp_send_json_error('Refused: ' . ($host !== '' ? $host : 'this address') . ' is not a Livraria API host. Nothing was saved.', 400);
            }
            return $posted;
        }
        $stored = (string) get_option('courier_api_base_url', '');
        if ($stored !== '' && Livraria_API_Client::is_allowed_api_url($stored)) {
            return $stored;
        }
        return Livraria_API_Client::PRODUCTION_API_URL;
    }

    /**
     * wp-admin notice, for users who can manage options, while the Livraria
     * session is expired (see Livraria_API_Client::mark_session_expired())
     */
    public function render_session_expired_notice() {
        if (!current_user_can('manage_options') || !Livraria_API_Client::is_session_expired()) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>Livraria:</strong> '
            . esc_html('Livraria session expired, log in again. Until then the plugin cannot get quotes or create shipments.')
            . ' <a href="' . esc_url(admin_url('options-general.php?page=courier-api-settings')) . '">Settings &gt; Livraria</a></p></div>';
    }

    /**
     * wp-admin notice, for users who can manage options, while the stored API
     * URL is not a Livraria API host (see Livraria_API_Client::check_stored_api_url())
     */
    public function render_blocked_api_url_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $blocked = Livraria_API_Client::blocked_api_url();
        if ($blocked === '') {
            return;
        }
        $host = Livraria_API_Client::url_host($blocked);
        echo '<div class="notice notice-error"><p><strong>Livraria:</strong> '
            . esc_html('The configured API address (' . ($host !== '' ? $host : 'unparseable URL') . ') is not a Livraria API host. The plugin has stopped all API calls and logged the shop out of the API. An administrator must log in again from the settings page.')
            . ' <a href="' . esc_url(admin_url('options-general.php?page=courier-api-settings')) . '">Settings &gt; Livraria</a></p></div>';
    }

    /**
     * AJAX handler for logout
     */
    public function ajax_logout() {
        check_ajax_referer('livraria_admin_nonce', 'nonce');
        $this->require_capability('manage_options');
        
        // Forget the API session (access and refresh tokens)
        Livraria_API_Client::clear_session();
        
        wp_send_json_success(array('message' => 'Logged out successfully'));
    }
    
    /**
     * AJAX handler for login
     */
    public function ajax_login() {
        check_ajax_referer('livraria_admin_nonce', 'nonce');
        $this->require_capability('manage_options');
        
        if (!isset($_POST['username']) || !isset($_POST['password'])) {
            wp_send_json_error('Username and password are required');
            return;
        }
        
        $username = sanitize_text_field(wp_unslash($_POST['username']));
        $password = sanitize_text_field(wp_unslash($_POST['password']));
        
        if (empty($username) || empty($password)) {
            wp_send_json_error('Username and password are required');
            return;
        }
        
        // Allowed hosts only outside development mode. Nothing is saved
        // before the login succeeds.
        $api_url = $this->request_api_url();
        $api_client = new Livraria_API_Client($api_url);
        $result = $api_client->login($username, $password);
        
        if ($result['success']) {
            update_option('courier_api_base_url', $api_url);
            delete_option('livraria_api_url_blocked');
            
            // login() stored the username and the tokens; the password is
            // used for this request only and never stored.
            $message = 'Login successful';
            if (!$result['refresh_stored']) {
                $message = Livraria_API_Client::can_encrypt()
                    ? 'Login successful, but the API sent no refresh token: you will have to log in again within an hour.'
                    : 'Login successful, but this server\'s PHP has no OpenSSL, so the session cannot be stored encrypted: you will have to log in again within an hour.';
            }
            
            wp_send_json_success(array('message' => $message));
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * AJAX handler for updating a single option
     */
    public function ajax_update_option() {
        check_ajax_referer('livraria_admin_nonce', 'nonce');
        $this->require_capability('manage_options');
        
        if (!isset($_POST['option_name']) || !isset($_POST['option_value'])) {
            wp_send_json_error('Missing required parameters');
            return;
        }
        
        $option_name = sanitize_text_field(wp_unslash($_POST['option_name']));
        $option_value = sanitize_text_field(wp_unslash($_POST['option_value']));
        
        // Validate that this is an allowed option
        $allowed_options = array_merge(array('courier_auto_create', 'livraria_default_sender_profile_id'), Livraria_Lockers::setting_names());
        if (!in_array($option_name, $allowed_options, true)) {
            wp_send_json_error('Option not allowed');
            return;
        }
        if (in_array($option_name, Livraria_Lockers::setting_names(), true)) {
            $option_value = $this->sanitize_flag($option_value);
        }
        
        // Update the option (same as what happens when clicking "Save changes")
        $result = update_option($option_name, $option_value);
        Livraria_API_Client::forget_sender_profiles();
        
        // update_option() answers false when the value did not change: that is saved too
        if ($result !== false || (string) get_option($option_name, '') === (string) $option_value) {
            wp_send_json_success(array('message' => 'Option updated successfully'));
        } else {
            wp_send_json_error('Failed to update option');
        }
    }

    /**
     * AJAX handler for deleting expedition association (does NOT cancel AWB with courier)
     */
    public function ajax_delete_expedition() {
        check_ajax_referer('courier_expedition_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if (!$order_id) {
            wp_send_json_error('Invalid order ID');
        }

        $meta_keys = array(
            '_courier_expedition_id',
            '_courier_awb_number',
            '_courier_name',
            '_courier_quote_id',
            '_courier_price',
            '_courier_status',
            '_courier_expedition_created',
            '_courier_quote_request_id',
            '_courier_selected_quote_id',
            '_courier_selected_quote_name',
        );

        Livraria_Order_Handler::delete_order_meta($order_id, $meta_keys);

        wp_send_json_success();
    }

    /**
     * AJAX handler for downloading AWB PDF
     */
    public function ajax_download_awb_pdf() {
        // Verify nonce for security
        check_ajax_referer('courier_expedition_nonce', 'nonce');

        // Check user permissions
        if (!current_user_can('edit_shop_orders')) {
            wp_die('Insufficient permissions', 'Forbidden', array('response' => 403));
        }

        if (!isset($_GET['order_id']) || !isset($_GET['awb_number'])) {
            wp_die('Missing required parameters', 'Bad Request', array('response' => 400));
        }
        
        $order_id = intval(wp_unslash($_GET['order_id']));
        $awb_number = sanitize_text_field(wp_unslash($_GET['awb_number']));

        // Validate inputs
        if (!$order_id || !$awb_number) {
            wp_die('Missing required parameters', 'Bad Request', array('response' => 400));
        }

        // Verify AWB belongs to this order (security check)
        $stored_awb = Livraria_Order_Handler::get_order_meta($order_id, '_courier_awb_number');
        if ($stored_awb !== $awb_number) {
            wp_die('AWB number mismatch', 'Forbidden', array('response' => 403));
        }

        // Get PDF options from query params
        $options = array(
            'format' => isset($_GET['format']) ? sanitize_text_field(wp_unslash($_GET['format'])) : 'pdf',
            'paperSize' => isset($_GET['paperSize']) ? sanitize_text_field(wp_unslash($_GET['paperSize'])) : 'A4',
            'language' => isset($_GET['language']) ? sanitize_text_field(wp_unslash($_GET['language'])) : 'EN'
        );

        // Download PDF from API
        $pdf_data = $this->api_client->download_awb_pdf($awb_number, $options);

        if ($pdf_data === false) {
            wp_die(esc_html($this->order_handler->api_error_message('Could not download the AWB')), 'Service Unavailable', array('response' => 503));
        }

        // Set headers for PDF download
        header('Content-Type: ' . $pdf_data['content_type']);
        header('Content-Disposition: attachment; filename="AWB-' . $awb_number . '-' . $options['paperSize'] . '.pdf"');
        header('Content-Length: ' . $pdf_data['content_length']);
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        // Output PDF binary data
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF data cannot be escaped
        echo $pdf_data['body'];

        // Important: Stop execution to prevent WordPress from adding extra output
        exit;
    }

    public function save_expedition_meta($post_id) {
        // Check if this is an order post type
        if (get_post_type($post_id) !== 'shop_order') {
            return;
        }
        
        // Verify nonce if present (form submission, not AJAX)
        if (isset($_POST['courier_expedition_nonce_field'])) {
            if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['courier_expedition_nonce_field'])), 'courier_expedition_nonce')) {
                return;
            }
        }
        
        // Check user permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // This method can be used for custom save actions if needed
        // Currently just prevents the fatal error
    }
    
    public function enqueue_admin_styles() {
        // Enqueue admin assets on all admin pages
        wp_enqueue_style('livraria-admin-css', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), LIVRARIA_WC_PLUGIN_VERSION);
        wp_enqueue_script('livraria-admin-js', plugin_dir_url(__FILE__) . 'assets/admin.js', array('jquery'), LIVRARIA_WC_PLUGIN_VERSION, true);
        wp_localize_script('livraria-admin-js', 'livrariaAdmin', array(
            'nonce'                  => wp_create_nonce('livraria_admin_nonce'),
            'ajaxurl'                => admin_url('admin-ajax.php'),
            'isLoggedIn'             => !empty(get_option('livraria_api_token', '')),
            'defaultSenderProfileId' => get_option('livraria_default_sender_profile_id', ''),
        ));

        // Only enqueue order script on order edit pages
        $screen = get_current_screen();
        if (!$screen || ($screen->post_type !== 'shop_order' && $screen->id !== 'woocommerce_page_wc-orders')) {
            return;
        }

        // Enqueue order meta box script with dynamic data
        $order_id = self::current_order_id();
        $order = $order_id && function_exists('wc_get_order') ? wc_get_order($order_id) : false;
        $is_draft = $order ? self::is_draft_order($order) : true;
        Livraria_Checkout::register_picker_assets();
        wp_enqueue_style(Livraria_Checkout::SCRIPT);
        wp_enqueue_script('livraria-order-js', plugin_dir_url(__FILE__) . 'assets/order.js', array('jquery', Livraria_Checkout::SCRIPT), LIVRARIA_WC_PLUGIN_VERSION, true);
        wp_localize_script('livraria-order-js', 'livrariaOrder', array_merge(array(
            'autoCreateEnabled' => (bool) get_option('courier_auto_create'),
            'expeditionExists'  => (bool) Livraria_Order_Handler::get_order_meta($order_id, '_courier_expedition_id'),
            'orderId'           => $order_id,
            'shouldAutoCreate'  => (bool) get_transient('livraria_auto_create_order_' . $order_id),
        ), $is_draft ? array() : self::route_script_data($order_id)));

        // The box's own stylesheet (scoped to .livraria-panel)
        wp_enqueue_style('livraria-order-panel', plugin_dir_url(__FILE__) . 'assets/order-panel.css', array(), LIVRARIA_WC_PLUGIN_VERSION);
    }
}

// Initialize the plugin
new LivrariaPlugin();