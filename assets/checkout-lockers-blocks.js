/*
 * Blocks checkout: renders the locker picker in the Shipping options section,
 * under the shipping rates (slot ExperimentalOrderShippingPackages), where
 * the buyer chooses how the parcel travels, and sends the pick to the server
 * as Store API extension data, namespace "livraria". If the slot renders once
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

    function LockerPicker() {
        var ref = useRef(null);
        var id = useRef(0);
        if (!id.current) { id.current = nextId++; }
        if (owner === null) { owner = id.current; }
        var mine = owner === id.current;
        useEffect(function () {
            if (!mine || !ref.current) { return undefined; }
            var picker = window.LivrariaLockerPicker.mount(ref.current, Object.assign({}, config, { onChange: send }));
            return function () {
                picker.destroy();
                if (owner === id.current) { owner = null; }
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
