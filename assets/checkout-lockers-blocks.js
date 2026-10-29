/*
 * Blocks checkout: renders the locker picker in the Shipping options section,
 * under the shipping rates (slot ExperimentalOrderShippingPackages), where
 * the buyer chooses how the parcel travels, and sends the pick to the server
 * as Store API extension data, namespace "livraria". It shows only while the
 * selected rate is a shipping method the merchant ticked (settings data
 * "methods"); otherwise it hides and clears the pick. If the slot renders once
 * per shipping package, only the first instance shows the picker. No build
 * step: plain wp.element calls.
 */
(function (wp, wc) {
    if (!wp || !wc || !wc.blocksCheckout || !wc.blocksCheckout.ExperimentalOrderShippingPackages || !wp.plugins || !window.LivrariaLockerPicker) {
        return;
    }
    var el = wp.element.createElement;
    var useEffect = wp.element.useEffect;
    var useRef = wp.element.useRef;
    var config = (wc.wcSettings && wc.wcSettings.getSetting) ? wc.wcSettings.getSetting('livraria-lockers_data', null) : null;
    if (!config || !config.couriers || !config.couriers.length) {
        return;
    }

    function send(pick) {
        var data = {
            delivery_mode: pick.mode,
            locker_id: pick.id,
            locker_name: pick.name,
            locker_courier: pick.courier
        };
        var checkout = wp.data.dispatch('wc/store/checkout');
        if (checkout && typeof checkout.setExtensionData === 'function') {
            checkout.setExtensionData('livraria', data);
        } else if (checkout && typeof checkout.__internalSetExtensionData === 'function') {
            checkout.__internalSetExtensionData('livraria', data);
        }
    }

    // One picker per page, whatever the number of fills the slot renders
    var owner = null;
    var nextId = 1;

    var methods = config.methods || [];

    function offersLockers(rate) {
        rate = String(rate || '');
        return methods.some(function (m) { return rate === m || rate.indexOf(m + ':') === 0; });
    }

    // The selected rate of the first package, from the cart store
    function selectedRate(select) {
        var packages = select('wc/store/cart').getShippingRates() || [];
        var rates = packages.length ? (packages[0].shipping_rates || []) : [];
        for (var i = 0; i < rates.length; i++) {
            if (rates[i].selected) { return rates[i].rate_id; }
        }
        return '';
    }

    function LockerPicker() {
        var ref = useRef(null);
        var id = useRef(0);
        if (!id.current) { id.current = nextId++; }
        if (owner === null) { owner = id.current; }
        var rate = wp.data.useSelect(function (select) { return selectedRate(select); }, []);
        var shown = offersLockers(rate);
        var mine = owner === id.current && shown;
        useEffect(function () {
            return function () { if (owner === id.current) { owner = null; } };
        }, []);
        useEffect(function () {
            if (owner === id.current && !shown) {
                send({ mode: '', id: '', name: '', courier: '' }); // hidden: no pick rides on the order
            }
        }, [shown]);
        useEffect(function () {
            if (!mine || !ref.current) { return undefined; }
            var picker = window.LivrariaLockerPicker.mount(ref.current, Object.assign({}, config, { onChange: send }));
            return function () {
                picker.destroy();
            };
        }, [mine]);
        return mine ? el('div', { ref: ref, className: 'livraria-locker-picker', 'data-context': 'blocks' }) : null;
    }

    wp.plugins.registerPlugin('livraria-locker-picker', {
        scope: 'woocommerce-checkout',
        render: function () {
            return el(wc.blocksCheckout.ExperimentalOrderShippingPackages, null, el(LockerPicker));
        }
    });
}(window.wp, window.wc));
