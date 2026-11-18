/*
 * Classic (shortcode) checkout: mounts the locker picker after the customer
 * details and keeps the four hidden fields the server reads in step. The
 * picker shows only while the chosen shipping rate is one the merchant
 * ticked (Settings > Livraria) or the plugin's "Livrare la locker" method;
 * otherwise it is hidden and the pick cleared. On "Livrare la locker" it is a
 * modal with prices, and each pick goes to the server (selectAction) before
 * the checkout refreshes, so the rate costs what the chosen locker costs.
 */
jQuery(function ($) {
    var root = document.getElementById('livraria-locker-picker');
    if (!root || !window.LivrariaLockerPicker || !window.livrariaLockerConfig) {
        return;
    }
    var config = window.livrariaLockerConfig;
    var methods = config.methods || [];
    var lockerMethod = config.lockerMethod || '';
    var mounted = null;
    var mountedFor = '';
    var lastPick = config.pricedPick || null;
    var sent = pickKey(config.pricedPick);

    function pickKey(pick) {
        return pick && pick.mode === 'locker' && pick.id ? pick.courier + ':' + pick.id : '';
    }

    function write(pick) {
        $('#livraria_delivery_mode').val(pick.mode);
        $('#livraria_locker_id').val(pick.id);
        $('#livraria_locker_name').val(pick.name);
        $('#livraria_locker_courier').val(pick.courier);
    }

    // The chosen rate: the checked radio, the select, or the single hidden input
    function chosenRate() {
        var $field = $('input[name^="shipping_method"]:checked, select[name^="shipping_method"], input[type="hidden"][name^="shipping_method"]').first();
        return $field.length ? String($field.val() || '') : '';
    }

    function isLockerRate(rate) {
        return lockerMethod !== '' && (rate === lockerMethod || rate.indexOf(lockerMethod + ':') === 0);
    }

    function offersLockers(rate) {
        return isLockerRate(rate) || methods.some(function (m) { return rate === m || rate.indexOf(m + ':') === 0; });
    }

    // "Livrare la locker": the server prices the rate for the locker, then the checkout refreshes
    function select(pick) {
        var key = pickKey(pick);
        if (key === sent) { return; }
        sent = key;
        $.post(config.ajaxUrl, {
            action: config.selectAction,
            nonce: config.nonce,
            delivery_mode: pick.mode,
            locker_id: pick.id,
            locker_name: pick.name,
            locker_courier: pick.courier
        }).always(function () { $(document.body).trigger('update_checkout'); });
    }

    function sync() {
        var rate = chosenRate();
        if (offersLockers(rate)) {
            root.hidden = false;
            var priced = isLockerRate(rate);
            var key = priced ? rate : 'ticked';
            if (mounted && mountedFor !== key) { mounted.destroy(); mounted = null; }
            if (!mounted) {
                mountedFor = key;
                mounted = window.LivrariaLockerPicker.mount(root, $.extend({}, config, priced ? {
                    modal: true, showModeChoice: false, requireLocker: true, rate: rate, pick: lastPick,
                    onChange: function (pick) { lastPick = pick; write(pick); select(pick); }
                } : {
                    onChange: write
                }));
            }
        } else {
            root.hidden = true;
            if (mounted) { mounted.destroy(); mounted = null; mountedFor = ''; }
            write({ mode: '', id: '', name: '', courier: '' });
        }
    }

    $(document.body).on('updated_checkout updated_shipping_method', sync);
    $(document.body).on('change', 'input[name^="shipping_method"], select[name^="shipping_method"]', sync);
    sync();
});
