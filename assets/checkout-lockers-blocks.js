/*
 * Blocks checkout: renders the locker picker in the checkout block's order
 * summary (slot ExperimentalOrderMeta) and sends the pick to the server as
 * Store API extension data, namespace "livraria". No build step: plain
 * wp.element calls.
 */
(function (wp, wc) {
    if (!wp || !wc || !wc.blocksCheckout || !wp.plugins || !window.LivrariaLockerPicker) {
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

    function LockerPicker() {
        var ref = useRef(null);
        useEffect(function () {
            if (!ref.current) { return undefined; }
            var picker = window.LivrariaLockerPicker.mount(ref.current, Object.assign({}, config, { onChange: send }));
            return function () { picker.destroy(); };
        }, []);
        return el('div', { ref: ref, className: 'livraria-locker-picker', 'data-context': 'blocks' });
    }

    wp.plugins.registerPlugin('livraria-locker-picker', {
        scope: 'woocommerce-checkout',
        render: function () {
            return el(wc.blocksCheckout.ExperimentalOrderMeta, null, el(LockerPicker));
        }
    });
}(window.wp, window.wc));
