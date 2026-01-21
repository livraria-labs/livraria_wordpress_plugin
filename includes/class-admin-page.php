<?php
/**
 * Admin Page Handler for Livraria Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Livraria_Admin_Page {
    
    private $api_client;
    
    public function __construct($api_client) {
        $this->api_client = $api_client;
    }
    
    /**
     * Render the admin settings page
     */
    public function render() {
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
            
            <?php
            // Display user info and sender profile if token is available
            $this->render_account_information();
            ?>
            
            <div id="api-test-section" style="margin-top: 30px;">
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
    
    /**
     * Render account information section (user profile and sender profile)
     */
    private function render_account_information() {
        $api_token = get_option('livraria_api_token', '');
        if (empty($api_token)) {
            return;
        }
        
        // Ensure API client has latest base URL
        $current_base_url = get_option('courier_api_base_url', '');
        if (!empty($current_base_url)) {
            $this->api_client->set_api_base_url($current_base_url);
        }
        
        $user_info = $this->api_client->get_user_info();
        $sender_profile = $this->api_client->get_sender_profile();
        ?>
        <div id="user-info-section" style="margin-top: 30px;">
            <?php $this->render_user_profile($user_info); ?>
            <?php $this->render_sender_profiles($sender_profile); ?>
        </div>
        <?php
    }
    
    /**
     * Render user profile information
     * 
     * @param mixed $user_info User info from API
     */
    private function render_user_profile($user_info) {
        if ($user_info === false || is_wp_error($user_info)) {
            ?>
            <div style="background: #fcf0f1; border-left: 4px solid #d63638; padding: 12px 20px; margin-bottom: 20px; border-radius: 2px;">
                <p style="margin: 0; color: #d63638;">Unable to fetch user information. Please check your API connection.</p>
            </div>
            <?php
            return;
        }
        
        $user_info = (array) $user_info;
        ?>
        <div style="background: #f7f7f7; border-left: 4px solid #2271b1; padding: 15px 20px; margin-bottom: 20px; border-radius: 2px;">
            <h3 style="margin: 0 0 15px 0; font-size: 16px; color: #1d2327;">User Profile</h3>
            <div style="display: flex; flex-wrap: wrap; gap: 20px 30px;">
                <?php
                // Display name
                if (isset($user_info['name']) && !empty($user_info['name'])) {
                    echo '<div><strong style="display: block; color: #646970; font-size: 12px; margin-bottom: 4px;">Name</strong><span style="color: #1d2327; font-size: 14px;">' . esc_html($user_info['name']) . '</span></div>';
                }
                
                // Display email
                if (isset($user_info['email']) && !empty($user_info['email'])) {
                    echo '<div><strong style="display: block; color: #646970; font-size: 12px; margin-bottom: 4px;">Email</strong><span style="color: #1d2327; font-size: 14px;">' . esc_html($user_info['email']) . '</span></div>';
                }
                
                // Display phone (combine phoneCountrycode + phoneNSN)
                $phone = '';
                if (isset($user_info['phoneCountrycode']) || isset($user_info['phoneNSN'])) {
                    $country_code = isset($user_info['phoneCountrycode']) ? $user_info['phoneCountrycode'] : '';
                    $nsn = isset($user_info['phoneNSN']) ? $user_info['phoneNSN'] : '';
                    $phone = trim($country_code . ' ' . $nsn);
                }
                if (!empty($phone)) {
                    echo '<div><strong style="display: block; color: #646970; font-size: 12px; margin-bottom: 4px;">Phone</strong><span style="color: #1d2327; font-size: 14px;">' . esc_html($phone) . '</span></div>';
                }
                
                // Display isActive
                if (isset($user_info['isActive'])) {
                    $is_active = is_bool($user_info['isActive']) ? $user_info['isActive'] : (strtolower($user_info['isActive']) === 'true' || $user_info['isActive'] === '1');
                    $status_color = $is_active ? '#00a32a' : '#d63638';
                    $status_text = $is_active ? 'Active' : 'Inactive';
                    echo '<div><strong style="display: block; color: #646970; font-size: 12px; margin-bottom: 4px;">Status</strong><span style="color: ' . $status_color . '; font-size: 14px; font-weight: 500;">' . esc_html($status_text) . '</span></div>';
                }
                
                // Display isBusiness
                if (isset($user_info['isBusiness'])) {
                    $is_business = is_bool($user_info['isBusiness']) ? $user_info['isBusiness'] : (strtolower($user_info['isBusiness']) === 'true' || $user_info['isBusiness'] === '1');
                    $type_text = $is_business ? 'Business' : 'Personal';
                    echo '<div><strong style="display: block; color: #646970; font-size: 12px; margin-bottom: 4px;">Account Type</strong><span style="color: #1d2327; font-size: 14px;">' . esc_html($type_text) . '</span></div>';
                }
                
                // Display company (if exists and isBusiness is true)
                if (isset($user_info['company']) && !empty($user_info['company'])) {
                    echo '<div><strong style="display: block; color: #646970; font-size: 12px; margin-bottom: 4px;">Company</strong><span style="color: #1d2327; font-size: 14px;">' . esc_html($user_info['company']) . '</span></div>';
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render sender profiles (handles array of profiles)
     * 
     * @param mixed $sender_profile Sender profile(s) from API
     */
    private function render_sender_profiles($sender_profile) {
        if ($sender_profile === false || is_wp_error($sender_profile)) {
            ?>
            <div style="background: #fcf0f1; border-left: 4px solid #d63638; padding: 12px 20px; border-radius: 2px;">
                <p style="margin: 0; color: #d63638;">Unable to fetch sender profile. Please check your API connection.</p>
            </div>
            <?php
            return;
        }
        
        // Handle array of sender profiles
        $profiles = is_array($sender_profile) ? $sender_profile : array($sender_profile);
        ?>
        <div style="background: #f7f7f7; border-left: 4px solid #2271b1; padding: 15px 20px; border-radius: 2px;">
            <h3 style="margin: 0 0 15px 0; font-size: 16px; color: #1d2327;">Sender Profile<?php echo count($profiles) > 1 ? 's' : ''; ?></h3>
            <?php
            foreach ($profiles as $index => $profile) {
                $is_multiple = count($profiles) > 1;
                ?>
                <?php if ($is_multiple): ?>
                    <div style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #ddd;">
                <?php endif; ?>
                
                <?php if ($is_multiple): ?>
                    <h4 style="margin: 0 0 12px 0; font-size: 14px; color: #646970; font-weight: 600;">Profile <?php echo ($index + 1); ?></h4>
                <?php endif; ?>
                
                <div style="display: flex; flex-wrap: wrap; gap: 20px 30px;">
                    <?php $this->render_sender_profile($profile); ?>
                </div>
                
                <?php if ($is_multiple && $index < count($profiles) - 1): ?>
                    </div>
                <?php endif; ?>
                <?php
            }
            ?>
        </div>
        <?php
    }
    
    /**
     * Render a single sender profile in a compact format
     * 
     * @param mixed $sender_profile Sender profile data (single profile object)
     */
    private function render_sender_profile($sender_profile) {
        if (!is_array($sender_profile) && !is_object($sender_profile)) {
            return;
        }
        
        $sender_profile = (array) $sender_profile;
        
        // Display all relevant fields from sender profile
        // Show all fields that have values (excluding technical fields like id, createdAt, etc.)
        $hidden_fields = array('id', 'createdAt', 'created_at', 'updatedAt', 'updated_at', 'deletedAt', 'deleted_at', 'userId', 'user_id');
        
        foreach ($sender_profile as $field_key => $value) {
            // Skip hidden/technical fields
            if (in_array(strtolower($field_key), array_map('strtolower', $hidden_fields))) {
                continue;
            }
            
            // Skip empty values
            if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                continue;
            }
            
            $field_label = ucwords(str_replace(array('_', '-'), ' ', $field_key));
            $display_value = '';
            
            // Handle nested objects/arrays
            if (is_array($value) || is_object($value)) {
                $value = (array) $value;
                
                // Handle address object
                if (isset($value['street']) || isset($value['city']) || isset($value['country'])) {
                    $address_parts = array();
                    if (isset($value['street']) && !empty($value['street'])) {
                        $street = $value['street'];
                        if (isset($value['streetNumber']) && !empty($value['streetNumber'])) {
                            $street .= ' ' . $value['streetNumber'];
                        }
                        $address_parts[] = $street;
                    }
                    if (isset($value['city']) && !empty($value['city'])) {
                        $address_parts[] = $value['city'];
                    }
                    if (isset($value['county']) && !empty($value['county'])) {
                        $address_parts[] = $value['county'];
                    }
                    if (isset($value['postcode']) && !empty($value['postcode'])) {
                        $address_parts[] = $value['postcode'];
                    }
                    if (isset($value['country']) && !empty($value['country'])) {
                        $address_parts[] = $value['country'];
                    }
                    $display_value = !empty($address_parts) ? implode(', ', $address_parts) : '';
                } else {
                    // For other nested objects, show as JSON or skip
                    continue;
                }
            } else {
                // Handle simple values
                if (is_bool($value)) {
                    $display_value = $value ? 'Yes' : 'No';
                } else {
                    $display_value = $value;
                }
            }
            
            if ($display_value !== '') {
                echo '<div><strong style="display: block; color: #646970; font-size: 12px; margin-bottom: 4px;">' . esc_html($field_label) . '</strong><span style="color: #1d2327; font-size: 14px;">' . esc_html($display_value) . '</span></div>';
            }
        }
    }
}

