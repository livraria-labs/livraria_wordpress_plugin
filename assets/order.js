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
        var id = 'livraria-fill-' + field + '-' + Math.random().toString(36).slice(2, 8);
        return '<div class="livraria-fill-in" data-field="' + field + '">' +
            '<label class="screen-reader-text" for="' + id + '">' + esc(f.label) + '</label>' +
            '<input type="' + f.type + '" id="' + id + '" class="livraria-fill-in-value" placeholder="' + esc(f.placeholder) + '">' +
            '<button type="button" class="button livraria-fill-in-save">' + esc(f.label) + '</button>' +
            '<span class="livraria-fill-in-result" aria-live="polite"></span>' +
            '</div>';
    }

    // A message line in the box: kind is error, success or warning
    function message(kind, text) {
        return '<p class="lp-' + kind + '">' + esc(text) + '</p>';
    }

    // An error line, with the fill-in the server named (data.fix) under it
    function errorHtml(data, fallback) {
        var html = message('error', 'Error: ' + errorText(data, fallback));
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
                    btn.prop('disabled', false).text('Remove local record');
                }
            },
            error: function() {
                alert('AJAX error while removing expedition');
                btn.prop('disabled', false).text('Remove local record');
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

    // ---------------------------------------------------------------------
    // The box adapts to its own width (main column or sidebar): container
    // queries in order-panel.css, and this class where they are missing
    // ---------------------------------------------------------------------
    var $panel = $('.livraria-panel');
    function markNarrow() {
        $panel.each(function() { $(this).toggleClass('lp-narrow', this.getBoundingClientRect().width <= 560); });
    }
    if ($panel.length) {
        if (window.ResizeObserver) {
            var observer = new ResizeObserver(markNarrow);
            $panel.each(function() { observer.observe(this); });
        } else {
            $(window).on('resize', markNarrow);
        }
        markNarrow();
    }

    // ---------------------------------------------------------------------
    // Parcels
    // ---------------------------------------------------------------------
    var PARCEL_FIELDS = [
        ['package_weight', 'Weight (kg)', '0.01', '1', '1'],
        ['package_length', 'Length (cm)', '1', '10', '10'],
        ['package_width', 'Width (cm)', '1', '10', '10'],
        ['package_height', 'Height (cm)', '1', '10', '10']
    ];

    $('#add-package-btn').on('click', function() {
        packageCount++;
        var row = '<tr class="package-item" data-package="' + packageCount + '"><th scope="row" class="livraria-package-label">Parcel ' + packageCount + '</th>';
        PARCEL_FIELDS.forEach(function(f) {
            row += '<td data-label="' + f[1] + '"><input type="number" name="' + f[0] + '[]" step="' + f[2] + '" min="' + f[3] + '" value="' + f[4] + '" aria-label="' + f[1] + '"></td>';
        });
        row += '<td class="lp-remove-cell"><button type="button" class="button-link button-link-delete remove-package">Remove</button></td></tr>';
        $('#packages-container').append(row);
        updateParcels();
    });

    $(document).on('click', '.remove-package', function() {
        $(this).closest('.package-item').remove();
        packageCount--;
        updateParcels();
    });

    function updateParcels() {
        var $rows = $('.package-item');
        $rows.each(function(index) { $(this).find('.livraria-package-label').text('Parcel ' + (index + 1)); });
        $('.remove-package').prop('hidden', $rows.length <= 1);
    }

    // ---------------------------------------------------------------------
    // The one primary action: "Get quotes", then "Create AWB · courier · price"
    // ---------------------------------------------------------------------
    function actionsBeforeSelection() {
        $('#generate-label-section').prop('hidden', true);
        $('#create-expedition-btn').addClass('button-primary').text('Get quotes');
    }

    function actionsAfterSelection(quote) {
        var label = 'Create AWB · ' + (quote.courierName || 'courier') + (quote.amount !== undefined ? ' · ' + quote.amount + ' ' + (quote.currency || 'RON') : '');
        $('#generate-label-btn').text(label);
        $('#generate-label-section').prop('hidden', false);
        $('#create-expedition-btn').removeClass('button-primary').text('Get quotes again');
    }

    function busy(id, on) {
        $(id).prop('hidden', !on);
    }

    // ---------------------------------------------------------------------
    // Get quotes
    // ---------------------------------------------------------------------
    $('#create-expedition-btn').on('click', function() {
        var btn = $(this);
        var errors = [];

        $('.package-item').each(function(index) {
            var $item = $(this);
            var weight = parseFloat($item.find('input[name="package_weight[]"]').val());
            var width  = parseFloat($item.find('input[name="package_width[]"]').val());
            var height = parseFloat($item.find('input[name="package_height[]"]').val());
            var length = parseFloat($item.find('input[name="package_length[]"]').val());
            if (isNaN(weight) || weight < 1)  { errors.push('Parcel ' + (index + 1) + ': weight must be at least 1 kg'); }
            if (isNaN(width)  || width  < 10) { errors.push('Parcel ' + (index + 1) + ': width must be at least 10 cm'); }
            if (isNaN(height) || height < 10) { errors.push('Parcel ' + (index + 1) + ': height must be at least 10 cm'); }
            if (isNaN(length) || length < 10) { errors.push('Parcel ' + (index + 1) + ': length must be at least 10 cm'); }
        });
        var codAmount       = parseFloat($('input[name="cod_amount"]').val());
        var insuranceAmount = parseFloat($('input[name="insurance_amount"]').val());
        if (isNaN(codAmount)       || codAmount       < 0) { errors.push('COD amount must be at least 0'); }
        if (isNaN(insuranceAmount) || insuranceAmount < 0) { errors.push('Insurance must be at least 0'); }

        if (errors.length) {
            $('#expedition-result').html(errors.map(function(e) { return message('error', e); }).join(''));
            return;
        }

        btn.prop('disabled', true);
        busy('#expedition-loading', true);
        $('#expedition-result').html('');
        $('#label-result').html('');
        $('#quotes-section').prop('hidden', true);
        selectedQuoteId = null;
        actionsBeforeSelection();

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
                busy('#expedition-loading', false);
                btn.prop('disabled', false);
                if (response.success && response.data.blocked) {
                    // An unusable buyer pick: named, never guessed; the merchant decides
                    renderBanner(response.data.banner);
                    $('#expedition-result').html(message('warning', response.data.blocked));
                } else if (response.success && response.data.quotes && response.data.quotes.length > 0) {
                    quoteRequestId = response.data.quoteRequestId;
                    renderBanner(response.data.banner);
                    activeTab = 'ALL';
                    displayQuotes(response.data.quotes, response.data.tabs);
                    $('#quotes-section').prop('hidden', false);
                    $('#quote-selection-result').html('');
                    // Every quote is shown; the cheapest usable one is preselected (the merchant may pick another)
                    var preselect = autoQuote(response.data);
                    if (preselect) { selectQuote(preselect.id); }
                } else {
                    $('#expedition-result').html(errorHtml(response.data, 'Failed to get quotes'));
                }
            },
            error: function(xhr, status, error) {
                busy('#expedition-loading', false);
                btn.prop('disabled', false);
                var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? errorText(xhr.responseJSON.data, 'Request failed') : (error || ('Request failed: ' + status));
                $('#expedition-result').html(message('error', 'Error: ' + errorMsg));
            }
        });
    });

    // ---------------------------------------------------------------------
    // Quotes: nav tabs (count, "· Cheapest", empty tab disabled) and a list of
    // real radios; disabled quotes muted with the backend's reason and the
    // fill-in they need, never selectable
    // ---------------------------------------------------------------------
    var activeTab = 'ALL';
    var tabsData = [];
    var ROUTE_BADGES = { DOOR_TO_DOOR: 'Door to door', DOOR_TO_LOCKER: 'To a locker', LOCKER_TO_DOOR: 'From a locker', LOCKER_TO_LOCKER: 'Locker to locker' };

    // Usable first, then by price (the server keeps the API's order)
    function byUsableThenAmount(a, b) {
        if (!!a.usable !== !!b.usable) { return a.usable ? -1 : 1; }
        return (parseFloat(a.amount) || 0) - (parseFloat(b.amount) || 0);
    }

    function displayQuotes(quotes, tabs) {
        quotesData = quotes.slice().sort(byUsableThenAmount);
        quotes = quotesData;
        if (tabs) { tabsData = tabs; }
        var html = '<div class="nav-tab-wrapper lp-tabs" role="tablist">';
        tabsData.forEach(function(tab) {
            var active = tab.key === activeTab;
            html += '<button type="button" role="tab" class="nav-tab livraria-quote-tab' + (active ? ' nav-tab-active' : '') + '" data-tab="' + esc(tab.key) + '" aria-selected="' + (active ? 'true' : 'false') + '"' + (tab.count === 0 ? ' disabled' : '') + '>' +
                esc(tab.label) + ' <span class="lp-tab__count">(' + esc(tab.count) + ')</span>' + (tab.cheapest ? ' <span class="lp-tab__cheapest">· Cheapest</span>' : '') + '</button>';
        });
        html += '</div><fieldset class="lp-quotes" role="radiogroup"><legend class="screen-reader-text">Choose a quote</legend>';
        quotes.forEach(function(quote, index) {
            if (activeTab !== 'ALL' && quote.deliveryType !== activeTab) { return; }
            var name = esc(quote.courierName || ('Courier ' + (index + 1)));
            var route = '<span class="lp-quote__route">' + esc(ROUTE_BADGES[quote.deliveryType] || quote.deliveryType || '') + '</span>';
            var eta = quote.deliveryDays !== undefined ? '<span class="lp-quote__eta">' + esc(quote.deliveryDays) + ' day(s)</span>' : '';
            if (!quote.usable) {
                var fill = quote.needsEmail ? fillInControl('email') : (quote.needsField ? fillInControl(quote.needsField) : '');
                html += '<div class="lp-quote quote-item is-disabled" data-quote-id="' + esc(quote.id) + '" aria-disabled="true">' +
                    '<input type="radio" name="livraria_quote" disabled aria-label="' + name + ' (unavailable)">' +
                    '<span class="lp-quote__main"><span class="lp-quote__courier">' + name + '</span>' + route + '<span class="lp-quote__state">Unavailable</span></span>' +
                    '<span class="lp-quote__price">—</span><span></span>' +
                    '<span class="lp-quote__reason quote-disabled-reason">' + esc(quote.disabledReason) + '</span>' + fill +
                    '</div>';
                return;
            }
            var selected = selectedQuoteId === quote.id;
            var price = quote.amount !== undefined ? esc(quote.amount) + ' ' + esc(quote.currency || 'RON') : '';
            html += '<label class="lp-quote quote-item' + (selected ? ' is-selected' : '') + '" data-quote-id="' + esc(quote.id) + '">' +
                '<input type="radio" name="livraria_quote" value="' + esc(quote.id) + '"' + (selected ? ' checked' : '') + '>' +
                '<span class="lp-quote__main"><span class="lp-quote__courier">' + name + '</span>' + route + eta + '</span>' +
                '<span class="lp-quote__price">' + price + '</span>' +
                '<span class="lp-quote__check" aria-hidden="true">✓</span>' +
                '</label>';
        });
        html += '</fieldset>';
        $('#quotes-container').html(html);
    }

    $(document).on('click', '.livraria-quote-tab', function() {
        activeTab = $(this).attr('data-tab');
        displayQuotes(quotesData);
    });

    $(document).on('change', 'input[name="livraria_quote"]', function() {
        selectQuote($(this).val());
    });

    // Select a quote with the API (its own route's quote request), then offer "Create AWB"
    function selectQuote(quoteId) {
        var selectedQuote = quotesData.find(function(q) { return q.id === quoteId; });
        if (!selectedQuote || !selectedQuote.usable) {
            return; // a disabled quote is never selected
        }
        if (!quoteRequestId && !selectedQuote.quoteRequestId) {
            $('#expedition-result').html(message('error', 'Quote request ID not found'));
            return;
        }
        $('input[name="livraria_quote"]').prop('disabled', true);
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'livraria_select_quote',
                order_id: orderId,
                quote_request_id: selectedQuote.quoteRequestId || quoteRequestId,
                courier_quote_id: quoteId,
                courier_name: selectedQuote.courierName || '',
                sender_profile_id: $('#livraria-sender-profile-select').val() || '',
                nonce: $('#courier_expedition_nonce_field').val()
            },
            success: function(response) {
                if (response.success) {
                    selectedQuoteId = quoteId;
                    displayQuotes(quotesData);
                    actionsAfterSelection(selectedQuote);
                    $('#quote-selection-result').html('');
                } else {
                    displayQuotes(quotesData);
                    $('#quote-selection-result').html(message('error', 'Error: ' + errorText(response.data, 'Failed to select quote')));
                }
            },
            error: function(xhr, status, error) {
                displayQuotes(quotesData);
                var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? errorText(xhr.responseJSON.data, 'Request failed') : (error || ('Request failed: ' + status));
                $('#quote-selection-result').html(message('error', 'Error: ' + errorMsg));
            }
        });
    }

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
        var $notice = $('<div class="notice inline lp-notice"></div>').addClass(banner.type === 'buyer' ? 'notice-info' : 'notice-warning');
        $notice.append($('<p></p>').text(banner.message));
        if (banner.type === 'unusable' || banner.type === 'buyer') {
            $notice.append($('<p></p>').append($('<button type="button" class="button-link livraria-ship-to-address"></button>').text('Ship to the order address instead')));
        }
        if (banner.type === 'changed' && buyerPick) {
            $notice.append($('<p></p>').append($('<button type="button" class="button-link livraria-use-buyer-pick"></button>').text(buyerPick.supported ? "Use the buyer's locker again" : 'Undo')));
        }
        $box.append($notice);
    }

    // The banner for the destination in use, before any quote (the server sends its own with the quotes)
    function localBanner() {
        if (!buyerPick) { return null; }
        if (!buyerPick.supported) {
            return route.destination.mode === 'buyer'
                ? { type: 'unusable', message: buyerPick.message }
                : { type: 'changed', message: 'You chose not to use the buyer\'s locker (' + buyerPick.display + ', which cannot be used).' };
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
        $('#quotes-section').prop('hidden', true);
        $('#quotes-container').html('');
        $('#expedition-result').html('');
        actionsBeforeSelection();
    }

    function renderEnd(end) {
        var $box = $('#livraria-' + end + '-locker');
        $box.empty();
        if (route[end].mode !== 'locker') { return; }
        var locker = route[end].locker;
        if (locker) {
            $box.append($('<span class="lp-locker"></span>').text((route[end].suggested ? 'Nearest: ' : '') + lockerLabel(locker)));
        } else if (route[end].status) {
            $box.append($('<span class="lp-note"></span>').text(route[end].status));
        }
        $box.append($('<button type="button" class="button-link livraria-choose-locker"></button>').attr('data-end', end).text(locker ? 'Change' : 'Choose locker'));
        if (end === 'origin' && locker && locker.courier === 'sameday') {
            $box.append($('<span class="lp-note"></span>').text('Suggested drop-off point: the parcel may be left at any easybox.'));
        }
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
        $root.prop('hidden', false);
        picker = window.LivrariaLockerPicker.mount($root[0], $.extend({}, livrariaOrder.lockerPicker, {
            onChange: function(pick) {
                if (!pick.id || !pickerFor) { return; }
                route[pickerFor].locker = { id: pick.id, name: pick.name, courier: pick.courier };
                route[pickerFor].suggested = false;
                renderEnd(pickerFor);
                renderBanner(localBanner());
                resetQuotes();
                picker.destroy();
                $root.prop('hidden', true);
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

    // ---------------------------------------------------------------------
    // "Add email" / "Add postcode" …: saved on the WooCommerce order, then quote again
    // ---------------------------------------------------------------------
    $(document).on('click', '.livraria-fill-in-save', function() {
        var btn = $(this);
        var $box = btn.closest('.livraria-fill-in');
        var field = $box.attr('data-field');
        var value = $.trim($box.find('.livraria-fill-in-value').val() || '');
        var $result = $box.find('.livraria-fill-in-result');
        if (!value) {
            $result.attr('class', 'livraria-fill-in-result lp-error').text('Enter a value first.');
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
                    $result.attr('class', 'livraria-fill-in-result lp-success').text(errorText(response.data, 'Saved on the order.') + ' Getting quotes again…');
                    selectedQuoteId = null;
                    actionsBeforeSelection();
                    $('#create-expedition-btn').trigger('click');
                } else {
                    $result.attr('class', 'livraria-fill-in-result lp-error').text(errorText(response.data, 'Could not save it on the order.'));
                    btn.prop('disabled', false);
                }
            },
            error: function(xhr) {
                $result.attr('class', 'livraria-fill-in-result lp-error').text(errorText(xhr.responseJSON && xhr.responseJSON.data, 'Could not save it on the order.'));
                btn.prop('disabled', false);
            }
        });
    });

    // ---------------------------------------------------------------------
    // "Reload profiles": sender profiles are rendered from the cache only
    // ---------------------------------------------------------------------
    $(document).on('click', '#livraria-reload-profiles', function() {
        var btn = $(this);
        var $result = $('#livraria-reload-profiles-result');
        btn.prop('disabled', true);
        $result.attr('class', 'lp-inline-result').text('Loading…');
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: { action: 'livraria_reload_sender_profiles', order_id: orderId, nonce: $('#courier_expedition_nonce_field').val() },
            success: function(response) {
                if (!response.success) {
                    $result.attr('class', 'lp-inline-result lp-error').text(errorText(response.data, 'Could not load the sender profiles.'));
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
                    .append($('<p class="description"></p>').text('Billing and pickup use this profile.'));
            },
            error: function(xhr) {
                $result.attr('class', 'lp-inline-result lp-error').text(errorText(xhr.responseJSON && xhr.responseJSON.data, 'Could not load the sender profiles.'));
                btn.prop('disabled', false);
            }
        });
    });

    // ---------------------------------------------------------------------
    // Create AWB (the selected quote)
    // ---------------------------------------------------------------------
    $('#generate-label-btn').on('click', function() {
        var btn = $(this);
        $('#label-result').html('');
        if (!selectedQuoteId) {
            $('#label-result').html(message('error', 'Select a quote first.'));
            return;
        }
        btn.prop('disabled', true);
        $('#create-expedition-btn').prop('disabled', true);
        busy('#label-loading', true);
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: { action: 'livraria_generate_label', order_id: orderId, nonce: $('#courier_expedition_nonce_field').val() },
            success: function(response) {
                busy('#label-loading', false);
                if (response.success) {
                    $('#label-result').html(message('success', 'AWB created: ' + (response.data.awb_number || 'N/A') + '. Reloading…'));
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    $('#label-result').html(errorHtml(response.data, 'Failed to create the AWB'));
                    btn.prop('disabled', false);
                    $('#create-expedition-btn').prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                busy('#label-loading', false);
                var errorMsg = (xhr.responseJSON && xhr.responseJSON.data) ? errorText(xhr.responseJSON.data, 'Request failed') : (error || ('Request failed: ' + status));
                $('#label-result').html(message('error', 'Error: ' + errorMsg));
                btn.prop('disabled', false);
                $('#create-expedition-btn').prop('disabled', false);
            }
        });
    });
});
