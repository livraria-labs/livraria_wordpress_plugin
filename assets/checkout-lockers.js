/*
 * Classic (shortcode) checkout: mounts the locker picker after the customer
 * details and keeps the four hidden fields the server reads in step. The
 * picker shows only while the chosen shipping rate is one the merchant
 * ticked (Settings > Livraria); otherwise it is hidden and the pick cleared.
 */
jQuery(function ($) {
    var root = document.getElementById('livraria-locker-picker');
    if (!root || !window.LivrariaLockerPicker || !window.livrariaLockerConfig) {
        return;
    }
    var config = window.livrariaLockerConfig;
    var methods = config.methods || [];
    var mounted = null;

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

    function offersLockers(rate) {
        return methods.some(function (m) { return rate === m || rate.indexOf(m + ':') === 0; });
    }

    function sync() {
        if (offersLockers(chosenRate())) {
            root.hidden = false;
            if (!mounted) {
                mounted = window.LivrariaLockerPicker.mount(root, $.extend({}, config, { onChange: write }));
            }
        } else {
            root.hidden = true;
            if (mounted) { mounted.destroy(); mounted = null; }
            write({ mode: '', id: '', name: '', courier: '' });
        }
    }

    $(document.body).on('updated_checkout updated_shipping_method', sync);
    $(document.body).on('change', 'input[name^="shipping_method"], select[name^="shipping_method"]', sync);
    sync();
});
