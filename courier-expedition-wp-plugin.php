<?php
/**
 * Plugin Name: Livraria
 * Description: WordPress plugin for generating shipping expeditions via courier API
 * Version: 1.0.0
 * Author: Your Company
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include required classes
require_once plugin_dir_path(__FILE__) . 'includes/class-api-client.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-order-handler.php';

class LivrariaPlugin {
    
    private $api_base_url;
    private $api_token;
    private $api_client;
    private $order_handler;
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
        
        // Add settings link to plugin page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_plugin_action_links'));
        
        // WooCommerce integration
        add_action('woocommerce_order_status_completed', array($this, 'auto_create_expedition'));
        add_action('add_meta_boxes', array($this, 'add_expedition_meta_box'));
        add_action('add_meta_boxes_shop_order', array($this, 'add_expedition_meta_box'));
        add_action('add_meta_boxes_woocommerce_page_wc-orders', array($this, 'add_expedition_meta_box'));
        add_action('save_post', array($this, 'save_expedition_meta'));
        
        // AJAX handlers
        add_action('wp_ajax_create_expedition', array($this, 'ajax_create_expedition'));
        add_action('wp_ajax_get_courier_quotes', array($this, 'ajax_get_courier_quotes'));
        add_action('wp_ajax_test_courier_api_connection', array($this, 'ajax_test_api_connection'));
        add_action('wp_ajax_test_connectivity', array($this, 'ajax_test_connectivity'));
        
        $this->api_base_url = get_option('courier_api_base_url', '');
        
        // Initialize API client and order handler  
        $this->api_client = new Livraria_API_Client($this->api_base_url);
        $this->order_handler = new Livraria_Order_Handler($this->api_client);
    }
    
    public function init() {
        wp_enqueue_script('jquery');
        
        // Enqueue admin assets on admin pages
        if (is_admin()) {
            wp_enqueue_style('livraria-admin-css', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), '1.0.0');
            wp_enqueue_script('livraria-admin-js', plugin_dir_url(__FILE__) . 'assets/admin.js', array('jquery'), '1.0.0', true);
            
            // Debug: Log current screen info for troubleshooting
            add_action('current_screen', array($this, 'debug_current_screen'));
        }
    }
    
    public function debug_current_screen($screen) {
        if ($screen && (strpos($screen->id, 'order') !== false || strpos($screen->id, 'shop') !== false)) {
            error_log('Livraria Debug: Current screen ID: ' . $screen->id . ', Base: ' . $screen->base . ', Post Type: ' . ($screen->post_type ?? 'none'));
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
            array($this, 'admin_page')
        );
    }
    
    public function admin_init() {
        register_setting('courier_api_settings', 'courier_api_base_url');
        register_setting('courier_api_settings', 'courier_api_username');
        register_setting('courier_api_settings', 'courier_api_password');
        register_setting('courier_api_settings', 'courier_auto_create');
        register_setting('courier_api_settings', 'courier_default_sender_name');
        register_setting('courier_api_settings', 'courier_default_sender_email');
        register_setting('courier_api_settings', 'courier_default_sender_phone');
        
        // Individual address fields
        register_setting('courier_api_settings', 'courier_sender_country');
        register_setting('courier_api_settings', 'courier_sender_county');
        register_setting('courier_api_settings', 'courier_sender_city');
        register_setting('courier_api_settings', 'courier_sender_postcode');
        register_setting('courier_api_settings', 'courier_sender_street');
        register_setting('courier_api_settings', 'courier_sender_street_number');
        register_setting('courier_api_settings', 'courier_sender_block');
        register_setting('courier_api_settings', 'courier_sender_staircase');
        register_setting('courier_api_settings', 'courier_sender_floor');
        register_setting('courier_api_settings', 'courier_sender_apartment');
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Livraria Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('courier_api_settings');
                do_settings_sections('courier_api_settings');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">API Base URL</th>
                        <td><input type="url" name="courier_api_base_url" value="<?php echo esc_attr(get_option('courier_api_base_url')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Username</th>
                        <td><input type="text" name="courier_api_username" value="<?php echo esc_attr(get_option('courier_api_username')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Password</th>
                        <td><input type="password" name="courier_api_password" value="<?php echo esc_attr(get_option('courier_api_password')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Auto-create expeditions</th>
                        <td><input type="checkbox" name="courier_auto_create" value="1" <?php checked(1, get_option('courier_auto_create'), true); ?> /></td>
                    </tr>
                    <tr>
                        <th scope="row">Default Sender Name</th>
                        <td><input type="text" name="courier_default_sender_name" value="<?php echo esc_attr(get_option('courier_default_sender_name')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Default Sender Email</th>
                        <td><input type="email" name="courier_default_sender_email" value="<?php echo esc_attr(get_option('courier_default_sender_email')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Default Sender Phone</th>
                        <td><input type="text" name="courier_default_sender_phone" value="<?php echo esc_attr(get_option('courier_default_sender_phone')); ?>" class="regular-text" /></td>
                    </tr>
                </table>
                
                <h3>Sender Address</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">Country</th>
                        <td><input type="text" name="courier_sender_country" value="<?php echo esc_attr(get_option('courier_sender_country', 'Romania')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">County/State</th>
                        <td><input type="text" name="courier_sender_county" value="<?php echo esc_attr(get_option('courier_sender_county', 'Bucharest')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">City</th>
                        <td><input type="text" name="courier_sender_city" value="<?php echo esc_attr(get_option('courier_sender_city', 'Bucharest')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Postcode</th>
                        <td><input type="text" name="courier_sender_postcode" value="<?php echo esc_attr(get_option('courier_sender_postcode', '010101')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Street</th>
                        <td><input type="text" name="courier_sender_street" value="<?php echo esc_attr(get_option('courier_sender_street')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Street Number</th>
                        <td><input type="text" name="courier_sender_street_number" value="<?php echo esc_attr(get_option('courier_sender_street_number')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Block <small>(optional)</small></th>
                        <td><input type="text" name="courier_sender_block" value="<?php echo esc_attr(get_option('courier_sender_block')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Staircase <small>(optional)</small></th>
                        <td><input type="text" name="courier_sender_staircase" value="<?php echo esc_attr(get_option('courier_sender_staircase')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Floor <small>(optional)</small></th>
                        <td><input type="text" name="courier_sender_floor" value="<?php echo esc_attr(get_option('courier_sender_floor')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Apartment <small>(optional)</small></th>
                        <td><input type="text" name="courier_sender_apartment" value="<?php echo esc_attr(get_option('courier_sender_apartment')); ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <div id="api-test-section">
                <h3>Test API Connection</h3>
                <p class="description">Test network connectivity and authentication separately to troubleshoot issues.</p>
                
                <div style="margin-bottom: 15px;">
                    <button type="button" id="test-connectivity" class="button">1. Test Network Connectivity</button>
                    <span class="description" style="margin-left: 10px;">Check if the server can reach your API</span>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <button type="button" id="test-api-connection" class="button button-primary">2. Test Authentication & API Access</button>
                    <span class="description" style="margin-left: 10px;">Test login and API permissions</span>
                </div>
                
                <div id="api-test-result"></div>
                
                <div id="test-steps" style="display:none; margin-top: 15px;">
                    <h4>Test Steps:</h4>
                    <ol>
                        <li id="step-login">Login with credentials... <span class="status"></span></li>
                        <li id="step-api">Test API access with token... <span class="status"></span></li>
                    </ol>
                </div>
            </div>
        </div>
        
        <script>
        var livrariaAdmin = {
            nonce: '<?php echo wp_create_nonce('livraria_admin_nonce'); ?>',
            ajaxurl: '<?php echo admin_url('admin-ajax.php'); ?>'
        };
        </script>
        <?php
    }
    
    public function add_expedition_meta_box() {
        // Debug: Log when this method is called
        error_log('Livraria Debug: add_expedition_meta_box called');
        
        // For traditional WooCommerce order pages (post-based)
        add_meta_box(
            'livraria-expedition',
            'Livraria Expedition',
            array($this, 'expedition_meta_box_callback'),
            'shop_order',
            'side',
            'high'
        );
        error_log('Livraria Debug: Added metabox for shop_order');
        
        // For WooCommerce High-Performance Order Storage (HPOS)
        if (class_exists('Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore')) {
            add_meta_box(
                'livraria-expedition',
                'Livraria Expedition',
                array($this, 'expedition_meta_box_callback'),
                wc_get_page_screen_id('shop-order'),
                'side',
                'high'
            );
        }
        
        // Alternative screen IDs that WooCommerce might use
        $screen = get_current_screen();
        if ($screen && in_array($screen->id, array('woocommerce_page_wc-orders', 'shop_order'))) {
            add_meta_box(
                'livraria-expedition',
                'Livraria Expedition',
                array($this, 'expedition_meta_box_callback'),
                $screen->id,
                'side',
                'high'
            );
        }
    }
    
    public function expedition_meta_box_callback($post) {
        // Debug log to confirm metabox is being called
        error_log('Livraria Debug: Metabox callback called for post ID: ' . ($post->ID ?? 'unknown'));
        
        wp_nonce_field('courier_expedition_nonce', 'courier_expedition_nonce_field');
        
        $expedition_id = get_post_meta($post->ID, '_courier_expedition_id', true);
        $awb_number = get_post_meta($post->ID, '_courier_awb_number', true);
        $tracking_url = get_post_meta($post->ID, '_courier_tracking_url', true);
        
        ?>
        <div id="courier-expedition-meta">
            <?php if ($expedition_id): ?>
                <p><strong>Expedition ID:</strong> <?php echo esc_html($expedition_id); ?></p>
                <?php if ($awb_number): ?>
                    <p><strong>AWB Number:</strong> <?php echo esc_html($awb_number); ?></p>
                    <?php if ($tracking_url): ?>
                        <p><a href="<?php echo esc_url($tracking_url); ?>" target="_blank">Track Package</a></p>
                    <?php endif; ?>
                <?php endif; ?>
            <?php else: ?>
                
                <!-- Package Configuration -->
                <div class="expedition-section">
                    <h4>Package Information</h4>
                    <div id="packages-container">
                        <div class="package-item" data-package="1">
                            <h5>Package 1</h5>
                            <div class="package-dimensions">
                                <label>Weight (kg): <input type="number" name="package_weight[]" step="0.1" min="0.1" value="1" style="width:60px;"></label>
                                <label>Width (cm): <input type="number" name="package_width[]" step="0.1" min="1" value="20" style="width:60px;"></label>
                                <label>Height (cm): <input type="number" name="package_height[]" step="0.1" min="1" value="10" style="width:60px;"></label>
                                <label>Length (cm): <input type="number" name="package_length[]" step="0.1" min="1" value="30" style="width:60px;"></label>
                                <button type="button" class="button remove-package" style="display:none;">Remove</button>
                            </div>
                        </div>
                    </div>
                    <button type="button" id="add-package-btn" class="button">Add Another Package</button>
                </div>

                <!-- Content Description -->
                <div class="expedition-section">
                    <h4>Content Description</h4>
                    <textarea name="content_description" placeholder="Describe the package contents..." style="width:100%; height:60px;"></textarea>
                </div>

                <!-- Service Options -->
                <div class="expedition-section">
                    <h4>Service Options</h4>
                    <p>
                        <label>COD Amount: <input type="number" name="cod_amount" step="0.01" min="0" value="<?php echo esc_attr($post->post_type === 'shop_order' ? wc_get_order($post->ID)->get_total() : '0'); ?>" style="width:80px;"> RON</label>
                    </p>
                    <p>
                        <label>Insurance Amount: <input type="number" name="insurance_amount" step="0.01" min="0" value="0" style="width:80px;"> RON</label>
                    </p>
                    <p>
                        <label><input type="checkbox" name="open_on_delivery" value="1"> Open on Delivery</label>
                    </p>
                    <p>
                        <label><input type="checkbox" name="saturday_delivery" value="1"> Saturday Delivery</label>
                    </p>
                </div>

                <div class="expedition-section">
                    <button type="button" id="create-expedition-btn" class="button button-primary">Create Expedition</button>
                    <div id="expedition-loading" style="display:none;">Creating expedition...</div>
                    <div id="expedition-result"></div>
                </div>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var packageCount = 1;
            
            // Add package functionality
            $('#add-package-btn').click(function() {
                packageCount++;
                var packageHtml = '<div class="package-item" data-package="' + packageCount + '">' +
                    '<h5>Package ' + packageCount + '</h5>' +
                    '<div class="package-dimensions">' +
                        '<label>Weight (kg): <input type="number" name="package_weight[]" step="0.1" min="0.1" value="1" style="width:60px;"></label>' +
                        '<label>Width (cm): <input type="number" name="package_width[]" step="0.1" min="1" value="20" style="width:60px;"></label>' +
                        '<label>Height (cm): <input type="number" name="package_height[]" step="0.1" min="1" value="10" style="width:60px;"></label>' +
                        '<label>Length (cm): <input type="number" name="package_length[]" step="0.1" min="1" value="30" style="width:60px;"></label>' +
                        '<button type="button" class="button remove-package">Remove</button>' +
                    '</div>' +
                '</div>';
                
                $('#packages-container').append(packageHtml);
                updateRemoveButtons();
            });
            
            // Remove package functionality
            $(document).on('click', '.remove-package', function() {
                $(this).closest('.package-item').remove();
                packageCount--;
                updatePackageNumbers();
                updateRemoveButtons();
            });
            
            function updateRemoveButtons() {
                $('.remove-package').toggle($('.package-item').length > 1);
            }
            
            function updatePackageNumbers() {
                $('.package-item').each(function(index) {
                    $(this).find('h5').text('Package ' + (index + 1));
                });
            }
            
            $('#create-expedition-btn').click(function() {
                var btn = $(this);
                btn.prop('disabled', true);
                $('#expedition-loading').show();
                
                // Collect package data
                var packages = [];
                $('.package-item').each(function() {
                    var $item = $(this);
                    packages.push({
                        weight: parseFloat($item.find('input[name="package_weight[]"]').val()) || 1,
                        width: parseFloat($item.find('input[name="package_width[]"]').val()) || 20,
                        height: parseFloat($item.find('input[name="package_height[]"]').val()) || 10,
                        length: parseFloat($item.find('input[name="package_length[]"]').val()) || 30
                    });
                });
                
                // Collect service options
                var expeditionData = {
                    packages: packages,
                    content_description: $('textarea[name="content_description"]').val() || '',
                    cod_amount: parseFloat($('input[name="cod_amount"]').val()) || 0,
                    insurance_amount: parseFloat($('input[name="insurance_amount"]').val()) || 0,
                    open_on_delivery: $('input[name="open_on_delivery"]').is(':checked'),
                    saturday_delivery: $('input[name="saturday_delivery"]').is(':checked')
                };
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'create_expedition',
                        order_id: <?php echo $post->ID; ?>,
                        nonce: $('#courier_expedition_nonce_field').val(),
                        expedition_data: expeditionData
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#expedition-result').html('<p style="color:green;">Expedition created successfully!</p>');
                            location.reload();
                        } else {
                            $('#expedition-result').html('<p style="color:red;">Error: ' + response.data + '</p>');
                            btn.prop('disabled', false);
                        }
                        $('#expedition-loading').hide();
                    },
                    error: function() {
                        $('#expedition-result').html('<p style="color:red;">AJAX error occurred</p>');
                        $('#expedition-loading').hide();
                        btn.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function auto_create_expedition($order_id) {
        if (!get_option('courier_auto_create')) {
            return;
        }
        
        $this->order_handler->create_expedition_for_order($order_id);
    }
    
    public function ajax_create_expedition() {
        if (!wp_verify_nonce($_POST['nonce'], 'courier_expedition_nonce')) {
            wp_die('Security check failed');
        }
        
        $order_id = intval($_POST['order_id']);
        $custom_data = isset($_POST['expedition_data']) ? $_POST['expedition_data'] : array();
        
        // Sanitize custom data
        if (!empty($custom_data)) {
            $custom_data = $this->sanitize_expedition_data($custom_data);
        }
        
        $result = $this->order_handler->create_expedition_for_order($order_id, $custom_data);
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    private function sanitize_expedition_data($data) {
        $sanitized = array();
        
        // Sanitize packages
        if (isset($data['packages']) && is_array($data['packages'])) {
            $sanitized['packages'] = array();
            foreach ($data['packages'] as $package) {
                $sanitized['packages'][] = array(
                    'weight' => floatval($package['weight']),
                    'width' => floatval($package['width']),
                    'height' => floatval($package['height']),
                    'length' => floatval($package['length'])
                );
            }
        }
        
        // Sanitize other fields
        $sanitized['content_description'] = sanitize_textarea_field($data['content_description'] ?? '');
        $sanitized['cod_amount'] = floatval($data['cod_amount'] ?? 0);
        $sanitized['insurance_amount'] = floatval($data['insurance_amount'] ?? 0);
        $sanitized['open_on_delivery'] = (bool)($data['open_on_delivery'] ?? false);
        $sanitized['saturday_delivery'] = (bool)($data['saturday_delivery'] ?? false);
        
        return $sanitized;
    }
    
    public function ajax_test_api_connection() {
        if (!wp_verify_nonce($_POST['nonce'], 'livraria_admin_nonce')) {
            wp_die('Security check failed');
        }
        
        // Get test credentials from POST data
        $api_url = sanitize_url($_POST['api_url']);
        $username = sanitize_email($_POST['username']);
        $password = sanitize_text_field($_POST['password']);
        
        // Create temporary API client and test login
        $test_client = new Livraria_API_Client($api_url);
        $result = $test_client->test_login($username, $password);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    public function ajax_test_connectivity() {
        if (!wp_verify_nonce($_POST['nonce'], 'livraria_admin_nonce')) {
            wp_die('Security check failed');
        }
        
        // Get API URL from POST data
        $api_url = sanitize_url($_POST['api_url']);
        
        // Create temporary API client and test connectivity
        $test_client = new Livraria_API_Client($api_url);
        $result = $test_client->test_connectivity();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    public function save_expedition_meta($post_id) {
        // Check if this is an order post type
        if (get_post_type($post_id) !== 'shop_order') {
            return;
        }
        
        // Verify nonce if present
        if (isset($_POST['courier_expedition_nonce_field'])) {
            if (!wp_verify_nonce($_POST['courier_expedition_nonce_field'], 'courier_expedition_nonce')) {
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
        // Only enqueue on order edit pages
        $screen = get_current_screen();
        if (!$screen || ($screen->post_type !== 'shop_order' && $screen->id !== 'woocommerce_page_wc-orders')) {
            return;
        }
        
        // Inline CSS for expedition form styling
        $css = "
        .expedition-form-section {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .expedition-form-section h4 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #333;
            font-size: 14px;
            font-weight: 600;
        }
        
        .packages-container {
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #fff;
        }
        
        .package-item {
            border-bottom: 1px solid #eee;
            padding: 15px;
            background: #fff;
            position: relative;
        }
        
        .package-item:last-child {
            border-bottom: none;
        }
        
        .package-item h5 {
            margin: 0 0 10px 0;
            font-size: 13px;
            font-weight: 600;
            color: #555;
        }
        
        .package-dimensions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .package-dimensions label {
            font-size: 12px;
            color: #666;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .package-dimensions input {
            width: 60px;
            padding: 2px 6px;
            font-size: 12px;
            border: 1px solid #ddd;
            border-radius: 3px;
        }
        
        .remove-package {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 3px;
            padding: 3px 8px;
            font-size: 11px;
            cursor: pointer;
            line-height: 1;
        }
        
        .remove-package:hover {
            background: #c82333;
        }
        
        .add-package-btn {
            background: #0073aa;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            margin-top: 10px;
        }
        
        .add-package-btn:hover {
            background: #005a87;
        }
        
        .service-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .service-options label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: #666;
        }
        
        .service-options input[type='number'] {
            width: 80px;
            padding: 4px 8px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 12px;
        }
        
        .service-options input[type='checkbox'] {
            margin: 0;
        }
        
        .content-description textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 12px;
            resize: vertical;
            min-height: 60px;
        }
        
        .sender-address-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 10px;
        }
        
        .sender-address-grid label {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 12px;
            color: #666;
        }
        
        .sender-address-grid input {
            padding: 6px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 12px;
        }
        
        .sender-address-grid .full-width {
            grid-column: span 2;
        }
        
        #expedition-loading {
            display: none;
            padding: 10px;
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 4px;
            margin: 10px 0;
            font-size: 12px;
            color: #0073aa;
        }
        
        .notice {
            padding: 10px 15px;
            border-left: 4px solid #ddd;
            background: #f9f9f9;
            margin: 10px 0;
            font-size: 13px;
        }
        
        .notice-success {
            border-left-color: #46b450;
            background: #ecf7ed;
        }
        
        .notice-error {
            border-left-color: #dc3545;
            background: #fdf2f2;
        }
        
        .notice-warning {
            border-left-color: #f0ad4e;
            background: #fcf8e3;
        }
        ";
        
        wp_add_inline_style('wp-admin', $css);
    }
}

// Initialize the plugin
new LivrariaPlugin();