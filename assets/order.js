// Order meta box JS for Livraria Shipping for WooCommerce
// Dynamic data (orderId, autoCreateEnabled, etc.) provided via wp_localize_script as livrariaOrder.
jQuery(document).ready(function($) {

    // Server text (the backend's own words) goes in as text, never as HTML
    function esc(value) {
        return $('<div>').text(value === undefined || value === null ? '' : String(value)).html();
    }

    var RELOAD_BUTTON = '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>';

    // The quote an unattended run ships with: the server's pick (cheapest
    // usable), never a disabled one; null when there is none
    function autoQuote(data) {
        if (!data || !data.autoQuoteId || !data.quotes) { return null; }
        for (var i = 0; i < data.quotes.length; i++) {
            if (data.quotes[i].id === data.autoQuoteId && data.quotes[i].usable) { return data.quotes[i]; }
        }
        return null;
    }

    // "Add email" / "Add phone": a value written onto the WooCommerce order
    // (billing email; the phone field the plugin reads first), then a new quote
    var FILL_INS = {
        email: { type: 'email', placeholder: 'name@example.com', label: 'Add email' },
        phone: { type: 'tel', placeholder: '07XX XXX XXX', label: 'Add phone' },
        postcode: { type: 'text', placeholder: '400001', label: 'Add postcode' },
        street: { type: 'text', placeholder: 'Strada, număr, bloc', label: 'Add street' },
        city: { type: 'text', placeholder: 'Oraș', label: 'Add city' }
    };

    function fillInControl(field) {
        var f = FILL_INS[field];
        if (!f) { return ''; }
        return '<div class="livraria-fill-in" data-field="' + field + '" style="margin-top: 6px;">' +
            '<input type="' + f.type + '" class="livraria-fill-in-value" placeholder="' + esc(f.placeholder) + '" style="width: 60%;"> ' +
            '<button type="button" class="button livraria-fill-in-save">' + f.label + '</button>' +
            '<span class="livraria-fill-in-result" style="display: block; font-size: 12px;"></span>' +
            '</div>';
    }

    // An error line, with the fill-in the server named (data.fix) under it
    function errorHtml(data, fallback) {
        var html = '<p style="color:red;">Error: ' + esc(errorText(data, fallback)) + '</p>';
        if (data && FILL_INS[data.fix]) {
            html += fillInControl(data.fix);
        }
        return html;
    }

    // The message of a wp_send_json_error() payload: a string, or { message }
    function errorText(data, fallback) {
        if (typeof data === 'string' && data !== '') { return data; }
        if (data && typeof data.message === 'string' && data.message !== '') { return data.message; }
        return fallback;
    }

    // Delete expedition association
    $('#livraria-delete-expedition-btn').on('click', function() {
        var confirmed = confirm(
            'Remove expedition association?\n\n' +
            '⚠️ WARNING: This only removes the local record from this order. ' +
            'It does NOT cancel the AWB with the courier. ' +
            'You must cancel the shipment manually in the Livraria dashboard.\n\n' +
            'Continue?'
        );
        if (!confirmed) return;

        var btn = $(this);
        btn.prop('disabled', true).text('Removing...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'livraria_delete_expedition',
                order_id: btn.data('order-id'),
                nonce: btn.data('nonce')
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Failed to remove expedition: ' + errorText(response.data, 'Unknown error'));
                    btn.prop('disabled', false).text('Remove expedition association');
                }
            },
            error: function() {
                alert('AJAX error while removing expedition');
                btn.prop('disabled', false).text('Remove expedition association');
            }
        });
    });
    var packageCount = 1;
    var quoteRequestId = null;
    var selectedQuoteId = null;
    var quotesData = [];
    var autoCreateInProgress = false;

    var autoCreateEnabled = livrariaOrder.autoCreateEnabled;
    var expeditionExists  = livrariaOrder.expeditionExists;
    var orderId           = livrariaOrder.orderId;
    var shouldAutoCreate  = livrariaOrder.shouldAutoCreate;

    if (autoCreateEnabled && !expeditionExists && shouldAutoCreate) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'clear_auto_create_flag',
                order_id: orderId,
                nonce: $('#courier_expedition_nonce_field').val()
            }
        });
        runAutoCreateExpedition();
    }

    function runAutoCreateExpedition() {
        if (autoCreateInProgress) return;

        autoCreateInProgress = true;
        window.livrariaPreventUnload = true;

        var $overlay = $('<div id="livraria-auto-create-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:999999;display:flex;align-items:center;justify-content:center;flex-direction:column;color:white;font-size:16px;"><div style="background:white;color:#333;padding:30px;border-radius:8px;max-width:500px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.3);"><h3 style="margin-top:0;color:#2271b1;">Creating Expedition...</h3><p>Please wait while we create the shipping expedition for this order.</p><div id="livraria-progress" style="margin:20px 0;"><div style="background:#f0f0f0;height:24px;border-radius:12px;overflow:hidden;border:2px solid #ddd;"><div id="livraria-progress-bar" style="background:#2271b1;height:100%;width:0%;transition:width 0.3s;display:flex;align-items:center;justify-content:center;color:white;font-size:12px;font-weight:bold;"></div></div></div><p id="livraria-status-text" style="margin:10px 0;font-size:14px;color:#666;">Getting quotes...</p></div></div>');
        $('body').append($overlay);

        var updateProgress = function(percent, text) {
            $('#livraria-progress-bar').css('width', percent + '%').text(percent + '%');
            $('#livraria-status-text').text(text);
        };

        updateProgress(20, 'Getting shipping quotes...');
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'livraria_get_quotes_for_order',
                auto: 1,
                order_id: orderId,
                nonce: $('#courier_expedition_nonce_field').val(),
                expedition_data: {}
            },
            success: function(response) {
                if (!response.success || !response.data.quotes || response.data.quotes.length === 0) {
                    var errorMsg = 'Could not get quotes.';
                    if (response.data) {
                        errorMsg = errorText(response.data, errorMsg);
                    }
                    $overlay.find('h3').text('Error');
                    $overlay.find('p').html(esc(errorMsg) + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                    autoCreateInProgress = false;
                    window.livrariaPreventUnload = false;
                    return;
                }

                var quoteRequestId = response.data.quoteRequestId;
                var quotes = response.data.quotes;
                var selectedQuote = autoQuote(response.data);
                if (!selectedQuote) {
                    $overlay.find('h3').text('Error');
                    $overlay.find('p').html(esc('No courier can take this order automatically: ' + (response.data.unusableSummary || 'no usable quote.')) + RELOAD_BUTTON);
                    autoCreateInProgress = false;
                    window.livrariaPreventUnload = false;
                    return;
                }

                updateProgress(40, 'Selecting quote...');

                var senderProfileId = $('#livraria-sender-profile-select').val() || '';
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'livraria_select_quote',
                        auto: 1,
                        order_id: orderId,
                        quote_request_id: quoteRequestId,
                        courier_quote_id: selectedQuote.id,
                        sender_profile_id: senderProfileId,
                        nonce: $('#courier_expedition_nonce_field').val()
                    },
                    success: function(selectResponse) {
                        if (!selectResponse.success) {
                            $overlay.find('h3').text('Error');
                            $overlay.find('p').html(esc(errorText(selectResponse.data, 'Failed to select quote.')) + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                            autoCreateInProgress = false;
                            window.livrariaPreventUnload = false;
                            return;
                        }

                        updateProgress(60, 'Attaching billing information...');
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'livraria_generate_label',
                                auto: 1,
                                order_id: orderId,
                                nonce: $('#courier_expedition_nonce_field').val()
                            },
                            success: function(labelResponse) {
                                if (!labelResponse.success) {
                                    $overlay.find('h3').text('Error');
                                    $overlay.find('p').html(esc(errorText(labelResponse.data, 'Could not create the shipment.')) + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                    autoCreateInProgress = false;
                                    window.livrariaPreventUnload = false;
                                    return;
                                }
                                updateProgress(100, 'Expedition created successfully!');
                                setTimeout(function() {
                                    $overlay.find('h3').text('Success!');
                                    $overlay.find('p').html('Expedition created successfully. Reloading page...');
                                    autoCreateInProgress = false;
                                    window.livrariaPreventUnload = false;
                                    setTimeout(function() { location.reload(); }, 1000);
                                }, 1000);
                            },
                            error: function(xhr, status, error) {
                                var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? errorText(xhr.responseJSON.data, 'Request failed') : (error || ('Request failed: ' + status) || 'AJAX error occurred while creating expedition');
                                $overlay.find('h3').text('Error');
                                $overlay.find('p').html(esc(errorMsg) + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                console.error('Auto-create generate label error:', xhr, status, error);
                                autoCreateInProgress = false;
                                window.livrariaPreventUnload = false;
                            }
                        });
                    },
                    error: function(xhr, status, error) {
                        var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? errorText(xhr.responseJSON.data, 'Request failed') : (error || ('Request failed: ' + status) || 'AJAX error occurred while selecting quote');
                        $overlay.find('h3').text('Error');
                        $overlay.find('p').html(esc(errorMsg) + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                        console.error('Auto-create select quote error:', xhr, status, error);
                        autoCreateInProgress = false;
                        window.livrariaPreventUnload = false;
                    }
                });
            },
            error: function(xhr, status, error) {
                var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? errorText(xhr.responseJSON.data, 'Request failed') : (error || ('Request failed: ' + status) || 'AJAX error occurred while getting quotes');
                $overlay.find('h3').text('Error');
                $overlay.find('p').html(esc(errorMsg) + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                console.error('Auto-create get quotes error:', xhr, status, error);
                autoCreateInProgress = false;
                window.livrariaPreventUnload = false;
            }
        });
    }

    if (autoCreateEnabled && !expeditionExists) {
        var $orderStatusSelect = $('#order_status, select[name="order_status"], #order-status-select, select#order_status, .order-status-select');
        var $orderForm = $('#post, form[name="post"], form#post, form.edit-order').first();
        var $saveButton = $('button.save_order, input#save-post, button[type="submit"], button[name="save"]').first();

        if ($orderStatusSelect.length) {
            var originalStatus = $orderStatusSelect.val() || $orderStatusSelect.find('option:selected').val();
            var formSubmitted = false;
            var statusChangedToCompleted = false;

            $orderStatusSelect.on('change', function() {
                var newStatus = $(this).val() || $(this).find('option:selected').val();
                var isCompleted = (newStatus === 'wc-completed' || newStatus === 'completed');
                statusChangedToCompleted = isCompleted && (originalStatus !== 'wc-completed' && originalStatus !== 'completed');
                originalStatus = newStatus;
            });

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
                    window.livrariaPreventUnload = true;

                    var $overlay = $('<div id="livraria-auto-create-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:999999;display:flex;align-items:center;justify-content:center;flex-direction:column;color:white;font-size:16px;"><div style="background:white;color:#333;padding:30px;border-radius:8px;max-width:500px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.3);"><h3 style="margin-top:0;color:#2271b1;">Creating Expedition...</h3><p>Please wait while we create the shipping expedition for this order.</p><div id="livraria-progress" style="margin:20px 0;"><div style="background:#f0f0f0;height:24px;border-radius:12px;overflow:hidden;border:2px solid #ddd;"><div id="livraria-progress-bar" style="background:#2271b1;height:100%;width:0%;transition:width 0.3s;display:flex;align-items:center;justify-content:center;color:white;font-size:12px;font-weight:bold;"></div></div></div><p id="livraria-status-text" style="margin:10px 0;font-size:14px;color:#666;">Getting quotes...</p></div></div>');
                    $('body').append($overlay);

                    var updateProgress = function(percent, text) {
                        $('#livraria-progress-bar').css('width', percent + '%').text(percent + '%');
                        $('#livraria-status-text').text(text);
                    };

                    updateProgress(20, 'Getting shipping quotes...');
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'livraria_get_quotes_for_order',
                            auto: 1,
                            order_id: orderId,
                            nonce: $('#courier_expedition_nonce_field').val(),
                            expedition_data: {}
                        },
                        success: function(response) {
                            if (!response.success || !response.data.quotes || response.data.quotes.length === 0) {
                                var errorMsg = 'Could not get quotes.';
                                if (response.data) {
                                    errorMsg = errorText(response.data, errorMsg);
                                }
                                $overlay.find('h3').text('Error');
                                $overlay.find('p').html(esc(errorMsg) + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                autoCreateInProgress = false;
                                window.livrariaPreventUnload = false;
                                return;
                            }

                            var quoteRequestId = response.data.quoteRequestId;
                            var quotes = response.data.quotes;
                            var selectedQuote = autoQuote(response.data);
                            if (!selectedQuote) {
                                $overlay.find('h3').text('Error');
                                $overlay.find('p').html(esc('No courier can take this order automatically: ' + (response.data.unusableSummary || 'no usable quote.')) + RELOAD_BUTTON);
                                autoCreateInProgress = false;
                                window.livrariaPreventUnload = false;
                                return;
                            }

                            updateProgress(40, 'Selecting quote...');

                            var senderProfileId = $('#livraria-sender-profile-select').val() || '';
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'livraria_select_quote',
                                    auto: 1,
                                    order_id: orderId,
                                    quote_request_id: quoteRequestId,
                                    courier_quote_id: selectedQuote.id,
                                    sender_profile_id: senderProfileId,
                                    nonce: $('#courier_expedition_nonce_field').val()
                                },
                                success: function(selectResponse) {
                                    if (!selectResponse.success) {
                                        $overlay.find('h3').text('Error');
                                        $overlay.find('p').html(esc(errorText(selectResponse.data, 'Failed to select quote.')) + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                        autoCreateInProgress = false;
                                        window.livrariaPreventUnload = false;
                                        return;
                                    }

                                    updateProgress(60, 'Attaching billing information...');
                                    $.ajax({
                                        url: ajaxurl,
                                        type: 'POST',
                                        data: {
                                            action: 'livraria_generate_label',
                                            auto: 1,
                                            order_id: orderId,
                                            nonce: $('#courier_expedition_nonce_field').val()
                                        },
                                        success: function(labelResponse) {
                                            if (!labelResponse.success) {
                                                $overlay.find('h3').text('Error');
                                                $overlay.find('p').html(esc(errorText(labelResponse.data, 'Could not create the shipment.')) + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                                autoCreateInProgress = false;
                                                window.livrariaPreventUnload = false;
                                                return;
                                            }
                                            updateProgress(100, 'Expedition created successfully!');
                                            setTimeout(function() {
                                                $overlay.find('h3').text('Success!');
                                                $overlay.find('p').html('Expedition created successfully. Reloading page...');
                                                formSubmitted = true;
                                                autoCreateInProgress = false;
                                                window.livrariaPreventUnload = false;
                                                setTimeout(function() {
                                                    if ($orderForm.length) {
                                                        $orderForm.off('submit').submit();
                                                    } else {
                                                        location.reload();
                                                    }
                                                }, 500);
                                            }, 1000);
                                        },
                                        error: function(xhr, status, error) {
                                            var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? errorText(xhr.responseJSON.data, 'Request failed') : (error || ('Request failed: ' + status) || 'AJAX error occurred while creating expedition');
                                            $overlay.find('h3').text('Error');
                                            $overlay.find('p').html(esc(errorMsg) + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                            console.error('Auto-create generate label error:', xhr, status, error);
                                            autoCreateInProgress = false;
                                            window.livrariaPreventUnload = false;
                                        }
                                    });
                                },
                                error: function(xhr, status, error) {
                                    var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? errorText(xhr.responseJSON.data, 'Request failed') : (error || ('Request failed: ' + status) || 'AJAX error occurred while selecting quote');
                                    $overlay.find('h3').text('Error');
                                    $overlay.find('p').html(esc(errorMsg) + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                    console.error('Auto-create select quote error:', xhr, status, error);
                                    autoCreateInProgress = false;
                                    window.livrariaPreventUnload = false;
                                }
                            });
                        },
                        error: function(xhr, status, error) {
                            var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? errorText(xhr.responseJSON.data, 'Request failed') : (error || ('Request failed: ' + status) || 'AJAX error occurred while getting quotes');
                            $overlay.find('h3').text('Error');
                            $overlay.find('p').html(esc(errorMsg) + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                            console.error('Auto-create get quotes error:', xhr, status, error);
                            autoCreateInProgress = false;
                            window.livrariaPreventUnload = false;
                        }
                    });

                    return false;
                }
            });

            if ($orderForm.length) {
                $orderForm.on('submit', function(e) {
                    if (statusChangedToCompleted && !formSubmitted && autoCreateInProgress) {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        return false;
                    }
                });
            }

            $(window).on('beforeunload', function() {
                if (window.livrariaPreventUnload && autoCreateInProgress) {
                    return 'An expedition is being created. Are you sure you want to leave?';
                }
            });
        }
    }

    // Add package
    $('#add-package-btn').click(function() {
        packageCount++;
        var packageHtml = '<div class="package-item" data-package="' + packageCount + '">' +
            '<h5>Package ' + packageCount + '</h5>' +
            '<div class="package-dimensions">' +
                '<label>Weight (kg): <input type="number" name="package_weight[]" step="0.01" min="1" value="1"></label>' +
                '<label>Width (cm): <input type="number" name="package_width[]" step="1" min="10" value="10"></label>' +
                '<label>Height (cm): <input type="number" name="package_height[]" step="1" min="10" value="10"></label>' +
                '<label>Length (cm): <input type="number" name="package_length[]" step="1" min="10" value="10"></label>' +
                '<button type="button" class="button remove-package">Remove</button>' +
            '</div>' +
        '</div>';
        $('#packages-container').append(packageHtml);
        updateRemoveButtons();
    });

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

    // Get quotes
    $('#create-expedition-btn').click(function() {
        var btn = $(this);
        var isValid = true;
        var errors = [];

        $('.package-item').each(function(index) {
            var $item = $(this);
            var weight = parseFloat($item.find('input[name="package_weight[]"]').val());
            var width  = parseFloat($item.find('input[name="package_width[]"]').val());
            var height = parseFloat($item.find('input[name="package_height[]"]').val());
            var length = parseFloat($item.find('input[name="package_length[]"]').val());

            if (isNaN(weight) || weight < 1)  { isValid = false; errors.push('Package ' + (index + 1) + ': Weight must be at least 1 kg'); }
            if (isNaN(width)  || width  < 10) { isValid = false; errors.push('Package ' + (index + 1) + ': Width must be at least 10 cm'); }
            if (isNaN(height) || height < 10) { isValid = false; errors.push('Package ' + (index + 1) + ': Height must be at least 10 cm'); }
            if (isNaN(length) || length < 10) { isValid = false; errors.push('Package ' + (index + 1) + ': Length must be at least 10 cm'); }
        });

        var codAmount       = parseFloat($('input[name="cod_amount"]').val());
        var insuranceAmount = parseFloat($('input[name="insurance_amount"]').val());
        if (isNaN(codAmount)       || codAmount       < 0) { isValid = false; errors.push('COD Amount must be at least 0'); }
        if (isNaN(insuranceAmount) || insuranceAmount < 0) { isValid = false; errors.push('Insurance Amount must be at least 0'); }

        if (!isValid) {
            $('#expedition-result').html('<p style="color:red;">' + errors.join('<br>') + '</p>');
            return;
        }

        btn.prop('disabled', true);
        $('#expedition-loading').show();
        $('#expedition-result').html('');
        $('#quotes-section').hide();
        $('#generate-label-section').hide();

        var packages = [];
        $('.package-item').each(function() {
            var $item = $(this);
            packages.push({
                weight: parseFloat($item.find('input[name="package_weight[]"]').val()),
                width:  parseFloat($item.find('input[name="package_width[]"]').val()),
                height: parseFloat($item.find('input[name="package_height[]"]').val()),
                length: parseFloat($item.find('input[name="package_length[]"]').val())
            });
        });

        var expeditionData = {
            packages:            packages,
            content_description: $('textarea[name="content_description"]').val() || '',
            cod_amount:          parseFloat($('input[name="cod_amount"]').val()),
            insurance_amount:    parseFloat($('input[name="insurance_amount"]').val()),
            open_on_delivery:    $('input[name="open_on_delivery"]').is(':checked') ? 1 : 0,
            saturday_delivery:   $('input[name="saturday_delivery"]').is(':checked') ? 1 : 0,
            route:               routeForRequest()
        };

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'livraria_get_quotes_for_order',
                order_id: orderId,
                nonce: $('#courier_expedition_nonce_field').val(),
                expedition_data: expeditionData
            },
            success: function(response) {
                $('#expedition-loading').hide();
                if (response.success && response.data.blocked) {
                    // An unusable buyer pick: named, never guessed; the merchant decides
                    renderBanner(response.data.banner);
                    $('#expedition-result').html('<p style="color:#996800;">' + esc(response.data.blocked) + '</p>');
                    btn.prop('disabled', false);
                } else if (response.success && response.data.quotes && response.data.quotes.length > 0) {
                    quoteRequestId = response.data.quoteRequestId;
                    renderBanner(response.data.banner);
                    activeTab = 'ALL';
                    displayQuotes(response.data.quotes, response.data.tabs);
                    // Every quote is shown; the cheapest usable one is preselected (the merchant may pick another)
                    var preselect = autoQuote(response.data);
                    if (preselect) {
                        $('.select-quote-btn').filter(function() { return $(this).attr('data-quote-id') === preselect.id; }).first().trigger('click');
                    }
                    $('#quotes-section').show();
                    $('#quote-selection-result').html('');
                    btn.prop('disabled', false);
                } else {
                    var errorMsg = 'Failed to get quotes';
                    if (response.data) {
                        errorMsg = errorText(response.data, errorMsg);
                    }
                    $('#expedition-result').html(errorHtml(response.data, 'Failed to get quotes'));
                    btn.prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                $('#expedition-loading').hide();
                var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? errorText(xhr.responseJSON.data, 'Request failed') : (error || ('Request failed: ' + status) || 'AJAX request failed');
                $('#expedition-result').html('<p style="color:red;">Error: ' + esc(errorMsg) + '</p>');
                console.error('Get quotes AJAX error:', xhr, status, error);
                btn.prop('disabled', false);
            }
        });
    });

    // Tabs ALL / one per route ("· Cheapest" on the route holding the lowest
    // usable quote, from the server); rows: usable first with a price and
    // "Select", disabled ones greyed with the backend's reason, never selectable
    var activeTab = 'ALL';
    var tabsData = [];
    var ROUTE_BADGES = { DOOR_TO_DOOR: 'Door to door', DOOR_TO_LOCKER: 'To a locker', LOCKER_TO_DOOR: 'From a locker', LOCKER_TO_LOCKER: 'Locker to locker' };

    function displayQuotes(quotes, tabs) {
        quotesData = quotes;
        if (tabs) { tabsData = tabs; }
        var tabsHtml = '<div class="livraria-quote-tabs" role="tablist" style="display:flex; flex-wrap:wrap; gap:4px; margin-bottom:8px;">';
        tabsData.forEach(function(tab) {
            var active = tab.key === activeTab;
            tabsHtml += '<button type="button" role="tab" class="button livraria-quote-tab' + (active ? ' button-primary' : '') + '" data-tab="' + esc(tab.key) + '" aria-selected="' + (active ? 'true' : 'false') + '"' + (tab.count === 0 ? ' disabled' : '') + '>' +
                esc(tab.label) + ' (' + esc(tab.count) + ')' + (tab.cheapest ? ' · Cheapest' : '') + '</button>';
        });
        tabsHtml += '</div>';

        var quotesHtml = '';
        quotes.forEach(function(quote, index) {
            if (activeTab !== 'ALL' && quote.deliveryType !== activeTab) { return; }
            var name = esc(quote.courierName || ('Courier ' + (index + 1)));
            var badge = ' <span style="font-size: 11px; color: #646970;">' + esc(ROUTE_BADGES[quote.deliveryType] || quote.deliveryType || '') + '</span>';
            if (!quote.usable) {
                quotesHtml += '<div class="quote-item quote-disabled" data-quote-id="' + esc(quote.id) + '" aria-disabled="true" style="border: 1px solid #e0e0e0; padding: 10px; margin-bottom: 10px; border-radius: 4px; background-color: #f6f7f7; color: #8c8f94;">';
                quotesHtml += '<strong style="color: #8c8f94;">' + name + '</strong>' + badge + ' <span style="font-size: 11px;">(unavailable)</span>';
                quotesHtml += '<br><span class="quote-disabled-reason">' + esc(quote.disabledReason) + '</span>';
                if (quote.needsEmail) {
                    quotesHtml += fillInControl('email');
                } else if (quote.needsField) {
                    quotesHtml += fillInControl(quote.needsField);
                }
                quotesHtml += '</div>';
                return;
            }
            var isSelected = selectedQuoteId === quote.id;
            quotesHtml += '<div class="quote-item" data-quote-id="' + esc(quote.id) + '" style="border: 1px solid #ddd; padding: 10px; margin-bottom: 10px; border-radius: 4px; ' + (isSelected ? 'border-color: #0073aa; background-color: #f0f8ff;' : '') + '">';
            quotesHtml += '<strong>' + name + '</strong>' + badge;
            if (quote.amount !== undefined)       { quotesHtml += '<br>Price: <strong>' + esc(quote.amount) + ' ' + esc(quote.currency || 'RON') + '</strong>'; }
            if (quote.deliveryDays !== undefined) { quotesHtml += '<br>Estimated delivery: ' + esc(quote.deliveryDays) + ' day(s)'; }
            quotesHtml += '<br><button type="button" class="button select-quote-btn" data-quote-id="' + esc(quote.id) + '" ' + (isSelected ? 'disabled style="background-color: #46b450; color: white;"' : '') + '>';
            quotesHtml += isSelected ? '✓ Selected' : 'Select This Quote';
            quotesHtml += '</button></div>';
        });
        $('#quotes-container').html(tabsHtml + quotesHtml);
    }

    $(document).on('click', '.livraria-quote-tab', function() {
        activeTab = $(this).attr('data-tab');
        displayQuotes(quotesData);
    });

    // ---------------------------------------------------------------------
    // Route: origin (courier pickup / drop-off at a locker) and destination
    // (the buyer's pick / the order address / a locker). Any change drops the
    // quotes: they were priced for the other route.
    // ---------------------------------------------------------------------
    var buyerPick = livrariaOrder.buyerPick || null;
    var route = {
        origin: { mode: 'pickup', locker: null, suggested: false },
        destination: { mode: buyerPick ? 'buyer' : 'order', locker: null, suggested: false }
    };
    var pickerFor = null;
    var picker = null;

    function lockerLabel(locker) {
        if (!locker) { return ''; }
        var product = locker.courier === 'sameday' ? 'easybox Sameday' : (locker.courier === 'dpd' ? 'locker DPD' : 'locker');
        return product + ' ' + (locker.name || locker.id);
    }

    function routeForRequest() {
        var payload = { origin: { mode: route.origin.mode }, destination: { mode: route.destination.mode } };
        ['origin', 'destination'].forEach(function(end) {
            var locker = route[end].locker;
            if (route[end].mode === 'locker' && locker) {
                payload[end].locker = { id: locker.id, courier: locker.courier, name: locker.name };
            }
        });
        return payload;
    }

    function renderBanner(banner) {
        var $box = $('#livraria-route-banner');
        if (!$box.length) { return; }
        $box.empty();
        if (!banner) { return; }
        var $notice = $('<div class="notice inline"></div>').addClass(banner.type === 'buyer' ? 'notice-info' : 'notice-warning');
        $notice.append($('<p></p>').text(banner.message));
        if (banner.type === 'unusable' || banner.type === 'buyer') {
            $notice.append($('<p></p>').append($('<button type="button" class="button livraria-ship-to-address"></button>').text('Ship to the order address instead')));
        }
        if (banner.type === 'changed' && buyerPick) {
            $notice.append($('<p></p>').append($('<button type="button" class="button livraria-use-buyer-pick"></button>').text(buyerPick.supported ? "Use the buyer's locker again" : 'Undo')));
        }
        $box.append($notice);
    }

    // The banner for the destination in use, before any quote (the server sends its own with the quotes)
    function localBanner() {
        if (!buyerPick) { return null; }
        if (!buyerPick.supported) {
            return route.destination.mode === 'buyer'
                ? { type: 'unusable', message: buyerPick.message }
                : { type: 'changed', message: 'You chose not to use the buyer\'s pick (' + buyerPick.display + ', which cannot be used).' };
        }
        if (route.destination.mode === 'buyer') {
            return { type: 'buyer', message: 'The buyer chose delivery to a locker (livrare la locker): ' + buyerPick.display + '. Quotes to it are under "To a locker".' };
        }
        var to = route.destination.mode === 'locker'
            ? (route.destination.locker ? lockerLabel(route.destination.locker) : 'a locker you have not chosen yet')
            : 'the order\'s shipping address';
        return { type: 'changed', message: 'You changed the destination. The buyer chose ' + buyerPick.display + ' at checkout; this shipment goes to ' + to + '.' };
    }

    function resetQuotes() {
        quotesData = [];
        tabsData = [];
        selectedQuoteId = null;
        quoteRequestId = null;
        $('#quotes-section').hide();
        $('#quotes-container').html('');
        $('#generate-label-section').hide();
        $('#expedition-result').html('');
    }

    function renderEnd(end) {
        var $box = $('#livraria-' + end + '-locker');
        $box.empty();
        if (route[end].mode !== 'locker') { return; }
        var locker = route[end].locker;
        if (locker) {
            $box.append($('<p></p>').text((route[end].suggested ? 'Suggested (nearest): ' : 'Locker: ') + lockerLabel(locker)));
            if (end === 'origin' && locker.courier === 'sameday') {
                $box.append($('<p style="font-size: 11px; color: #646970;"></p>').text('Suggested drop-off point. You may leave the parcel at any easybox.'));
            }
        } else if (route[end].status) {
            $box.append($('<p style="font-size: 12px;"></p>').text(route[end].status));
        }
        $box.append($('<button type="button" class="button livraria-choose-locker"></button>').attr('data-end', end).text(locker ? 'Change' : 'Choose locker'));
    }

    function suggest(end) {
        route[end].status = 'Looking for the nearest locker…';
        renderEnd(end);
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: { action: 'livraria_closest_locker', order_id: orderId, end: end, nonce: $('#courier_expedition_nonce_field').val() },
            success: function(response) {
                if (route[end].mode !== 'locker' || route[end].locker) { return; }
                if (response.success && response.data && response.data.locker) {
                    route[end].locker = response.data.locker;
                    route[end].suggested = true;
                    route[end].status = '';
                } else {
                    route[end].status = errorText(response.data, 'No locker could be suggested. Choose one from the list.');
                }
                renderEnd(end);
                renderBanner(localBanner());
            },
            error: function() {
                route[end].status = 'No locker could be suggested. Choose one from the list.';
                renderEnd(end);
            }
        });
    }

    function openPicker(end) {
        var $root = $('#livraria-route-picker');
        if (!$root.length || !window.LivrariaLockerPicker) { return; }
        pickerFor = end;
        if (picker) { picker.destroy(); }
        $root.show();
        picker = window.LivrariaLockerPicker.mount($root[0], $.extend({}, livrariaOrder.lockerPicker, {
            onChange: function(pick) {
                if (!pick.id || !pickerFor) { return; }
                route[pickerFor].locker = { id: pick.id, name: pick.name, courier: pick.courier };
                route[pickerFor].suggested = false;
                renderEnd(pickerFor);
                renderBanner(localBanner());
                resetQuotes();
                picker.destroy();
                $root.hide();
                pickerFor = null;
            }
        }));
    }

    function setDestination(mode) {
        route.destination.mode = mode;
        $('#livraria-destination-mode').val(mode);
        resetQuotes();
        renderEnd('destination');
        renderBanner(localBanner());
        if (mode === 'locker' && !route.destination.locker) { suggest('destination'); }
    }

    $('#livraria-origin-mode').on('change', function() {
        route.origin.mode = $(this).val();
        resetQuotes();
        renderEnd('origin');
        if (route.origin.mode === 'locker' && !route.origin.locker) { suggest('origin'); }
    });
    $('#livraria-destination-mode').on('change', function() { setDestination($(this).val()); });
    $(document).on('click', '.livraria-choose-locker', function() { openPicker($(this).attr('data-end')); });
    $(document).on('click', '.livraria-ship-to-address', function() { setDestination('order'); });
    $(document).on('click', '.livraria-use-buyer-pick', function() { setDestination('buyer'); });

    $(document).on('click', '.livraria-fill-in-save', function() {
        var btn = $(this);
        var $box = btn.closest('.livraria-fill-in');
        var field = $box.attr('data-field');
        var value = $.trim($box.find('.livraria-fill-in-value').val() || '');
        var $result = $box.find('.livraria-fill-in-result');
        if (!value) {
            $result.css('color', 'red').text(field === 'email' ? 'Enter an email address.' : 'Enter a phone number.');
            return;
        }
        btn.prop('disabled', true);
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'livraria_update_order_contact',
                order_id: orderId,
                field: field,
                value: value,
                nonce: $('#courier_expedition_nonce_field').val()
            },
            success: function(response) {
                if (response.success) {
                    $result.css('color', 'green').text(errorText(response.data, 'Saved on the order.') + ' Getting quotes again...');
                    selectedQuoteId = null;
                    $('#generate-label-section').hide();
                    $('#create-expedition-btn').trigger('click');
                } else {
                    $result.css('color', 'red').text(errorText(response.data, 'Could not save it on the order.'));
                    btn.prop('disabled', false);
                }
            },
            error: function(xhr) {
                $result.css('color', 'red').text(errorText(xhr.responseJSON && xhr.responseJSON.data, 'Could not save it on the order.'));
                btn.prop('disabled', false);
            }
        });
    });

    // "Reload profiles": the panel renders sender profiles from the cache only;
    // with none cached, fetch them through admin-ajax and build the select
    $(document).on('click', '#livraria-reload-profiles', function() {
        var btn = $(this);
        var $result = $('#livraria-reload-profiles-result');
        btn.prop('disabled', true);
        $result.css('color', '').text('Loading...');
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'livraria_reload_sender_profiles',
                order_id: orderId,
                nonce: $('#courier_expedition_nonce_field').val()
            },
            success: function(response) {
                if (!response.success) {
                    $result.css('color', 'red').text(errorText(response.data, 'Could not load the sender profiles.'));
                    btn.prop('disabled', false);
                    return;
                }
                var $select = $('<select id="livraria-sender-profile-select" name="livraria_sender_profile_id"></select>');
                $.each(response.data.options || [], function(i, option) {
                    var $option = $('<option></option>').attr('value', option.id).text(option.label);
                    if (option.id === response.data.selected) { $option.prop('selected', true); }
                    $select.append($option);
                });
                $('#livraria-sender-profiles').empty().append($select)
                    .append($('<p class="description"></p>').text('Select the sender profile to use for this expedition'));
            },
            error: function(xhr) {
                $result.css('color', 'red').text(errorText(xhr.responseJSON && xhr.responseJSON.data, 'Could not load the sender profiles.'));
                btn.prop('disabled', false);
            }
        });
    });

    // Select quote
    $(document).on('click', '.select-quote-btn', function() {
        var quoteId = String($(this).attr('data-quote-id'));
        var btn = $(this);

        if (!quoteRequestId) {
            $('#expedition-result').html('<p style="color:red;">Quote request ID not found</p>');
            return;
        }

        var selectedQuote = quotesData.find(function(q) { return q.id === quoteId; });
        if (!selectedQuote || !selectedQuote.usable) {
            return; // a disabled quote is never selected
        }
        var courierName = selectedQuote.courierName || '';

        btn.prop('disabled', true);
        $('#expedition-loading').show();

        var senderProfileId = $('#livraria-sender-profile-select').val() || '';
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'livraria_select_quote',
                order_id: orderId,
                quote_request_id: selectedQuote.quoteRequestId || quoteRequestId,
                courier_quote_id: quoteId,
                courier_name: courierName,
                sender_profile_id: senderProfileId,
                nonce: $('#courier_expedition_nonce_field').val()
            },
            success: function(response) {
                $('#expedition-loading').hide();
                if (response.success) {
                    selectedQuoteId = quoteId;
                    $('.quote-item').removeClass('selected').css({'border-color': '#ddd', 'background-color': ''});
                    $('.select-quote-btn').prop('disabled', false).text('Select This Quote').css({'background-color': '', 'color': ''});
                    $('.quote-item[data-quote-id="' + quoteId + '"]').css({'border-color': '#0073aa', 'background-color': '#f0f8ff'});
                    btn.prop('disabled', true).text('✓ Selected').css({'background-color': '#46b450', 'color': 'white'});
                    $('#generate-label-section').show();
                    $('#quote-selection-result').html('<p style="color:green;">Quote selected successfully!</p>');
                } else {
                    var errorMsg = 'Failed to select quote';
                    if (response.data) {
                        errorMsg = errorText(response.data, errorMsg);
                    }
                    $('#quote-selection-result').html('<p style="color:red;">Error: ' + esc(errorMsg) + '</p>');
                    btn.prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                $('#expedition-loading').hide();
                var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? errorText(xhr.responseJSON.data, 'Request failed') : (error || ('Request failed: ' + status) || 'AJAX request failed');
                $('#quote-selection-result').html('<p style="color:red;">Error: ' + esc(errorMsg) + '</p>');
                console.error('Select quote AJAX error:', xhr, status, error);
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
                action: 'livraria_generate_label',
                order_id: orderId,
                nonce: $('#courier_expedition_nonce_field').val()
            },
            success: function(response) {
                $('#label-loading').hide();
                if (response.success) {
                    $('#label-result').html('<p style="color:green;">Expedition created successfully! AWB: ' + (response.data.awb_number || 'N/A') + '</p>');
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    var errorMsg = 'Failed to generate label';
                    if (response.data) {
                        errorMsg = errorText(response.data, errorMsg);
                    }
                    $('#label-result').html(errorHtml(response.data, 'Failed to generate label'));
                    btn.prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                $('#label-loading').hide();
                var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? errorText(xhr.responseJSON.data, 'Request failed') : (error || ('Request failed: ' + status) || 'AJAX request failed');
                $('#label-result').html('<p style="color:red;">Error: ' + esc(errorMsg) + '</p>');
                console.error('Generate label AJAX error:', xhr, status, error);
                btn.prop('disabled', false);
            }
        });
    });
});
