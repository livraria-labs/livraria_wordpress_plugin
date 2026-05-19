// Order meta box JS for Livraria Shipping for WooCommerce
// Dynamic data (orderId, autoCreateEnabled, etc.) provided via wp_localize_script as livrariaOrder.
jQuery(document).ready(function($) {

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
                    alert('Failed to remove expedition: ' + (response.data || 'Unknown error'));
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
                order_id: orderId,
                nonce: $('#courier_expedition_nonce_field').val(),
                expedition_data: {}
            },
            success: function(response) {
                if (!response.success || !response.data.quotes || response.data.quotes.length === 0) {
                    var errorMsg = 'Unknown error';
                    if (response.data) {
                        errorMsg = (typeof response.data === 'string') ? response.data : (response.data.message || errorMsg);
                    }
                    $overlay.find('h3').text('Error');
                    $overlay.find('p').html('Failed to get quotes: ' + errorMsg + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                    autoCreateInProgress = false;
                    window.livrariaPreventUnload = false;
                    return;
                }

                var quoteRequestId = response.data.quoteRequestId;
                var quotes = response.data.quotes;
                var selectedQuote = quotes[0];

                updateProgress(40, 'Selecting quote...');

                var senderProfileId = $('#livraria-sender-profile-select').val() || '';
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'livraria_select_quote',
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
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'livraria_generate_label',
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
                                setTimeout(function() {
                                    $overlay.find('h3').text('Success!');
                                    $overlay.find('p').html('Expedition created successfully. Reloading page...');
                                    autoCreateInProgress = false;
                                    window.livrariaPreventUnload = false;
                                    setTimeout(function() { location.reload(); }, 1000);
                                }, 1000);
                            },
                            error: function(xhr, status, error) {
                                var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? xhr.responseJSON.data : (error || ('Request failed: ' + status) || 'AJAX error occurred while creating expedition');
                                $overlay.find('h3').text('Error');
                                $overlay.find('p').html(errorMsg + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                console.error('Auto-create generate label error:', xhr, status, error);
                                autoCreateInProgress = false;
                                window.livrariaPreventUnload = false;
                            }
                        });
                    },
                    error: function(xhr, status, error) {
                        var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? xhr.responseJSON.data : (error || ('Request failed: ' + status) || 'AJAX error occurred while selecting quote');
                        $overlay.find('h3').text('Error');
                        $overlay.find('p').html(errorMsg + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                        console.error('Auto-create select quote error:', xhr, status, error);
                        autoCreateInProgress = false;
                        window.livrariaPreventUnload = false;
                    }
                });
            },
            error: function(xhr, status, error) {
                var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? xhr.responseJSON.data : (error || ('Request failed: ' + status) || 'AJAX error occurred while getting quotes');
                $overlay.find('h3').text('Error');
                $overlay.find('p').html(errorMsg + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
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
                            order_id: orderId,
                            nonce: $('#courier_expedition_nonce_field').val(),
                            expedition_data: {}
                        },
                        success: function(response) {
                            if (!response.success || !response.data.quotes || response.data.quotes.length === 0) {
                                var errorMsg = 'Unknown error';
                                if (response.data) {
                                    errorMsg = (typeof response.data === 'string') ? response.data : (response.data.message || errorMsg);
                                }
                                $overlay.find('h3').text('Error');
                                $overlay.find('p').html('Failed to get quotes: ' + errorMsg + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                autoCreateInProgress = false;
                                window.livrariaPreventUnload = false;
                                return;
                            }

                            var quoteRequestId = response.data.quoteRequestId;
                            var quotes = response.data.quotes;
                            var selectedQuote = quotes[0];

                            updateProgress(40, 'Selecting quote...');

                            var senderProfileId = $('#livraria-sender-profile-select').val() || '';
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'livraria_select_quote',
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
                                    $.ajax({
                                        url: ajaxurl,
                                        type: 'POST',
                                        data: {
                                            action: 'livraria_generate_label',
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
                                            var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? xhr.responseJSON.data : (error || ('Request failed: ' + status) || 'AJAX error occurred while creating expedition');
                                            $overlay.find('h3').text('Error');
                                            $overlay.find('p').html(errorMsg + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                            console.error('Auto-create generate label error:', xhr, status, error);
                                            autoCreateInProgress = false;
                                            window.livrariaPreventUnload = false;
                                        }
                                    });
                                },
                                error: function(xhr, status, error) {
                                    var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? xhr.responseJSON.data : (error || ('Request failed: ' + status) || 'AJAX error occurred while selecting quote');
                                    $overlay.find('h3').text('Error');
                                    $overlay.find('p').html(errorMsg + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
                                    console.error('Auto-create select quote error:', xhr, status, error);
                                    autoCreateInProgress = false;
                                    window.livrariaPreventUnload = false;
                                }
                            });
                        },
                        error: function(xhr, status, error) {
                            var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? xhr.responseJSON.data : (error || ('Request failed: ' + status) || 'AJAX error occurred while getting quotes');
                            $overlay.find('h3').text('Error');
                            $overlay.find('p').html(errorMsg + '<br><button onclick="window.livrariaPreventUnload=false;location.reload()" style="margin-top:15px;padding:8px 16px;background:#2271b1;color:white;border:none;border-radius:4px;cursor:pointer;">Reload Page</button>');
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
            open_on_delivery:    $('input[name="open_on_delivery"]').is(':checked'),
            saturday_delivery:   $('input[name="saturday_delivery"]').is(':checked')
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
                if (response.success && response.data.quotes && response.data.quotes.length > 0) {
                    quoteRequestId = response.data.quoteRequestId;
                    displayQuotes(response.data.quotes);
                    $('#quotes-section').show();
                    $('#quote-selection-result').html('');
                    btn.prop('disabled', false);
                } else {
                    var errorMsg = 'Failed to get quotes';
                    if (response.data) {
                        errorMsg = (typeof response.data === 'string') ? response.data : (response.data.message || errorMsg);
                    }
                    $('#expedition-result').html('<p style="color:red;">Error: ' + errorMsg + '</p>');
                    btn.prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                $('#expedition-loading').hide();
                var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? xhr.responseJSON.data : (error || ('Request failed: ' + status) || 'AJAX request failed');
                $('#expedition-result').html('<p style="color:red;">Error: ' + errorMsg + '</p>');
                console.error('Get quotes AJAX error:', xhr, status, error);
                btn.prop('disabled', false);
            }
        });
    });

    function displayQuotes(quotes) {
        quotesData = quotes;
        var quotesHtml = '';
        quotes.forEach(function(quote, index) {
            var isSelected = selectedQuoteId === quote.id;
            quotesHtml += '<div class="quote-item" data-quote-id="' + quote.id + '" style="border: 1px solid #ddd; padding: 10px; margin-bottom: 10px; border-radius: 4px; ' + (isSelected ? 'border-color: #0073aa; background-color: #f0f8ff;' : '') + '">';
            quotesHtml += '<strong>' + (quote.courierName || 'Courier ' + (index + 1)) + '</strong>';
            if (quote.amount !== undefined)       { quotesHtml += '<br>Price: <strong>' + quote.amount + ' ' + (quote.currency || 'RON') + '</strong>'; }
            if (quote.deliveryDays !== undefined) { quotesHtml += '<br>Estimated delivery: ' + quote.deliveryDays + ' day(s)'; }
            quotesHtml += '<br><button type="button" class="button select-quote-btn" data-quote-id="' + quote.id + '" ' + (isSelected ? 'disabled style="background-color: #46b450; color: white;"' : '') + '>';
            quotesHtml += isSelected ? '✓ Selected' : 'Select This Quote';
            quotesHtml += '</button></div>';
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

        var selectedQuote = quotesData.find(function(q) { return q.id === quoteId; });
        var courierName = selectedQuote ? selectedQuote.courierName : '';

        btn.prop('disabled', true);
        $('#expedition-loading').show();

        var senderProfileId = $('#livraria-sender-profile-select').val() || '';
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'livraria_select_quote',
                order_id: orderId,
                quote_request_id: quoteRequestId,
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
                        errorMsg = (typeof response.data === 'string') ? response.data : (response.data.message || errorMsg);
                    }
                    $('#quote-selection-result').html('<p style="color:red;">Error: ' + errorMsg + '</p>');
                    btn.prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                $('#expedition-loading').hide();
                var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? xhr.responseJSON.data : (error || ('Request failed: ' + status) || 'AJAX request failed');
                $('#quote-selection-result').html('<p style="color:red;">Error: ' + errorMsg + '</p>');
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
                        errorMsg = (typeof response.data === 'string') ? response.data : (response.data.message || errorMsg);
                    }
                    $('#label-result').html('<p style="color:red;">Error: ' + errorMsg + '</p>');
                    btn.prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                $('#label-loading').hide();
                var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? xhr.responseJSON.data : (error || ('Request failed: ' + status) || 'AJAX request failed');
                $('#label-result').html('<p style="color:red;">Error: ' + errorMsg + '</p>');
                console.error('Generate label AJAX error:', xhr, status, error);
                btn.prop('disabled', false);
            }
        });
    });
});
