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
            
            <div style="background: #f7f7f7; border-left: 4px solid #2271b1; padding: 12px 16px; margin-top: 15px; margin-bottom: 15px; border-radius: 2px;">
                <h2 style="margin: 0 0 12px 0; font-size: 16px; color: #1d2327;">API Configuration</h2>
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
                </table>
                    <?php submit_button(); ?>
                </form>
            </div>
            
            <?php
            // Display user info and sender profile if token is available
            $this->render_account_information();
            ?>
            
            <div id="api-test-section" style="background: #f7f7f7; border-left: 4px solid #2271b1; padding: 12px 16px; margin-top: 15px; border-radius: 2px;">
                <h3 style="margin: 0 0 12px 0; font-size: 15px; color: #1d2327;">Test API Connection</h3>
                <p class="description" style="margin-bottom: 15px;">Test network connectivity and authentication separately to troubleshoot issues.</p>
                
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
            <?php $this->render_user_profile($user_info, $sender_profile); ?>
        </div>
        <?php
    }
    
    /**
     * Render user profile information organized into logical subsections
     * 
     * @param mixed $user_info User info from API
     * @param mixed $sender_profile Sender profile(s) from API
     */
    private function render_user_profile($user_info, $sender_profile = null) {
        if ($user_info === false || is_wp_error($user_info)) {
            ?>
            <div style="background: #fcf0f1; border-left: 4px solid #d63638; padding: 12px 20px; margin-bottom: 20px; border-radius: 2px;">
                <p style="margin: 0; color: #d63638;">Unable to fetch user information. Please check your API connection.</p>
            </div>
            <?php
            return;
        }
        
        $user_info = (array) $user_info;
        
        // Extract contact information
        $contact_fields = array();
        if (isset($user_info['name']) && !empty($user_info['name'])) {
            $contact_fields['name'] = $user_info['name'];
        }
        if (isset($user_info['email']) && !empty($user_info['email'])) {
            $contact_fields['email'] = $user_info['email'];
        }
        // Phone (combine phoneCountrycode + phoneNSN)
        $phone = '';
        if (isset($user_info['phoneCountrycode']) || isset($user_info['phoneNSN'])) {
            $country_code = isset($user_info['phoneCountrycode']) ? $user_info['phoneCountrycode'] : '';
            $nsn = isset($user_info['phoneNSN']) ? $user_info['phoneNSN'] : '';
            $phone = trim($country_code . ' ' . $nsn);
        } elseif (isset($user_info['phone']) && !empty($user_info['phone'])) {
            $phone = $user_info['phone'];
        }
        if (!empty($phone)) {
            $contact_fields['phone'] = $phone;
        }
        
        // Extract account information
        $account_fields = array();
        if (isset($user_info['isActive'])) {
            $is_active = is_bool($user_info['isActive']) ? $user_info['isActive'] : (strtolower($user_info['isActive']) === 'true' || $user_info['isActive'] === '1');
            $account_fields['status'] = array(
                'value' => $is_active ? 'Active' : 'Inactive',
                'color' => $is_active ? '#00a32a' : '#d63638'
            );
        }
        if (isset($user_info['isBusiness'])) {
            $is_business = is_bool($user_info['isBusiness']) ? $user_info['isBusiness'] : (strtolower($user_info['isBusiness']) === 'true' || $user_info['isBusiness'] === '1');
            $account_fields['accountType'] = $is_business ? 'Business' : 'Personal';
        }
        
        // Extract company information
        $company_fields = array();
        if (isset($user_info['company']) && !empty($user_info['company'])) {
            // Handle both string and array company values
            if (is_array($user_info['company']) || is_object($user_info['company'])) {
                $company_data = (array) $user_info['company'];
                // If it's an array, try to extract meaningful fields
                if (isset($company_data['name'])) {
                    $company_fields['company'] = $company_data['name'];
                } elseif (isset($company_data['companyName'])) {
                    $company_fields['company'] = $company_data['companyName'];
                } elseif (isset($company_data[0])) {
                    // If it's a simple array with one element
                    $company_fields['company'] = $company_data[0];
                } else {
                    // Fallback: show first non-empty value
                    foreach ($company_data as $key => $value) {
                        if (!empty($value) && !is_array($value) && !is_object($value)) {
                            $company_fields['company'] = $value;
                            break;
                        }
                    }
                }
            } else {
                $company_fields['company'] = $user_info['company'];
            }
        }
        
        
        ?>
        <div style="background: #f7f7f7; border-left: 4px solid #2271b1; padding: 12px 16px; margin-bottom: 15px; border-radius: 2px;">
            <h3 style="margin: 0 0 12px 0; font-size: 15px; color: #1d2327;">Livraria Account</h3>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <?php if (isset($contact_fields['name']) || isset($contact_fields['email'])): ?>
                    <div style="display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap;">
                        <?php if (isset($contact_fields['name'])): ?>
                            <span style="font-size: 18px; font-weight: 600; color: #1d2327; line-height: 1.3;"><?php echo esc_html($contact_fields['name']); ?></span>
                        <?php endif; ?>
                        <?php if (isset($contact_fields['email'])): ?>
                            <span style="font-size: 14px; color: #646970;">
                                <?php echo esc_html($contact_fields['email']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($company_fields['company'])): ?>
                    <div style="font-size: 13px; color: #8c8f94; margin-top: -4px;">
                        <?php echo esc_html($company_fields['company']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($account_fields) || isset($contact_fields['phone'])): ?>
                    <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-top: 4px; font-size: 12px; color: #646970;">
                        <?php if (isset($account_fields['status'])): ?>
                            <span style="display: flex; align-items: center; gap: 4px;">
                                <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background-color: <?php echo esc_attr($account_fields['status']['color']); ?>;"></span>
                                <?php echo esc_html($account_fields['status']['value']); ?>
                            </span>
                        <?php endif; ?>
                        <?php if (isset($account_fields['accountType'])): ?>
                            <span style="display: flex; align-items: center; gap: 4px;">
                                <span style="font-size: 11px;">🏢</span>
                                <?php echo esc_html($account_fields['accountType']); ?>
                            </span>
                        <?php endif; ?>
                        <?php if (isset($contact_fields['phone'])): ?>
                            <span style="display: flex; align-items: center; gap: 4px;">
                                <span style="font-size: 11px;">📞</span>
                                <?php echo esc_html($contact_fields['phone']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($sender_profile): ?>
                <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #ddd;">
                    <?php $this->render_sender_profiles($sender_profile); ?>
                </div>
            <?php endif; ?>
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
        <div>
            <h4 style="margin: 0 0 12px 0; font-size: 13px; color: #646970; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Sender Profile<?php echo count($profiles) > 1 ? 's' : ''; ?></h4>
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;">
            <?php
            foreach ($profiles as $index => $profile) {
                $is_multiple = count($profiles) > 1;
                $profile_array = (array) $profile;
                
                // Extract profile name for header
                $profile_name = 'Profile ' . ($index + 1); // Default fallback
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
                
                $profile_id = 'livraria-profile-' . $index;
                ?>
                <div class="livraria-profile-card">
                    <button type="button" onclick="openLivrariaProfileModal('<?php echo $profile_id; ?>')" style="width: 100%; height: 80px; padding: 14px; background: #fff; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; text-align: left; transition: all 0.2s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.05); display: flex; align-items: center;" onmouseover="this.style.borderColor='#2271b1'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'" onmouseout="this.style.borderColor='#ddd'; this.style.boxShadow='0 1px 2px rgba(0,0,0,0.05)'">
                        <span style="font-size: 13px; color: #1d2327; font-weight: 600; letter-spacing: 0.2px; line-height: 1.4;"><?php echo esc_html($profile_name); ?></span>
                    </button>
                    
                    <!-- Modal for this profile -->
                    <div id="<?php echo $profile_id; ?>-modal" class="livraria-profile-modal-overlay" style="display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); overflow: auto;">
                        <div style="background-color: #fff; margin: 5% auto; padding: 0; border-radius: 8px; width: 90%; max-width: 700px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); max-height: 85vh; overflow: hidden; display: flex; flex-direction: column;">
                            <div style="padding: 20px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; background: #f7f7f7;">
                                <h3 style="margin: 0; font-size: 16px; color: #1d2327; font-weight: 600;"><?php echo esc_html($profile_name); ?></h3>
                                <button type="button" onclick="closeLivrariaProfileModal('<?php echo $profile_id; ?>')" style="background: none; border: none; font-size: 24px; color: #646970; cursor: pointer; padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 4px; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='#e5e5e5'" onmouseout="this.style.backgroundColor='transparent'">&times;</button>
                            </div>
                            <div style="padding: 20px; overflow-y: auto; flex: 1;">
                                <?php $this->render_sender_profile($profile); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php
            }
            ?>
            </div>
        </div>
        
        <script>
        function openLivrariaProfileModal(profileId) {
            var modal = document.getElementById(profileId + '-modal');
            if (modal) {
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden';
            }
        }
        
        function closeLivrariaProfileModal(profileId) {
            var modal = document.getElementById(profileId + '-modal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }
        
        // Close modal when clicking outside of it
        window.onclick = function(event) {
            if (event.target.classList.contains('livraria-profile-modal-overlay') || (event.target.id && event.target.id.endsWith('-modal'))) {
                var modals = document.querySelectorAll('[id$="-modal"]');
                modals.forEach(function(modal) {
                    if (modal.style.display === 'block') {
                        modal.style.display = 'none';
                        document.body.style.overflow = 'auto';
                    }
                });
            }
        }
        </script>
        <?php
    }
    
    /**
     * Render a single sender profile organized into logical subsections
     * 
     * @param mixed $sender_profile Sender profile data (single profile object)
     */
    private function render_sender_profile($sender_profile) {
        if (!is_array($sender_profile) && !is_object($sender_profile)) {
            return;
        }
        
        $sender_profile = (array) $sender_profile;
        
        // Extract address data (can be nested in 'address' key or at top level)
        $address_data = array();
        if (isset($sender_profile['address']) && (is_array($sender_profile['address']) || is_object($sender_profile['address']))) {
            $address_data = (array) $sender_profile['address'];
        } elseif (isset($sender_profile['street']) || isset($sender_profile['city']) || isset($sender_profile['country'])) {
            // Address fields at top level
            $address_data = $sender_profile;
        }
        
        // Extract contact information
        $contact_fields = array();
        if (isset($sender_profile['name']) && !empty($sender_profile['name'])) {
            $contact_fields['name'] = $sender_profile['name'];
        }
        if (isset($sender_profile['email']) && !empty($sender_profile['email'])) {
            $contact_fields['email'] = $sender_profile['email'];
        }
        // Phone (combine country code + NSN)
        $phone = '';
        if (isset($sender_profile['phoneCountryCode']) || isset($sender_profile['phoneNSN'])) {
            $country_code = isset($sender_profile['phoneCountryCode']) ? $sender_profile['phoneCountryCode'] : '';
            $nsn = isset($sender_profile['phoneNSN']) ? $sender_profile['phoneNSN'] : '';
            $phone = trim($country_code . ' ' . $nsn);
        } elseif (isset($sender_profile['phone']) && !empty($sender_profile['phone'])) {
            $phone = $sender_profile['phone'];
        }
        if (!empty($phone)) {
            $contact_fields['phone'] = $phone;
        }
        
        // Extract company information
        $company_fields = array();
        if (isset($sender_profile['isCompany']) && $sender_profile['isCompany']) {
            $company_fields['isCompany'] = true;
        }
        if (isset($sender_profile['company']) && !empty($sender_profile['company'])) {
            $company_fields['company'] = $sender_profile['company'];
        }
        if (isset($sender_profile['companyName']) && !empty($sender_profile['companyName'])) {
            $company_fields['companyName'] = $sender_profile['companyName'];
        }
        if (isset($sender_profile['cui']) && !empty($sender_profile['cui'])) {
            $company_fields['cui'] = $sender_profile['cui'];
        }
        
        // Extract financial information
        $financial_fields = array();
        if (isset($sender_profile['codIban']) && !empty($sender_profile['codIban'])) {
            $financial_fields['codIban'] = $sender_profile['codIban'];
        }
        
        // Extract additional fields (anything not in the above categories)
        $hidden_fields = array('id', 'createdAt', 'created_at', 'updatedAt', 'updated_at', 'deletedAt', 'deleted_at', 'userId', 'user_id', 'name', 'email', 'phone', 'phoneCountryCode', 'phoneNSN', 'isCompany', 'company', 'companyName', 'cui', 'codIban', 'address', 'street', 'streetNumber', 'city', 'county', 'postcode', 'country', 'block', 'staircase', 'floor', 'apartment');
        $additional_fields = array();
        foreach ($sender_profile as $field_key => $value) {
            if (in_array(strtolower($field_key), array_map('strtolower', $hidden_fields))) {
                continue;
            }
            if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                continue; // Skip nested objects for now
            }
            $additional_fields[$field_key] = $value;
        }
        
        // Render subsections
        ?>
        <div style="width: 100%;">
            <?php if (!empty($contact_fields)): ?>
                <div style="margin-bottom: 20px;">
                    <h4 style="margin: 0 0 10px 0; font-size: 13px; color: #646970; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Contact Information</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px 25px;">
                        <?php if (isset($contact_fields['name'])): ?>
                            <div>
                                <strong style="display: block; color: #646970; font-size: 11px; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px;">Name</strong>
                                <span style="color: #1d2327; font-size: 14px; line-height: 1.5;"><?php echo esc_html($contact_fields['name']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($contact_fields['email'])): ?>
                            <div>
                                <strong style="display: block; color: #646970; font-size: 11px; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px;">Email</strong>
                                <span style="color: #1d2327; font-size: 14px; line-height: 1.5;"><?php echo esc_html($contact_fields['email']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($contact_fields['phone'])): ?>
                            <div>
                                <strong style="display: block; color: #646970; font-size: 11px; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px;">Phone</strong>
                                <span style="color: #1d2327; font-size: 14px; line-height: 1.5;"><?php echo esc_html($contact_fields['phone']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($company_fields)): ?>
                <div style="margin-bottom: 12px; padding-top: 12px; border-top: 1px solid #ddd;">
                    <h4 style="margin: 0 0 8px 0; font-size: 11px; color: #646970; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Company Information</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px 20px;">
                        <?php if (isset($company_fields['companyName'])): ?>
                            <div>
                                <strong style="display: block; color: #646970; font-size: 11px; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px;">Company Name</strong>
                                <span style="color: #1d2327; font-size: 14px; line-height: 1.5;"><?php echo esc_html($company_fields['companyName']); ?></span>
                            </div>
                        <?php elseif (isset($company_fields['company'])): ?>
                            <div>
                                <strong style="display: block; color: #646970; font-size: 11px; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px;">Company</strong>
                                <span style="color: #1d2327; font-size: 14px; line-height: 1.5;"><?php echo esc_html($company_fields['company']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($company_fields['cui'])): ?>
                            <div>
                                <strong style="display: block; color: #646970; font-size: 11px; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px;">CUI</strong>
                                <span style="color: #1d2327; font-size: 14px; line-height: 1.5;"><?php echo esc_html($company_fields['cui']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($company_fields['isCompany'])): ?>
                            <div>
                                <strong style="display: block; color: #646970; font-size: 11px; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px;">Type</strong>
                                <span style="color: #1d2327; font-size: 14px; line-height: 1.5;">Company Account</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($address_data)): ?>
                <div style="margin-bottom: 12px; padding-top: 12px; border-top: 1px solid #ddd;">
                    <h4 style="margin: 0 0 8px 0; font-size: 11px; color: #646970; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Address</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px 20px;">
                        <?php
                        // Street and street number
                        $street_line = '';
                        if (isset($address_data['street']) && !empty($address_data['street'])) {
                            $street_line = $address_data['street'];
                            if (isset($address_data['streetNumber']) && !empty($address_data['streetNumber'])) {
                                $street_line .= ' ' . $address_data['streetNumber'];
                            }
                        }
                        if (!empty($street_line)): ?>
                            <div style="grid-column: 1 / -1;">
                                <strong style="display: block; color: #646970; font-size: 11px; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px;">Street</strong>
                                <span style="color: #1d2327; font-size: 14px; line-height: 1.5;"><?php echo esc_html($street_line); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($address_data['city']) && !empty($address_data['city'])): ?>
                            <div>
                                <strong style="display: block; color: #646970; font-size: 11px; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px;">City</strong>
                                <span style="color: #1d2327; font-size: 14px; line-height: 1.5;"><?php echo esc_html($address_data['city']); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($address_data['county']) && !empty($address_data['county'])): ?>
                            <div>
                                <strong style="display: block; color: #646970; font-size: 11px; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px;">County</strong>
                                <span style="color: #1d2327; font-size: 14px; line-height: 1.5;"><?php echo esc_html($address_data['county']); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($address_data['postcode']) && !empty($address_data['postcode'])): ?>
                            <div>
                                <strong style="display: block; color: #646970; font-size: 11px; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px;">Postcode</strong>
                                <span style="color: #1d2327; font-size: 14px; line-height: 1.5;"><?php echo esc_html($address_data['postcode']); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($address_data['country']) && !empty($address_data['country'])): ?>
                            <div>
                                <strong style="display: block; color: #646970; font-size: 11px; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px;">Country</strong>
                                <span style="color: #1d2327; font-size: 14px; line-height: 1.5;"><?php echo esc_html($address_data['country']); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php
                        // Additional address fields (block, staircase, floor, apartment)
                        $additional_address = array();
                        if (isset($address_data['block']) && !empty($address_data['block'])) {
                            $additional_address[] = 'Block: ' . $address_data['block'];
                        }
                        if (isset($address_data['staircase']) && !empty($address_data['staircase'])) {
                            $additional_address[] = 'Staircase: ' . $address_data['staircase'];
                        }
                        if (isset($address_data['floor']) && !empty($address_data['floor'])) {
                            $additional_address[] = 'Floor: ' . $address_data['floor'];
                        }
                        if (isset($address_data['apartment']) && !empty($address_data['apartment'])) {
                            $additional_address[] = 'Apartment: ' . $address_data['apartment'];
                        }
                        if (!empty($additional_address)): ?>
                            <div style="grid-column: 1 / -1;">
                                <strong style="display: block; color: #646970; font-size: 11px; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px;">Additional Details</strong>
                                <span style="color: #1d2327; font-size: 14px; line-height: 1.5;"><?php echo esc_html(implode(', ', $additional_address)); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($financial_fields)): ?>
                <div style="margin-bottom: 12px; padding-top: 12px; border-top: 1px solid #ddd;">
                    <h4 style="margin: 0 0 8px 0; font-size: 11px; color: #646970; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Financial Information</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px 20px;">
                        <?php if (isset($financial_fields['codIban'])): ?>
                            <div>
                                <strong style="display: block; color: #646970; font-size: 11px; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px;">COD IBAN</strong>
                                <span style="color: #1d2327; font-size: 14px; line-height: 1.5; font-family: monospace;"><?php echo esc_html($financial_fields['codIban']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($additional_fields)): ?>
                <div style="margin-bottom: 12px; padding-top: 12px; border-top: 1px solid #ddd;">
                    <h4 style="margin: 0 0 8px 0; font-size: 11px; color: #646970; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Additional Information</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px 20px;">
                        <?php foreach ($additional_fields as $field_key => $value): ?>
                            <?php
                            // Convert field key to readable label
                            // Handle camelCase: billingName -> Billing Name
                            // Handle snake_case: billing_name -> Billing Name
                            // Handle kebab-case: billing-name -> Billing Name
                            $field_label = $field_key;
                            
                            // First, replace underscores and hyphens with spaces
                            $field_label = str_replace(array('_', '-'), ' ', $field_label);
                            
                            // Split camelCase: insert space before uppercase letters (but not at the start)
                            $field_label = preg_replace('/([a-z])([A-Z])/', '$1 $2', $field_label);
                            
                            // Convert to title case
                            $field_label = ucwords(strtolower($field_label));
                            
                            if (is_bool($value)) {
                                $display_value = $value ? 'Yes' : 'No';
                            } else {
                                $display_value = $value;
                            }
                            ?>
                            <div>
                                <strong style="display: block; color: #646970; font-size: 11px; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px;"><?php echo esc_html($field_label); ?></strong>
                                <span style="color: #1d2327; font-size: 14px; line-height: 1.5;"><?php echo esc_html($display_value); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

