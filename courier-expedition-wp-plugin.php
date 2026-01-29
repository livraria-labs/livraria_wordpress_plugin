<?php
/**
 * Plugin Name: Livraria
 * Description: WordPress plugin for generating shipping expeditions via courier API
 * Version: 1.0.0
 * Author: Livraria S.R.L.
 * 
 * DEVELOPMENT MODE: This plugin is mounted from source for real-time development
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include required classes
require_once plugin_dir_path(__FILE__) . 'includes/class-api-client.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-order-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-admin-page.php';

class LivrariaPlugin {
    
    private $api_base_url;
    private $api_token;
    private $api_client;
    private $order_handler;
    private $admin_page;
    
    /**
     * Check if plugin is running in development mode
     * 
     * @return bool True if in development mode, false if in production
     */
    public static function is_development_mode() {
        // Check for explicit development mode constant
        if (defined('LIVRARIA_DEV_MODE')) {
            return LIVRARIA_DEV_MODE === true;
        }
        
        // Check if WP_DEBUG is enabled (common in development)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return true;
        }
        
        // Check if plugin is running from source (has dev-scripts directory)
        $plugin_dir = plugin_dir_path(__FILE__);
        if (file_exists($plugin_dir . 'dev-scripts')) {
            return true;
        }
        
        // Check if we're in a development environment (common patterns)
        $home_url = get_home_url();
        if (strpos($home_url, 'localhost') !== false || 
            strpos($home_url, '127.0.0.1') !== false ||
            strpos($home_url, '.local') !== false ||
            strpos($home_url, '.dev') !== false ||
            strpos($home_url, 'staging') !== false) {
            return true;
        }
        
        return false;
    }
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
        
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
        add_action('wp_ajax_create_expedition', array($this, 'ajax_create_expedition'));
        add_action('wp_ajax_get_courier_quotes', array($this, 'ajax_get_courier_quotes'));
        add_action('wp_ajax_get_quotes_for_order', array($this, 'ajax_get_quotes_for_order'));
        add_action('wp_ajax_select_quote', array($this, 'ajax_select_quote'));
        add_action('wp_ajax_generate_label', array($this, 'ajax_generate_label'));
        add_action('wp_ajax_auto_create_expedition_ajax', array($this, 'ajax_auto_create_expedition'));
        add_action('wp_ajax_test_courier_api_connection', array($this, 'ajax_test_api_connection'));
        add_action('wp_ajax_test_connectivity', array($this, 'ajax_test_connectivity'));
        add_action('wp_ajax_livraria_logout', array($this, 'ajax_logout'));
        add_action('wp_ajax_livraria_login', array($this, 'ajax_login'));
        add_action('wp_ajax_update_option', array($this, 'ajax_update_option'));
        
        $this->api_base_url = get_option('courier_api_base_url', '');
        
        // Initialize API client and order handler  
        $this->api_client = new Livraria_API_Client($this->api_base_url);
        $this->order_handler = new Livraria_Order_Handler($this->api_client);
        $this->admin_page = new Livraria_Admin_Page($this->api_client);
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
            array($this->admin_page, 'render')
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
        
        // In production, automatically set API Base URL to production endpoint
        if (!self::is_development_mode()) {
            $current_url = get_option('courier_api_base_url', '');
            if ($current_url !== 'https://api.livraria.ro/') {
                update_option('courier_api_base_url', 'https://api.livraria.ro/');
            }
        }
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
            return '<img src="' . esc_url($logo_url) . '" alt="Livraria" style="width: 20px; height: 20px; vertical-align: middle;" /> Deliver with Livraria';
        }
        
        // Fallback: return text only if logo doesn't exist
        return 'Deliver with Livraria';
    }
    
    public function add_expedition_meta_box() {
        // Debug: Log when this method is called
        error_log('Livraria Debug: add_expedition_meta_box called');
        
        $metabox_title = $this->get_metabox_title();
        
        // For traditional WooCommerce order pages (post-based)
        add_meta_box(
            'livraria-expedition',
            $metabox_title,
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
                $metabox_title,
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
                $metabox_title,
                array($this, 'expedition_meta_box_callback'),
                $screen->id,
                'side',
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
        elseif (isset($_GET['id'])) {
            $order_id = intval($_GET['id']);
            $order = wc_get_order($order_id);
        }
        // Another fallback: try post parameter
        elseif (isset($_GET['post'])) {
            $order_id = intval($_GET['post']);
            $order = wc_get_order($order_id);
        }
        
        // Debug log
        error_log('Livraria Debug: Metabox callback - Order ID: ' . ($order_id ?? 'unknown') . ', Order object: ' . ($order ? 'yes' : 'no'));
        
        if (!$order_id || !$order) {
            echo '<p>Error: Could not load order information.</p>';
            return;
        }
        
        wp_nonce_field('courier_expedition_nonce', 'courier_expedition_nonce_field');
        
        $expedition_id = get_post_meta($order_id, '_courier_expedition_id', true);
        $awb_number = get_post_meta($order_id, '_courier_awb_number', true);
        $tracking_url = get_post_meta($order_id, '_courier_tracking_url', true);
        
        // Get order and check if payment method is COD
        $cod_amount_default = '0';
        $is_cod = false;
        
        if ($order) {
            if ($order) {
                // Get payment method ID (most reliable way to check)
                $payment_method = $order->get_payment_method();
                $payment_method_title = $order->get_payment_method_title();
                
                // Also check order meta directly as fallback
                $payment_method_meta = get_post_meta($order_id, '_payment_method', true);
                
                // Debug: Log payment method info
                error_log('Livraria Debug: Payment method ID: ' . $payment_method . ', Title: ' . $payment_method_title . ', Meta: ' . $payment_method_meta);
                
                // Primary check: payment method ID (WooCommerce COD gateway ID is 'cod')
                if ($payment_method === 'cod' || $payment_method_meta === 'cod') {
                    $is_cod = true;
                } else {
                    // Fallback: check payment method title for COD indicators (case-insensitive)
                    $payment_method_title_lower = strtolower($payment_method_title);
                    $cod_indicators = array('cash on delivery', 'payment on delivery', 'pay on delivery', 'cod');
                    
                    foreach ($cod_indicators as $indicator) {
                        if (strpos($payment_method_title_lower, strtolower($indicator)) !== false) {
                            $is_cod = true;
                            break;
                        }
                    }
                }
                
                // Set COD amount to order total if payment method is COD
                if ($is_cod) {
                    $cod_amount_default = $order->get_total();
                    error_log('Livraria Debug: COD detected, setting amount to: ' . $cod_amount_default);
                } else {
                    error_log('Livraria Debug: Not COD payment method');
                }
            }
        }
        
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
                    <h4>1. Package Information</h4>
                    <div id="packages-container">
                        <div class="package-item" data-package="1">
                            <h5>Package 1</h5>
                            <div class="package-dimensions">
                                <label>Weight (kg): <input type="number" name="package_weight[]" step="0.5" min="1" value="1" required></label>
                                <label>Width (cm): <input type="number" name="package_width[]" step="1" min="10" value="10" required></label>
                                <label>Height (cm): <input type="number" name="package_height[]" step="1" min="10" value="10" required></label>
                                <label>Length (cm): <input type="number" name="package_length[]" step="1" min="10" value="10" required></label>
                                <button type="button" class="button remove-package" style="display:none;">Remove</button>
                            </div>
                        </div>
                    </div>
                    <button type="button" id="add-package-btn" class="button">Add Another Package</button>
                </div>

                <!-- Content Description -->
                <div class="expedition-section">
                    <h4>2. Content Description</h4>
                    <textarea name="content_description" placeholder="Describe the package contents..." rows="3"></textarea>
                </div>

                <!-- Sender Profile Selection -->
                <div class="expedition-section">
                    <h4>3. Sender Profile</h4>
                    <?php
                    // Get sender profiles from API
                    $api_client = new Livraria_API_Client();
                    $sender_profiles = $api_client->get_sender_profile();
                    $default_profile_id = get_option('livraria_default_sender_profile_id', '');
                    
                    if ($sender_profiles !== false && !is_wp_error($sender_profiles)) {
                        $profiles = is_array($sender_profiles) ? $sender_profiles : array($sender_profiles);
                        ?>
                        <select id="livraria-sender-profile-select" name="livraria_sender_profile_id">
                            <?php foreach ($profiles as $index => $profile): 
                                $profile_array = (array) $profile;
                                $profile_id = isset($profile_array['id']) ? $profile_array['id'] : ('profile-' . $index);
                                
                                // Extract profile name
                                $profile_name = 'Profile ' . ($index + 1);
                                if (isset($profile_array['name']) && !empty($profile_array['name'])) {
                                    $profile_name = $profile_array['name'];
                                } elseif (isset($profile_array['companyName']) && !empty($profile_array['companyName'])) {
                                    $profile_name = $profile_array['companyName'];
                                } elseif (isset($profile_array['company']) && !empty($profile_array['company'])) {
                                    if (is_array($profile_array['company']) || is_object($profile_array['company'])) {
                                        $company_data = (array) $profile_array['company'];
                                        if (isset($company_data['name'])) {
                                            $profile_name = $company_data['name'];
                                        } elseif (isset($company_data['companyName'])) {
                                            $profile_name = $company_data['companyName'];
                                        }
                                    } else {
                                        $profile_name = $profile_array['company'];
                                    }
                                }
                                
                                // Use default profile if no order-specific profile is set
                                $selected_id = get_post_meta($order_id, '_livraria_sender_profile_id', true);
                                if (empty($selected_id)) {
                                    $selected_id = $default_profile_id;
                                }
                                ?>
                                <option value="<?php echo esc_attr($profile_id); ?>" <?php selected($selected_id, $profile_id); ?>>
                                    <?php echo esc_html($profile_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Select the sender profile to use for this expedition</p>
                        <?php
                    } else {
                        ?>
                        <p style="color: #d63638; font-size: 12px;">Unable to load sender profiles. Please check your API connection.</p>
                        <?php
                    }
                    ?>
                </div>

                <!-- Service Options -->
                <div class="expedition-section">
                    <h4>4. Service Options</h4>
                    <?php if ($order && $is_cod): ?>
                        <span class="service-badge cod">✓ Cash on Delivery</span>
                    <?php else: ?>
                        <span class="service-badge no-cod">No COD</span>
                    <?php endif; ?>
                    <div class="service-options-grid">
                        <div class="service-option-item">
                            <label>COD Amount: <span class="input-group"><input type="number" name="cod_amount" step="0.5" min="0" value="<?php echo esc_attr($cod_amount_default); ?>" required> RON</span></label>
                        </div>
                        <div class="service-option-item">
                            <label>Insurance Amount: <span class="input-group"><input type="number" name="insurance_amount" step="0.5" min="0" value="0" required> RON</span></label>
                        </div>
                        <div class="service-option-item">
                            <label><input type="checkbox" name="open_on_delivery" value="1"> Open on Delivery</label>
                        </div>
                        <div class="service-option-item">
                            <label><input type="checkbox" name="saturday_delivery" value="1"> Saturday Delivery</label>
                        </div>
                    </div>
                </div>

                <div class="expedition-section">
                    <button type="button" id="create-expedition-btn" class="button button-primary">Get Quotes</button>
                    <div id="expedition-loading" style="display:none;">Loading quotes...</div>
                    <div id="expedition-result"></div>
                </div>
                
                <!-- Quotes Display Section -->
                <div id="quotes-section" class="expedition-section" style="display:none;">
                    <h4>Available Shipping Options</h4>
                    <div id="quotes-container"></div>
                    <div id="quote-selection-result"></div>
                </div>
                
                <!-- Generate Label Section -->
                <div id="generate-label-section" class="expedition-section" style="display:none;">
                    <button type="button" id="generate-label-btn" class="button button-primary">Generate Label</button>
                    <div id="label-loading" style="display:none;">Generating label...</div>
                    <div id="label-result"></div>
                </div>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var packageCount = 1;
            var quoteRequestId = null;
            var selectedQuoteId = null;
            var autoCreateInProgress = false;
            
            // Intercept order status change to "completed" for auto-create expeditions
            // This prevents page refresh from interrupting the expedition creation flow
            var autoCreateEnabled = <?php echo get_option('courier_auto_create') ? 'true' : 'false'; ?>;
            var expeditionExists = <?php echo $expedition_id ? 'true' : 'false'; ?>;
            var orderId = <?php echo absint($order_id); ?>;
            
            // Check if we need to run auto-create (set by server-side hook)
            var shouldAutoCreate = <?php echo get_transient('livraria_auto_create_order_' . $order_id) ? 'true' : 'false'; ?>;
            
            if (autoCreateEnabled && !expeditionExists && shouldAutoCreate) {
                // Clear the flag
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'clear_auto_create_flag',
                        order_id: orderId,
                        nonce: $('#courier_expedition_nonce_field').val()
                    }
                });
                
                // Run auto-create immediately
                runAutoCreateExpedition();
            }
            
            function runAutoCreateExpedition() {
                if (autoCreateInProgress) return;
                
                autoCreateInProgress = true;
                window.livrariaPreventUnload = true;
                
                // Show loading overlay
                var $overlay = $('<div id="livraria-auto-create-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:999999;display:flex;align-items:center;justify-content:center;flex-direction:column;color:white;font-size:16px;"><div style="background:white;color:#333;padding:30px;border-radius:8px;max-width:500px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.3);"><h3 style="margin-top:0;color:#2271b1;">Creating Expedition...</h3><p>Please wait while we create the shipping expedition for this order.</p><div id="livraria-progress" style="margin:20px 0;"><div style="background:#f0f0f0;height:24px;border-radius:12px;overflow:hidden;border:2px solid #ddd;"><div id="livraria-progress-bar" style="background:#2271b1;height:100%;width:0%;transition:width 0.3s;display:flex;align-items:center;justify-content:center;color:white;font-size:12px;font-weight:bold;"></div></div></div><p id="livraria-status-text" style="margin:10px 0;font-size:14px;color:#666;">Getting quotes...</p></div></div>');
                $('body').append($overlay);
                
                var updateProgress = function(percent, text) {
                    $('#livraria-progress-bar').css('width', percent + '%').text(percent + '%');
                    $('#livraria-status-text').text(text);
                };
                
                // Step 1: Get quotes
                updateProgress(20, 'Getting shipping quotes...');
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_quotes_for_order',
                        order_id: orderId,
                        nonce: $('#courier_expedition_nonce_field').val(),
                        expedition_data: {}
                    },
                    success: function(response) {
                        if (!response.success || !response.data.quotes || response.data.quotes.length === 0) {
                            $overlay.find('h3').text('Error');
                            $overlay.find('p').html('Failed to get quotes: ' + (response.data ? response.data.message : 'Unknown error') + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                            autoCreateInProgress = false;
                            window.livrariaPreventUnload = false;
                            return;
                        }
                        
                        var quoteRequestId = response.data.quoteRequestId;
                        var quotes = response.data.quotes;
                        var selectedQuote = quotes[0];
                        
                        updateProgress(40, 'Selecting quote...');
                        
                        // Step 2: Select quote
                        // Get sender profile ID from dropdown
                        var senderProfileId = $('#livraria-sender-profile-select').val() || '';
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'select_quote',
                                order_id: orderId,
                                quote_request_id: quoteRequestId,
                                courier_quote_id: selectedQuote.id,
                                sender_profile_id: senderProfileId,
                                nonce: $('#courier_expedition_nonce_field').val()
                            },
                            success: function(selectResponse) {
                                if (!selectResponse.success) {
                                    $overlay.find('h3').text('Error');
                                    $overlay.find('p').html('Failed to select quote. <br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                    autoCreateInProgress = false;
                                    window.livrariaPreventUnload = false;
                                    return;
                                }
                                
                                updateProgress(60, 'Attaching billing information...');
                                
                                // Step 3: Generate label (auto-attach billing + create expedition)
                                $.ajax({
                                    url: ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'generate_label',
                                        order_id: orderId,
                                        nonce: $('#courier_expedition_nonce_field').val()
                                    },
                                    success: function(labelResponse) {
                                        if (!labelResponse.success) {
                                            $overlay.find('h3').text('Error');
                                            $overlay.find('p').html('Failed to create expedition: ' + (labelResponse.data || 'Unknown error') + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                            autoCreateInProgress = false;
                                            window.livrariaPreventUnload = false;
                                            return;
                                        }
                                        
                                        updateProgress(100, 'Expedition created successfully!');
                                        
                                        // Show success message briefly, then reload
                                        setTimeout(function() {
                                            $overlay.find('h3').text('Success!');
                                            $overlay.find('p').html('Expedition created successfully. Reloading page...');
                                            
                                            autoCreateInProgress = false;
                                            window.livrariaPreventUnload = false;
                                            
                                            setTimeout(function() {
                                                location.reload();
                                            }, 1000);
                                        }, 1000);
                                    },
                                    error: function() {
                                        $overlay.find('h3').text('Error');
                                        $overlay.find('p').html('AJAX error occurred while creating expedition.<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                        autoCreateInProgress = false;
                                        window.livrariaPreventUnload = false;
                                    }
                                });
                            },
                            error: function() {
                                $overlay.find('h3').text('Error');
                                $overlay.find('p').html('AJAX error occurred while selecting quote.<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                autoCreateInProgress = false;
                                window.livrariaPreventUnload = false;
                            }
                        });
                    },
                    error: function() {
                        $overlay.find('h3').text('Error');
                        $overlay.find('p').html('AJAX error occurred while getting quotes.<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                        autoCreateInProgress = false;
                        window.livrariaPreventUnload = false;
                    }
                });
            }
            
            // Also intercept save button clicks as backup (for when status is changed but flag wasn't set)
            if (autoCreateEnabled && !expeditionExists) {
                // Find order status select - try multiple selectors for compatibility
                var $orderStatusSelect = $('#order_status, select[name="order_status"], #order-status-select, select#order_status, .order-status-select');
                var $orderForm = $('#post, form[name="post"], form#post, form.edit-order').first();
                var $saveButton = $('button.save_order, input#save-post, button[type="submit"], button[name="save"]').first();
                
                if ($orderStatusSelect.length) {
                    var originalStatus = $orderStatusSelect.val() || $orderStatusSelect.find('option:selected').val();
                    var formSubmitted = false;
                    var statusChangedToCompleted = false;
                    
                    // Watch for status changes
                    $orderStatusSelect.on('change', function() {
                        var newStatus = $(this).val() || $(this).find('option:selected').val();
                        var isCompleted = (newStatus === 'wc-completed' || newStatus === 'completed');
                        statusChangedToCompleted = isCompleted && (originalStatus !== 'wc-completed' && originalStatus !== 'completed');
                        originalStatus = newStatus;
                    });
                    
                    // Intercept save button clicks
                    $saveButton.on('click', function(e) {
                        var newStatus = $orderStatusSelect.val() || $orderStatusSelect.find('option:selected').val();
                        var isCompleted = (newStatus === 'wc-completed' || newStatus === 'completed');
                        var isChangingToCompleted = isCompleted && (originalStatus !== 'wc-completed' && originalStatus !== 'completed');
                        
                        if (isChangingToCompleted && !autoCreateInProgress && !formSubmitted) {
                            e.preventDefault();
                            e.stopImmediatePropagation();
                            e.stopPropagation();
                            
                            statusChangedToCompleted = true;
                            autoCreateInProgress = true;
                            
                            // Prevent page unload
                            window.livrariaPreventUnload = true;
                            
                            // Show loading overlay
                            var $overlay = $('<div id="livraria-auto-create-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:999999;display:flex;align-items:center;justify-content:center;flex-direction:column;color:white;font-size:16px;"><div style="background:white;color:#333;padding:30px;border-radius:8px;max-width:500px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.3);"><h3 style="margin-top:0;color:#2271b1;">Creating Expedition...</h3><p>Please wait while we create the shipping expedition for this order.</p><div id="livraria-progress" style="margin:20px 0;"><div style="background:#f0f0f0;height:24px;border-radius:12px;overflow:hidden;border:2px solid #ddd;"><div id="livraria-progress-bar" style="background:#2271b1;height:100%;width:0%;transition:width 0.3s;display:flex;align-items:center;justify-content:center;color:white;font-size:12px;font-weight:bold;"></div></div></div><p id="livraria-status-text" style="margin:10px 0;font-size:14px;color:#666;">Getting quotes...</p></div></div>');
                            $('body').append($overlay);
                            
                            var updateProgress = function(percent, text) {
                                $('#livraria-progress-bar').css('width', percent + '%').text(percent + '%');
                                $('#livraria-status-text').text(text);
                            };
                            
                            // Step 1: Get quotes
                            updateProgress(20, 'Getting shipping quotes...');
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'get_quotes_for_order',
                                    order_id: <?php echo absint($order_id); ?>,
                                    nonce: $('#courier_expedition_nonce_field').val(),
                                    expedition_data: {}
                                },
                                success: function(response) {
                                    if (!response.success || !response.data.quotes || response.data.quotes.length === 0) {
                                        $overlay.find('h3').text('Error');
                                        $overlay.find('p').html('Failed to get quotes: ' + (response.data ? response.data.message : 'Unknown error') + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                        autoCreateInProgress = false;
                                        window.livrariaPreventUnload = false;
                                        return;
                                    }
                                    
                                    var quoteRequestId = response.data.quoteRequestId;
                                    var quotes = response.data.quotes;
                                    var selectedQuote = quotes[0];
                                    
                                    updateProgress(40, 'Selecting quote...');
                                    
                                    // Step 2: Select quote
                                    // Get sender profile ID from dropdown
                                    var senderProfileId = $('#livraria-sender-profile-select').val() || '';
                                    
                                    $.ajax({
                                        url: ajaxurl,
                                        type: 'POST',
                                        data: {
                                            action: 'select_quote',
                                            order_id: <?php echo absint($order_id); ?>,
                                            quote_request_id: quoteRequestId,
                                            courier_quote_id: selectedQuote.id,
                                            sender_profile_id: senderProfileId,
                                            nonce: $('#courier_expedition_nonce_field').val()
                                        },
                                        success: function(selectResponse) {
                                            if (!selectResponse.success) {
                                                $overlay.find('h3').text('Error');
                                                $overlay.find('p').html('Failed to select quote. <br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                                autoCreateInProgress = false;
                                                window.livrariaPreventUnload = false;
                                                return;
                                            }
                                            
                                            updateProgress(60, 'Attaching billing information...');
                                            
                                            // Step 3: Generate label (auto-attach billing + create expedition)
                                            $.ajax({
                                                url: ajaxurl,
                                                type: 'POST',
                                                data: {
                                                    action: 'generate_label',
                                                    order_id: <?php echo absint($order_id); ?>,
                                                    nonce: $('#courier_expedition_nonce_field').val()
                                                },
                                                success: function(labelResponse) {
                                                    if (!labelResponse.success) {
                                                        $overlay.find('h3').text('Error');
                                                        $overlay.find('p').html('Failed to create expedition: ' + (labelResponse.data || 'Unknown error') + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                                        autoCreateInProgress = false;
                                                        window.livrariaPreventUnload = false;
                                                        return;
                                                    }
                                                    
                                                    updateProgress(100, 'Expedition created successfully!');
                                                    
                                                    // Show success message briefly, then submit form
                                                    setTimeout(function() {
                                                        $overlay.find('h3').text('Success!');
                                                        $overlay.find('p').html('Expedition created successfully. Reloading page...');
                                                        
                                                        formSubmitted = true;
                                                        autoCreateInProgress = false;
                                                        window.livrariaPreventUnload = false;
                                                        
                                                        // Submit the form to save the status change
                                                        setTimeout(function() {
                                                            if ($orderForm.length) {
                                                                $orderForm.off('submit').submit();
                                                            } else {
                                                                location.reload();
                                                            }
                                                        }, 500);
                                                    }, 1000);
                                                },
                                                error: function() {
                                                    $overlay.find('h3').text('Error');
                                                    $overlay.find('p').html('AJAX error occurred while creating expedition.<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                                    autoCreateInProgress = false;
                                                    window.livrariaPreventUnload = false;
                                                }
                                            });
                                        },
                                        error: function() {
                                            $overlay.find('h3').text('Error');
                                            $overlay.find('p').html('AJAX error occurred while selecting quote.<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                            autoCreateInProgress = false;
                                            window.livrariaPreventUnload = false;
                                        }
                                    });
                                },
                                error: function() {
                                    $overlay.find('h3').text('Error');
                                    $overlay.find('p').html('AJAX error occurred while getting quotes.<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                    autoCreateInProgress = false;
                                    window.livrariaPreventUnload = false;
                                }
                            });
                            
                            return false;
                        }
                    });
                    
                    // Also intercept form submission as backup
                    if ($orderForm.length) {
                        $orderForm.on('submit', function(e) {
                            if (statusChangedToCompleted && !formSubmitted && autoCreateInProgress) {
                                e.preventDefault();
                                e.stopImmediatePropagation();
                                return false;
                            }
                        });
                    }
                    
                    // Prevent page unload during auto-create
                    $(window).on('beforeunload', function(e) {
                        if (window.livrariaPreventUnload && autoCreateInProgress) {
                            return 'An expedition is being created. Are you sure you want to leave?';
                        }
                    });
                }
            }
            
            // Add package functionality
            $('#add-package-btn').click(function() {
                packageCount++;
                var packageHtml = '<div class="package-item" data-package="' + packageCount + '">' +
                    '<h5>Package ' + packageCount + '</h5>' +
                    '<div class="package-dimensions">' +
                        '<label>Weight (kg): <input type="number" name="package_weight[]" step="0.5" min="1" value="1" required></label>' +
                        '<label>Width (cm): <input type="number" name="package_width[]" step="1" min="10" value="10" required></label>' +
                        '<label>Height (cm): <input type="number" name="package_height[]" step="1" min="10" value="10" required></label>' +
                        '<label>Length (cm): <input type="number" name="package_length[]" step="1" min="10" value="10" required></label>' +
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
            
            // Get quotes when "Get Quotes" button is clicked
            $('#create-expedition-btn').click(function() {
                var btn = $(this);
                
                // Validate inputs before proceeding
                var isValid = true;
                var errors = [];
                
                // Validate package dimensions
                $('.package-item').each(function(index) {
                    var $item = $(this);
                    var weight = parseFloat($item.find('input[name="package_weight[]"]').val());
                    var width = parseFloat($item.find('input[name="package_width[]"]').val());
                    var height = parseFloat($item.find('input[name="package_height[]"]').val());
                    var length = parseFloat($item.find('input[name="package_length[]"]').val());
                    
                    if (isNaN(weight) || weight < 1) {
                        isValid = false;
                        errors.push('Package ' + (index + 1) + ': Weight must be at least 1 kg');
                    }
                    if (isNaN(width) || width < 10) {
                        isValid = false;
                        errors.push('Package ' + (index + 1) + ': Width must be at least 10 cm');
                    }
                    if (isNaN(height) || height < 10) {
                        isValid = false;
                        errors.push('Package ' + (index + 1) + ': Height must be at least 10 cm');
                    }
                    if (isNaN(length) || length < 10) {
                        isValid = false;
                        errors.push('Package ' + (index + 1) + ': Length must be at least 10 cm');
                    }
                });
                
                // Validate COD and Insurance amounts
                var codAmount = parseFloat($('input[name="cod_amount"]').val());
                var insuranceAmount = parseFloat($('input[name="insurance_amount"]').val());
                
                if (isNaN(codAmount) || codAmount < 0) {
                    isValid = false;
                    errors.push('COD Amount must be at least 0');
                }
                if (isNaN(insuranceAmount) || insuranceAmount < 0) {
                    isValid = false;
                    errors.push('Insurance Amount must be at least 0');
                }
                
                if (!isValid) {
                    $('#expedition-result').html('<p style="color:red;">' + errors.join('<br>') + '</p>');
                    return;
                }
                
                btn.prop('disabled', true);
                $('#expedition-loading').show();
                $('#expedition-result').html('');
                $('#quotes-section').hide();
                $('#generate-label-section').hide();
                
                // Collect package data
                var packages = [];
                $('.package-item').each(function() {
                    var $item = $(this);
                    packages.push({
                        weight: parseFloat($item.find('input[name="package_weight[]"]').val()),
                        width: parseFloat($item.find('input[name="package_width[]"]').val()),
                        height: parseFloat($item.find('input[name="package_height[]"]').val()),
                        length: parseFloat($item.find('input[name="package_length[]"]').val())
                    });
                });
                
                // Collect service options
                var expeditionData = {
                    packages: packages,
                    content_description: $('textarea[name="content_description"]').val() || '',
                    cod_amount: parseFloat($('input[name="cod_amount"]').val()),
                    insurance_amount: parseFloat($('input[name="insurance_amount"]').val()),
                    open_on_delivery: $('input[name="open_on_delivery"]').is(':checked'),
                    saturday_delivery: $('input[name="saturday_delivery"]').is(':checked')
                };
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_quotes_for_order',
                        order_id: <?php echo $order_id; ?>,
                        nonce: $('#courier_expedition_nonce_field').val(),
                        expedition_data: expeditionData
                    },
                    success: function(response) {
                        $('#expedition-loading').hide();
                        if (response.success && response.data.quotes && response.data.quotes.length > 0) {
                            quoteRequestId = response.data.quoteRequestId;
                            displayQuotes(response.data.quotes);
                            $('#quotes-section').show();
                            $('#quote-selection-result').html(''); // Clear any previous selection messages
                            btn.prop('disabled', false);
                        } else {
                            $('#expedition-result').html('<p style="color:red;">Error: ' + (response.data ? response.data.message : 'Failed to get quotes') + '</p>');
                            btn.prop('disabled', false);
                        }
                    },
                    error: function() {
                        $('#expedition-loading').hide();
                        $('#expedition-result').html('<p style="color:red;">AJAX error occurred</p>');
                        btn.prop('disabled', false);
                    }
                });
            });
            
            // Display quotes
            function displayQuotes(quotes) {
                var quotesHtml = '';
                quotes.forEach(function(quote, index) {
                    var isSelected = selectedQuoteId === quote.id;
                    quotesHtml += '<div class="quote-item" data-quote-id="' + quote.id + '" style="border: 1px solid #ddd; padding: 10px; margin-bottom: 10px; border-radius: 4px; ' + (isSelected ? 'border-color: #0073aa; background-color: #f0f8ff;' : '') + '">';
                    quotesHtml += '<strong>' + (quote.courierName || 'Courier ' + (index + 1)) + '</strong>';
                    if (quote.amount !== undefined) {
                        quotesHtml += '<br>Price: <strong>' + quote.amount + ' ' + (quote.currency || 'RON') + '</strong>';
                    }
                    if (quote.deliveryDays !== undefined) {
                        quotesHtml += '<br>Estimated delivery: ' + quote.deliveryDays + ' day(s)';
                    }
                    quotesHtml += '<br><button type="button" class="button select-quote-btn" data-quote-id="' + quote.id + '" ' + (isSelected ? 'disabled style="background-color: #46b450; color: white;"' : '') + '>';
                    quotesHtml += isSelected ? '✓ Selected' : 'Select This Quote';
                    quotesHtml += '</button>';
                    quotesHtml += '</div>';
                });
                $('#quotes-container').html(quotesHtml);
            }
            
            // Select quote
            $(document).on('click', '.select-quote-btn', function() {
                var quoteId = $(this).data('quote-id');
                var btn = $(this);
                
                if (!quoteRequestId) {
                    $('#expedition-result').html('<p style="color:red;">Quote request ID not found</p>');
                    return;
                }
                
                btn.prop('disabled', true);
                $('#expedition-loading').show();
                
                // Get sender profile ID from dropdown
                var senderProfileId = $('#livraria-sender-profile-select').val() || '';
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'select_quote',
                        order_id: <?php echo $order_id; ?>,
                        quote_request_id: quoteRequestId,
                        courier_quote_id: quoteId,
                        sender_profile_id: senderProfileId,
                        nonce: $('#courier_expedition_nonce_field').val()
                    },
                    success: function(response) {
                        $('#expedition-loading').hide();
                        if (response.success) {
                            selectedQuoteId = quoteId;
                            // Update UI to show selected quote
                            $('.quote-item').removeClass('selected').css({'border-color': '#ddd', 'background-color': ''});
                            $('.select-quote-btn').prop('disabled', false).text('Select This Quote').css({'background-color': '', 'color': ''});
                            
                            $('.quote-item[data-quote-id="' + quoteId + '"]').css({'border-color': '#0073aa', 'background-color': '#f0f8ff'});
                            btn.prop('disabled', true).text('✓ Selected').css({'background-color': '#46b450', 'color': 'white'});
                            
                            // Show generate label button
                            $('#generate-label-section').show();
                            $('#quote-selection-result').html('<p style="color:green;">Quote selected successfully!</p>');
                        } else {
                            $('#quote-selection-result').html('<p style="color:red;">Error: ' + (response.data || 'Failed to select quote') + '</p>');
                            btn.prop('disabled', false);
                        }
                    },
                    error: function() {
                        $('#expedition-loading').hide();
                        $('#expedition-result').html('<p style="color:red;">AJAX error occurred</p>');
                        btn.prop('disabled', false);
                    }
                });
            });
            
            // Generate label
            $('#generate-label-btn').click(function() {
                var btn = $(this);
                btn.prop('disabled', true);
                $('#label-loading').show();
                $('#label-result').html('');
                
                if (!quoteRequestId || !selectedQuoteId) {
                    $('#label-result').html('<p style="color:red;">Please select a quote first</p>');
                    $('#label-loading').hide();
                    btn.prop('disabled', false);
                    return;
                }
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'generate_label',
                        order_id: <?php echo $order_id; ?>,
                        nonce: $('#courier_expedition_nonce_field').val()
                    },
                    success: function(response) {
                        $('#label-loading').hide();
                        if (response.success) {
                            $('#label-result').html('<p style="color:green;">Expedition created successfully! AWB: ' + (response.data.awb_number || 'N/A') + '</p>');
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            $('#label-result').html('<p style="color:red;">Error: ' + (response.data || 'Failed to generate label') + '</p>');
                            btn.prop('disabled', false);
                        }
                    },
                    error: function() {
                        $('#label-loading').hide();
                        $('#label-result').html('<p style="color:red;">AJAX error occurred</p>');
                        btn.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX handler to clear auto-create flag
     */
    public function ajax_clear_auto_create_flag() {
        $order_id = intval($_POST['order_id']);
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
        
        $order_id = intval($_POST['order_id']);
        
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
        error_log('Livraria Debug: auto_create_expedition called for order ID: ' . $order_id);
        
        if (!get_option('courier_auto_create')) {
            error_log('Livraria Debug: Auto-create expeditions is disabled');
            return;
        }
        
        // Check if expedition already exists
        if (get_post_meta($order_id, '_courier_expedition_id', true)) {
            error_log('Livraria Debug: Expedition already exists for order ID: ' . $order_id);
            return;
        }
        
        error_log('Livraria Debug: Starting auto-create expedition process for order ID: ' . $order_id);
        
        // Step 1: Get quotes (creates quote request)
        $quotes_result = $this->order_handler->get_quotes_for_order($order_id);
        
        if (!$quotes_result['success']) {
            error_log('Livraria Debug: Failed to get quotes: ' . $quotes_result['message']);
            return;
        }
        
        if (empty($quotes_result['quotes'])) {
            error_log('Livraria Debug: No quotes available for order ID: ' . $order_id);
            return;
        }
        
        $quote_request_id = $quotes_result['quoteRequestId'];
        $quotes = $quotes_result['quotes'];
        
        // Step 2: Automatically select the first quote (or best quote)
        $selected_quote = $quotes[0]; // Select first quote
        $selected_quote_id = $selected_quote['id'];
        
        error_log('Livraria Debug: Selected quote ID: ' . $selected_quote_id . ' for order ID: ' . $order_id);
        
        // Store selected quote in order meta
        update_post_meta($order_id, '_courier_selected_quote_id', $selected_quote_id);
        
        // Step 3: Select the quote via API
        $select_result = $this->api_client->select_courier_quote($quote_request_id, $selected_quote_id);
        
        if ($select_result === false) {
            error_log('Livraria Debug: Failed to select quote via API for order ID: ' . $order_id);
            return;
        }
        
        // Step 4: Attach billing info from default sender profile
        $default_sender_profile_id = get_option('livraria_default_sender_profile_id', '');
        
        if (empty($default_sender_profile_id)) {
            error_log('Livraria Debug: No default sender profile configured for order ID: ' . $order_id);
            return;
        }
        
        $billing_response = $this->api_client->attach_billing_info_from_sender_profile($quote_request_id, $default_sender_profile_id);
        
        if ($billing_response === false || !isset($billing_response['id'])) {
            error_log('Livraria Debug: Failed to attach billing information from sender profile for order ID: ' . $order_id);
            return;
        }
        
        $billing_info_id = $billing_response['id'];
        
        // Step 5: Create expedition
        $expedition_data = array(
            'quoteRequestId' => $quote_request_id,
            'courierQuoteId' => $selected_quote_id,
            'billingInfoId' => $billing_info_id
        );
        
        $expedition_response = $this->api_client->create_expedition($expedition_data);
        
        if ($expedition_response === false || !isset($expedition_response['id'])) {
            error_log('Livraria Debug: Failed to create expedition for order ID: ' . $order_id);
            return;
        }
        
        // Step 6: Save expedition data
        $order = wc_get_order($order_id);
        if ($order) {
            $this->order_handler->save_expedition_data($order_id, $expedition_response, $selected_quote);
            $this->order_handler->add_expedition_order_note($order, $expedition_response);
        }
        
        // Clean up temporary meta
        delete_post_meta($order_id, '_courier_quote_request_id');
        delete_post_meta($order_id, '_courier_selected_quote_id');
        
        error_log('Livraria Debug: Auto-create expedition completed successfully for order ID: ' . $order_id . ', Expedition ID: ' . $expedition_response['id']);
        
        // If called via AJAX, send JSON response
        if (defined('DOING_AJAX') && DOING_AJAX) {
            wp_send_json_success(array(
                'message' => 'Expedition created successfully',
                'expedition_id' => $expedition_response['id'],
                'awb_number' => $expedition_response['awbNumber'] ?? null
            ));
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
            error_log('Livraria Debug: Order ' . $order_id . ' status changed to completed, flag set for JS');
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
    
    public function ajax_create_expedition() {
        check_ajax_referer('courier_expedition_nonce', 'nonce');
        
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
    
    /**
     * AJAX handler to get quotes for an order (creates quote request)
     */
    public function ajax_get_quotes_for_order() {
        check_ajax_referer('courier_expedition_nonce', 'nonce');
        
        $order_id = intval($_POST['order_id']);
        $custom_data = isset($_POST['expedition_data']) ? $_POST['expedition_data'] : array();
        
        // Sanitize custom data
        if (!empty($custom_data)) {
            $custom_data = $this->sanitize_expedition_data($custom_data);
        }
        
        $result = $this->order_handler->get_quotes_for_order($order_id, $custom_data);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * AJAX handler to select a quote
     */
    public function ajax_select_quote() {
        check_ajax_referer('courier_expedition_nonce', 'nonce');
        
        $order_id = intval($_POST['order_id']);
        $quote_request_id = sanitize_text_field($_POST['quote_request_id']);
        $courier_quote_id = sanitize_text_field($_POST['courier_quote_id']);
        $sender_profile_id = isset($_POST['sender_profile_id']) ? sanitize_text_field($_POST['sender_profile_id']) : '';
        
        // Select the quote
        $result = $this->api_client->select_courier_quote($quote_request_id, $courier_quote_id);
        
        if ($result !== false) {
            // Store selected quote info in order meta
            update_post_meta($order_id, '_courier_quote_request_id', $quote_request_id);
            update_post_meta($order_id, '_courier_selected_quote_id', $courier_quote_id);
            
            // If sender profile ID is provided, attach billing info from sender profile
            if (!empty($sender_profile_id)) {
                // Save sender profile ID for this order
                update_post_meta($order_id, '_livraria_sender_profile_id', $sender_profile_id);
                
                // Attach billing info from sender profile
                $billing_result = $this->api_client->attach_billing_info_from_sender_profile($quote_request_id, $sender_profile_id);
                
                if ($billing_result === false) {
                    // Log error but don't fail the quote selection
                    error_log('Livraria Debug: Failed to attach billing info from sender profile, but quote selection succeeded');
                }
            }
            
            wp_send_json_success(array('message' => 'Quote selected successfully'));
        } else {
            wp_send_json_error('Failed to select quote');
        }
    }
    
    /**
     * AJAX handler to generate label (create expedition after quote selection)
     */
    public function ajax_generate_label() {
        check_ajax_referer('courier_expedition_nonce', 'nonce');
        
        $order_id = intval($_POST['order_id']);
        $quote_request_id = get_post_meta($order_id, '_courier_quote_request_id', true);
        $courier_quote_id = get_post_meta($order_id, '_courier_selected_quote_id', true);
        
        if (!$quote_request_id || !$courier_quote_id) {
            wp_send_json_error('Quote request ID or selected quote ID not found');
        }
        
        // Attach billing info from default sender profile
        $default_sender_profile_id = get_option('livraria_default_sender_profile_id', '');
        
        if (empty($default_sender_profile_id)) {
            wp_send_json_error('No default sender profile configured');
        }
        
        $billing_response = $this->api_client->attach_billing_info_from_sender_profile($quote_request_id, $default_sender_profile_id);
        if ($billing_response === false || !isset($billing_response['id'])) {
            wp_send_json_error('Failed to attach billing information from sender profile');
        }
        
        $billing_info_id = $billing_response['id'];
        
        // Create expedition
        $expedition_data = array(
            'quoteRequestId' => $quote_request_id,
            'courierQuoteId' => $courier_quote_id,
            'billingInfoId' => $billing_info_id
        );
        
        $expedition_response = $this->api_client->create_expedition($expedition_data);
        
        if ($expedition_response !== false && isset($expedition_response['id'])) {
            // Save expedition data
            $order = wc_get_order($order_id);
            if ($order) {
                $this->order_handler->save_expedition_data($order_id, $expedition_response, array('id' => $courier_quote_id));
                $this->order_handler->add_expedition_order_note($order, $expedition_response);
            }
            
            // Clean up temporary meta
            delete_post_meta($order_id, '_courier_quote_request_id');
            delete_post_meta($order_id, '_courier_selected_quote_id');
            
            wp_send_json_success(array(
                'message' => 'Expedition created successfully',
                'expedition_id' => $expedition_response['id'],
                'awb_number' => $expedition_response['awbNumber'] ?? null
            ));
        } else {
            wp_send_json_error('Failed to create expedition');
        }
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
        $sanitized['open_on_delivery'] = (bool)($data['open_on_delivery'] ?? false);
        $sanitized['saturday_delivery'] = (bool)($data['saturday_delivery'] ?? false);
        
        return $sanitized;
    }
    
    public function ajax_test_api_connection() {
        check_ajax_referer('livraria_admin_nonce', 'nonce');
        
        // Get test credentials from POST data
        $api_url = sanitize_url($_POST['api_url']);
        $username = sanitize_text_field($_POST['username']);
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
        check_ajax_referer('livraria_admin_nonce', 'nonce');
        
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
    
    /**
     * AJAX handler for logout
     */
    public function ajax_logout() {
        check_ajax_referer('livraria_admin_nonce', 'nonce');
        
        // Clear tokens (simplest logout logic)
        delete_option('livraria_api_token');
        delete_option('livraria_token_expires_at');
        
        wp_send_json_success(array('message' => 'Logged out successfully'));
    }
    
    /**
     * AJAX handler for login
     */
    public function ajax_login() {
        check_ajax_referer('livraria_admin_nonce', 'nonce');
        
        $api_url = sanitize_url($_POST['api_url']);
        $username = sanitize_text_field($_POST['username']);
        $password = sanitize_text_field($_POST['password']);
        
        if (empty($api_url) || empty($username) || empty($password)) {
            wp_send_json_error('API URL, username, and password are required');
        }
        
        // Update API URL first
        update_option('courier_api_base_url', $api_url);
        
        // Create API client and attempt login
        $api_client = new Livraria_API_Client($api_url);
        $result = $api_client->login($username, $password);
        
        if ($result['success']) {
            // Check if "Remember me" is enabled
            $remember_me = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';
            update_option('livraria_remember_credentials', $remember_me);
            
            if ($remember_me) {
                // Save encrypted credentials for future use
                $api_client->set_encrypted_option('courier_api_username', $username);
                $api_client->set_encrypted_option('courier_api_password', $password);
            } else {
                // Clear stored credentials if "Remember me" is not checked
                delete_option('courier_api_username');
                delete_option('courier_api_password');
            }
            
            wp_send_json_success(array('message' => 'Login successful'));
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * AJAX handler for updating a single option
     */
    public function ajax_update_option() {
        check_ajax_referer('livraria_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $option_name = sanitize_text_field($_POST['option_name']);
        $option_value = sanitize_text_field($_POST['option_value']);
        
        // Validate that this is an allowed option
        $allowed_options = array('courier_auto_create', 'livraria_default_sender_profile_id');
        if (!in_array($option_name, $allowed_options)) {
            wp_send_json_error('Option not allowed');
        }
        
        // Update the option (same as what happens when clicking "Save changes")
        $result = update_option($option_name, $option_value);
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Option updated successfully'));
        } else {
            wp_send_json_error('Failed to update option');
        }
    }
    
    public function save_expedition_meta($post_id) {
        // Check if this is an order post type
        if (get_post_type($post_id) !== 'shop_order') {
            return;
        }
        
        // Verify nonce if present (form submission, not AJAX)
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
        
        .quote-item {
            border: 1px solid #ddd;
            padding: 12px;
            margin-bottom: 10px;
            border-radius: 4px;
            background: #fff;
        }
        
        .quote-item.selected {
            border-color: #0073aa;
            background-color: #f0f8ff;
        }
        
        .quote-item strong {
            font-size: 14px;
            color: #333;
        }
        
        .select-quote-btn {
            margin-top: 8px;
        }
        
        .select-quote-btn:disabled {
            background-color: #46b450 !important;
            color: white !important;
            cursor: not-allowed;
        }
        ";
        
        wp_add_inline_style('wp-admin', $css);
    }
}

// Initialize the plugin
new LivrariaPlugin();