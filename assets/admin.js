jQuery(document).ready(function($) {
    
    // Test network connectivity
    $('#test-connectivity').click(function(e) {
        e.preventDefault();
        
        var btn = $(this);
        var originalText = btn.text();
        var resultDiv = $('#api-test-result-connectivity');
        
        // The URL field exists only in development mode; otherwise the server
        // uses the production API and ignores api_url.
        var apiUrl = $('#login-api-url').val() || '';
        var shownUrl = apiUrl || $('#livraria-debug-modal').find('td[data-api-url]').attr('data-api-url') || 'the Livraria API';
        
        btn.text('Testing...').prop('disabled', true);
        resultDiv.html('<div class="notice notice-info"><p>Testing network connectivity to ' + $('<span>').text(shownUrl).html() + '...</p></div>');
        
        var requestData = {
            action: 'livraria_test_connectivity',
            nonce: livrariaAdmin.nonce
        };
        if (apiUrl) {
            requestData.api_url = apiUrl;
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: requestData,
            success: function(response) {
                console.log('Livraria Debug: Connectivity test response:', response);
                
                if (response.success) {
                    resultDiv.html('<div class="notice notice-success"><p><strong>✅ Connectivity OK:</strong> ' + response.data.message + '</p></div>');
                } else {
                    resultDiv.html('<div class="notice notice-error"><p><strong>❌ Connectivity Failed:</strong> ' + response.data + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                console.log('Livraria Debug: Connectivity test AJAX error:', {xhr: xhr, status: status, error: error});
                resultDiv.html('<div class="notice notice-error"><p><strong>AJAX Error:</strong> ' + error + '</p></div>');
            },
            complete: function() {
                btn.text(originalText).prop('disabled', false);
            }
        });
    });
    
    // Test API connection
    $('#test-api-connection').click(function(e) {
        e.preventDefault();
        
        var btn = $(this);
        var originalText = btn.text();
        var resultDiv = $('#api-test-result-auth');
        
        // Get values from form inputs or from debug modal data attributes
        // The URL field exists only in development mode; otherwise the server
        // uses the production API and ignores api_url.
        var apiUrl = $('#login-api-url').val() || '';
        var username = $('input[name="courier_api_username"]').val();
        var password = $('input[name="courier_api_password"]').val();
        
        // If not found in form inputs, try reading from debug modal
        if (!username) {
            username = $('#livraria-debug-modal').find('td[data-api-username]').attr('data-api-username');
        }
        // No password typed while connected: the server tests the stored session
        var useStored = !password &&
            $('#livraria-debug-modal').find('td[data-session]').attr('data-session') === '1';
        
        // Validate values (check for empty strings, null, undefined, or 'Not set')
        if (!username || username === '' || username === 'Not set' || 
            (!password && !useStored)) {
            resultDiv.html('<div class="notice notice-error"><p>Please enter Username and Password before testing.</p></div>');
            return;
        }
        
        btn.text('Testing...').prop('disabled', true);
        resultDiv.html('<div class="notice notice-info"><p>Testing authentication and API access...</p></div>');
        $('#test-steps').show();
        $('#step-login .status').html('<span style="color: orange;">⏳ In progress</span>');
        $('#step-api .status').html('<span style="color: #ccc;">⏸ Waiting</span>');
        
        var requestData = {
            action: 'livraria_test_courier_api_connection',
            username: username,
            password: useStored ? '' : password,
            nonce: livrariaAdmin.nonce
        };
        if (useStored) {
            requestData.use_session = '1';
        }
        if (apiUrl) {
            requestData.api_url = apiUrl;
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: requestData,
            success: function(response) {
                console.log('Livraria Debug: AJAX response received:', response);
                
                if (response.success) {
                    $('#step-login .status').html('<span style="color: green;">✅ Success</span>');
                    $('#step-api .status').html('<span style="color: green;">✅ Success</span>');
                    
                    var message = response.data.message;
                    if (response.data.token_expires_at) {
                        message += '<br><small><strong>Token expires:</strong> ' + response.data.token_expires_at + '</small>';
                    }
                    resultDiv.html('<div class="notice notice-success"><p>' + message + '</p></div>');
                } else {
                    // Determine which step failed based on error message
                    if (response.data.indexOf('Login successful but API access failed') !== -1) {
                        // Login worked but API test failed
                        $('#step-login .status').html('<span style="color: green;">✅ Success</span>');
                        $('#step-api .status').html('<span style="color: red;">❌ Failed</span>');
                        resultDiv.html('<div class="notice notice-warning"><p><strong>Partial Success:</strong> ' + response.data + '</p></div>');
                    } else if (response.data.indexOf('Login successful but API test failed') !== -1) {
                        // Login worked but API test had an exception
                        $('#step-login .status').html('<span style="color: green;">✅ Success</span>');
                        $('#step-api .status').html('<span style="color: red;">❌ Failed</span>');
                        resultDiv.html('<div class="notice notice-warning"><p><strong>Partial Success:</strong> ' + response.data + '</p></div>');
                    } else if (response.data.indexOf('Login failed') !== -1 && response.data.indexOf('Login successful') === -1) {
                        // Actual login failure
                        $('#step-login .status').html('<span style="color: red;">❌ Failed</span>');
                        $('#step-api .status').html('<span style="color: #ccc;">⏸ Skipped</span>');
                        resultDiv.html('<div class="notice notice-error"><p><strong>Test Failed:</strong> ' + response.data + '</p></div>');
                    } else {
                        // Unknown error
                        $('#step-login .status').html('<span style="color: red;">❌ Failed</span>');
                        $('#step-api .status').html('<span style="color: #ccc;">⏸ Skipped</span>');
                        resultDiv.html('<div class="notice notice-error"><p><strong>Test Failed:</strong> ' + response.data + '</p></div>');
                    }
                }
            },
            error: function(xhr, status, error) {
                console.log('Livraria Debug: AJAX error:', {xhr: xhr, status: status, error: error});
                resultDiv.html('<div class="notice notice-error"><p>AJAX request failed: ' + error + '</p></div>');
            },
            complete: function() {
                btn.text(originalText).prop('disabled', false);
            }
        });
    });
    
    // Create expedition button in order meta box
    $(document).on('click', '#create-expedition-btn', function() {
        var btn = $(this);
        var orderId = btn.data('order-id') || livrariaAdmin.orderId;
        var nonce = $('#courier_expedition_nonce_field').val();
        
        if (!orderId || !nonce) {
            alert('Missing required data for expedition creation');
            return;
        }
        
        btn.prop('disabled', true);
        $('#expedition-loading').show();
        $('#expedition-result').html('');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'livraria_create_expedition',
                order_id: orderId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#expedition-result').html('<div class="notice notice-success"><p>Expedition created successfully!</p></div>');
                    // Reload the page to show updated expedition info
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $('#expedition-result').html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                    btn.prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                $('#expedition-result').html('<div class="notice notice-error"><p>AJAX error: ' + error + '</p></div>');
                btn.prop('disabled', false);
            },
            complete: function() {
                $('#expedition-loading').hide();
            }
        });
    });
    
    // Update expedition status button
    $(document).on('click', '#update-expedition-status-btn', function() {
        var btn = $(this);
        var orderId = btn.data('order-id') || livrariaAdmin.orderId;
        var nonce = $('#courier_expedition_nonce_field').val();
        
        btn.prop('disabled', true);
        var originalText = btn.text();
        btn.text('Updating...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'update_expedition_status',
                order_id: orderId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#expedition-status-display').html('<strong>Status:</strong> ' + response.data.status);
                    btn.text('✓ Updated');
                    setTimeout(function() {
                        btn.text(originalText);
                    }, 3000);
                } else {
                    alert('Failed to update status: ' + response.data);
                }
            },
            error: function() {
                alert('AJAX error occurred while updating status');
            },
            complete: function() {
                btn.prop('disabled', false);
                if (btn.text() === 'Updating...') {
                    btn.text(originalText);
                }
            }
        });
    });
    
    // Validate sender address JSON
    $('textarea[name="courier_default_sender_address"]').blur(function() {
        var value = $(this).val().trim();
        var feedback = $('#sender-address-feedback');
        
        if (!feedback.length) {
            $(this).after('<div id="sender-address-feedback"></div>');
            feedback = $('#sender-address-feedback');
        }
        
        if (!value) {
            feedback.html('').hide();
            return;
        }
        
        try {
            var address = JSON.parse(value);
            var requiredFields = ['country', 'county', 'city', 'postcode', 'street'];
            var missing = [];
            
            requiredFields.forEach(function(field) {
                if (!address[field]) {
                    missing.push(field);
                }
            });
            
            if (missing.length > 0) {
                feedback.html('<p style="color: orange;">⚠️ Missing required fields: ' + missing.join(', ') + '</p>').show();
            } else {
                feedback.html('<p style="color: green;">✓ Valid address format</p>').show();
            }
        } catch (e) {
            feedback.html('<p style="color: red;">❌ Invalid JSON format</p>').show();
        }
    });
    
    // Auto-save settings when API URL or token changes
    var saveTimeout;
    $('input[name="courier_api_base_url"], input[name="courier_api_token"]').on('input', function() {
        clearTimeout(saveTimeout);
        saveTimeout = setTimeout(function() {
            // Optional: Auto-save functionality
            // You could implement this to save settings without clicking the save button
        }, 1000);
    });
    
    // Show/hide advanced settings
    $('#show-advanced-settings').click(function(e) {
        e.preventDefault();
        var advancedRows = $('.advanced-setting-row');
        var isVisible = advancedRows.first().is(':visible');
        
        if (isVisible) {
            advancedRows.hide();
            $(this).text('Show Advanced Settings');
        } else {
            advancedRows.show();
            $(this).text('Hide Advanced Settings');
        }
    });
    
    // Preview quote calculation (if implemented)
    $('#preview-quote-btn').click(function(e) {
        e.preventDefault();
        
        var sampleData = {
            weight: $('#sample-weight').val() || 1,
            dimensions: {
                width: $('#sample-width').val() || 10,
                height: $('#sample-height').val() || 10,
                length: $('#sample-length').val() || 10
            },
            destination: {
                city: $('#sample-city').val() || 'Bucharest',
                county: $('#sample-county').val() || 'Bucharest'
            }
        };
        
        var btn = $(this);
        var originalText = btn.text();
        btn.text('Calculating...').prop('disabled', true);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'preview_courier_quote',
                sample_data: sampleData,
                nonce: livrariaAdmin.nonce
            },
            success: function(response) {
                if (response.success && response.data.quotes) {
                    var html = '<h4>Available Quotes:</h4><ul>';
                    response.data.quotes.forEach(function(quote) {
                        html += '<li>' + quote.courierName + ': ' + quote.amount + ' ' + (quote.currency || 'RON') + '</li>';
                    });
                    html += '</ul>';
                    $('#quote-preview-result').html(html);
                } else {
                    $('#quote-preview-result').html('<p style="color: red;">Failed to get quotes: ' + (response.data || 'Unknown error') + '</p>');
                }
            },
            error: function() {
                $('#quote-preview-result').html('<p style="color: red;">AJAX error occurred</p>');
            },
            complete: function() {
                btn.text(originalText).prop('disabled', false);
            }
        });
    });
    
    // Bulk expedition creation for orders
    $('#bulk-create-expeditions').click(function(e) {
        e.preventDefault();
        
        var selectedOrders = $('.order-checkbox:checked');
        if (selectedOrders.length === 0) {
            alert('Please select at least one order');
            return;
        }
        
        var orderIds = [];
        selectedOrders.each(function() {
            orderIds.push($(this).val());
        });
        
        if (!confirm('Create expeditions for ' + orderIds.length + ' selected orders?')) {
            return;
        }
        
        var btn = $(this);
        var originalText = btn.text();
        btn.text('Creating...').prop('disabled', true);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bulk_create_expeditions',
                order_ids: orderIds,
                nonce: livrariaAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('Bulk operation completed. Created: ' + response.data.created + ', Failed: ' + response.data.failed);
                    location.reload();
                } else {
                    alert('Bulk operation failed: ' + response.data);
                }
            },
            error: function() {
                alert('AJAX error occurred during bulk operation');
            },
            complete: function() {
                btn.text(originalText).prop('disabled', false);
            }
        });
    });
    
    // Copy AWB number to clipboard
    $(document).on('click', '.copy-awb', function(e) {
        e.preventDefault();
        
        var awbNumber = $(this).data('awb');
        if (!awbNumber) return;
        
        // Create temporary textarea for copying
        var temp = $('<textarea>');
        $('body').append(temp);
        temp.val(awbNumber).select();
        document.execCommand('copy');
        temp.remove();
        
        // Show feedback
        var originalText = $(this).text();
        $(this).text('Copied!').addClass('copied');
        
        setTimeout(() => {
            $(this).text(originalText).removeClass('copied');
        }, 2000);
    });
});

// Login/logout, debug modal, auto-create checkbox handlers
// (moved from class-admin-page.php inline script — data provided via wp_localize_script)
jQuery(document).ready(function($) {
    $('#livraria-logout-btn').on('click', function() {
        if (!confirm('Are you sure you want to logout?')) {
            return;
        }
        var btn = $(this);
        btn.prop('disabled', true).text('Logging out...');
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: { action: 'livraria_logout', nonce: livrariaAdmin.nonce },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Logout failed: ' + (response.data || 'Unknown error'));
                    btn.prop('disabled', false).text('Logout');
                }
            },
            error: function() {
                alert('AJAX error during logout');
                btn.prop('disabled', false).text('Logout');
            }
        });
    });

    $('#livraria-login-btn').on('click', function() {
        // Only in development mode is there a URL field; otherwise the server
        // uses the production API.
        var apiUrl = $('#login-api-url').val() || '';
        var username = $('#login-username').val() || $('input[name="courier_api_username"]').val();
        var password = $('#login-password').val() || $('input[name="courier_api_password"]').val();
        if (!username || !password) {
            alert('Please fill in Username and Password before logging in.');
            return;
        }
        var btn = $(this);
        btn.prop('disabled', true).text('Logging in...');
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: (function() {
                var loginData = {
                    action: 'livraria_login',
                    username: username,
                    password: password,
                    nonce: livrariaAdmin.nonce
                };
                if (apiUrl) {
                    loginData.api_url = apiUrl;
                }
                return loginData;
            })(),
            success: function(response) {
                if (response.success) {
                    // e.g. the session could not be stored encrypted
                    if (response.data && response.data.message && response.data.message !== 'Login successful') {
                        alert(response.data.message);
                    }
                    location.reload();
                } else {
                    alert('Login failed: ' + (response.data || 'Unknown error'));
                    btn.prop('disabled', false).text('Login');
                }
            },
            error: function() {
                alert('AJAX error during login');
                btn.prop('disabled', false).text('Login');
            }
        });
    });

    $('#livraria-debug-btn').on('click', function() {
        $('#livraria-debug-modal').css('display', 'block');
        $('body').css('overflow', 'hidden');
    });
    $('#livraria-debug-close').on('click', function() {
        $('#livraria-debug-modal').css('display', 'none');
        $('body').css('overflow', 'auto');
    });
    $('#livraria-debug-modal').on('click', function(e) {
        if ($(e.target).attr('id') === 'livraria-debug-modal') {
            $(this).css('display', 'none');
            $('body').css('overflow', 'auto');
        }
    });

    $('#courier_auto_create_account').on('change', function() {
        var checkbox = $(this);
        var isChecked = checkbox.is(':checked');
        var originalValue = !isChecked;
        checkbox.prop('disabled', true);
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'livraria_update_option',
                option_name: 'courier_auto_create',
                option_value: isChecked ? '1' : '0',
                nonce: livrariaAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    var feedback = $('#livraria-auto-save-feedback');
                    feedback.css('opacity', '1');
                    setTimeout(function() { feedback.css('opacity', '0'); }, 2000);
                } else {
                    checkbox.prop('checked', originalValue);
                    alert('Failed to save setting: ' + (response.data || 'Unknown error'));
                }
                checkbox.prop('disabled', false);
            },
            error: function() {
                checkbox.prop('checked', originalValue);
                alert('AJAX error while saving setting');
                checkbox.prop('disabled', false);
            }
        });
    });
});

// Default sender profile dropdown auto-save
// (moved from class-admin-page.php inline script — defaultSenderProfileId provided via wp_localize_script)
jQuery(document).ready(function($) {
    $('#livraria-default-sender-profile').on('change', function() {
        var select = $(this);
        var profileId = select.val();
        var originalValue = select.data('original-value') || livrariaAdmin.defaultSenderProfileId;
        select.prop('disabled', true);
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'livraria_update_option',
                option_name: 'livraria_default_sender_profile_id',
                option_value: profileId,
                nonce: livrariaAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    select.data('original-value', profileId);
                    var feedback = $('#livraria-default-profile-save-feedback');
                    feedback.css('opacity', '1');
                    setTimeout(function() { feedback.css('opacity', '0'); }, 2000);
                } else {
                    select.val(originalValue);
                    alert('Failed to save default profile: ' + (response.data || 'Unknown error'));
                }
                select.prop('disabled', false);
            },
            error: function() {
                select.val(originalValue);
                alert('AJAX error while saving default profile');
                select.prop('disabled', false);
            }
        });
    });
});

// Sender profile modal handlers
// (moved from class-admin-page.php inline script)
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

window.onclick = function(event) {
    if (event.target.classList.contains('livraria-profile-modal-overlay') || (event.target.id && event.target.id.endsWith('-modal'))) {
        document.querySelectorAll('[id$="-modal"]').forEach(function(modal) {
            if (modal.style.display === 'block') {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        });
    }
};