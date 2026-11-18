/*
 * Livraria locker picker: the county first (a small list), then that
 * county's lockers of both couriers with courier chips, a city filter, a
 * name or address search within the county, clear buttons and one
 * chosen-locker line with "change". The full list (over 8,000 lockers) is
 * never sent to the browser. Used by the classic checkout, the blocks
 * checkout and the order panel's Route pickers. No dependencies.
 *
 * modal: true (the "Livrare la locker" shipping method) puts the list in a
 * dialog opened from a one-line summary, and with ratesAction each courier
 * chip and locker row carries what that courier's locker costs the buyer;
 * couriers the server leaves out of the prices cannot carry this cart and
 * are hidden. The same file is copied into every shop plugin.
 *
 * window.LivrariaLockerPicker.mount(element, options) -> { setPick, getPick, destroy }
 * options: ajaxUrl, action, nonce, couriers [{slug, label}], requireLocker,
 *          showModeChoice, strings, pick {mode, id, name, courier},
 *          onChange(pick, locker), modal, ratesAction, rate (sent with ratesAction),
 *          loadPrices(ctx, cb)
 * onChange's second argument is the full locker row the buyer picked (street,
 * postcode, city, ... as the server sent it), null when there is no pick.
 * loadPrices(ctx, cb), optional, replaces the ratesAction request for shops
 * that price in the browser: ctx = {county, city, postcode, rate}, and cb is
 * called once with {slug: {amount, label}} (a slug with an empty label stays
 * offered, unpriced) or null for no prices.
 */
(function (window, document) {
    'use strict';

    var CAP = 50;
    var DIACRITICS = { 'ă': 'a', 'Ă': 'A', 'â': 'a', 'Â': 'A', 'î': 'i', 'Î': 'I', 'ș': 's', 'Ș': 'S', 'ş': 's', 'Ş': 'S', 'ț': 't', 'Ț': 'T', 'ţ': 't', 'Ţ': 'T' };

    function fold(value) {
        return String(value || '').split('').map(function (c) { return DIACRITICS[c] || c; }).join('').toUpperCase();
    }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            if (key === 'text') { node.textContent = attrs[key]; }
            else if (key === 'className') { node.className = attrs[key]; }
            else if (key.indexOf('on') === 0) { node.addEventListener(key.slice(2), attrs[key]); }
            else { node.setAttribute(key, attrs[key]); }
        });
        (children || []).forEach(function (child) { if (child) { node.appendChild(child); } });
        return node;
    }

    function format(template, values) {
        return String(template).replace(/\{(\w+)\}/g, function (m, key) { return values[key] !== undefined ? values[key] : m; });
    }

    function mount(root, options) {
        var o = options || {};
        var s = o.strings || {};
        var couriers = o.couriers || [];
        var labels = {};
        couriers.forEach(function (c) { labels[c.slug] = c.label; });
        var state = {
            mode: o.requireLocker ? 'locker' : ((o.pick && o.pick.mode) || (o.showModeChoice === false ? 'locker' : 'home')),
            pick: o.pick && o.pick.id ? { id: o.pick.id, name: o.pick.name, courier: o.pick.courier } : null,
            counties: null, byCounty: {}, loading: false, error: '',
            courier: '', county: '', city: '', search: '',
            open: false, prices: {}
        };
        var overlay = null;
        var dialogBody = null;
        var opener = null;

        function emit() {
            if (typeof o.onChange === 'function') {
                o.onChange({
                    mode: state.mode,
                    id: state.mode === 'locker' && state.pick ? state.pick.id : '',
                    name: state.mode === 'locker' && state.pick ? state.pick.name : '',
                    courier: state.mode === 'locker' && state.pick ? state.pick.courier : ''
                }, state.mode === 'locker' && state.pick && state.pick.locker ? state.pick.locker : null);
            }
        }

        function pricesWanted() {
            return !!(o.ratesAction || typeof o.loadPrices === 'function');
        }

        // GET the proxy: no county = the county list; a county = its lockers
        function load(county) {
            state.loading = true;
            state.error = '';
            render();
            var url = o.ajaxUrl + (o.ajaxUrl.indexOf('?') === -1 ? '?' : '&') + 'action=' + encodeURIComponent(o.action) +
                '&nonce=' + encodeURIComponent(o.nonce || '') + '&county=' + encodeURIComponent(county || '');
            var xhr = new XMLHttpRequest();
            xhr.open('GET', url);
            xhr.onload = function () {
                state.loading = false;
                var body = null;
                try { body = JSON.parse(xhr.responseText); } catch (e) { body = null; }
                var data = xhr.status === 200 && body && body.success ? body.data : null;
                if (data && !county && data.counties) {
                    state.counties = data.counties;
                } else if (data && county && data.lockers) {
                    state.byCounty[county] = data.lockers.filter(function (l) { return labels[l.courier]; });
                } else {
                    state.error = s.error || 'Could not load the lockers.';
                }
                render();
            };
            xhr.onerror = function () {
                state.loading = false;
                state.error = s.error || 'Could not load the lockers.';
                render();
            };
            xhr.send();
        }

        function retry() {
            load(state.counties === null ? '' : state.county);
        }

        function filtered() {
            var q = fold(state.search.trim());
            return (state.byCounty[state.county] || []).filter(function (l) {
                return (!state.courier || l.courier === state.courier) &&
                    (!state.city || l.city === state.city) &&
                    (!q || fold(l.name + ' ' + l.street + ' ' + l.city).indexOf(q) !== -1);
            });
        }

        function uniqueSorted(values) {
            var seen = {};
            return values.filter(function (v) { if (!v || seen[v]) { return false; } seen[v] = true; return true; })
                .sort(function (a, b) { return a.localeCompare(b, 'ro'); });
        }

        function display(locker) {
            return ((labels[locker.courier] || 'locker') + ' ' + (locker.name || locker.id)).trim();
        }

        // Prices are asked per county and city: the city filter, else the
        // county's city with the most lockers (where most buyers are)
        function pricingCity() {
            if (state.city) { return state.city; }
            var counts = {};
            var best = '';
            (state.byCounty[state.county] || []).forEach(function (l) {
                counts[l.city] = (counts[l.city] || 0) + 1;
                if (!best || counts[l.city] > counts[best]) { best = l.city; }
            });
            return best;
        }

        function priceKey() {
            return state.county + '|' + pricingCity();
        }

        function loadPrices() {
            if (!pricesWanted() || !state.county || state.prices[priceKey()]) { return; }
            var key = priceKey();
            var entry = { loading: true, map: null };
            state.prices[key] = entry;
            if (typeof o.loadPrices === 'function') {
                var city = pricingCity();
                var postcode = '';
                (state.byCounty[state.county] || []).forEach(function (l) {
                    if (!postcode && l.city === city && l.postcode) { postcode = l.postcode; }
                });
                var finished = false;
                var done = function (map) {
                    if (finished) { return; }
                    finished = true;
                    entry.loading = false;
                    entry.map = map && typeof map === 'object' ? map : null;
                    render();
                };
                try { o.loadPrices({ county: state.county, city: city, postcode: postcode, rate: o.rate || '' }, done); }
                catch (e) { done(null); }
                return;
            }
            var url = o.ajaxUrl + (o.ajaxUrl.indexOf('?') === -1 ? '?' : '&') + 'action=' + encodeURIComponent(o.ratesAction) +
                '&nonce=' + encodeURIComponent(o.nonce || '') + '&rate=' + encodeURIComponent(o.rate || '') +
                '&county=' + encodeURIComponent(state.county) + '&city=' + encodeURIComponent(pricingCity());
            var xhr = new XMLHttpRequest();
            xhr.open('GET', url);
            xhr.onload = function () {
                var body = null;
                try { body = JSON.parse(xhr.responseText); } catch (e) { body = null; }
                entry.loading = false;
                entry.map = xhr.status === 200 && body && body.success && body.data && body.data.prices ? body.data.prices : null;
                render();
            };
            xhr.onerror = function () { entry.loading = false; render(); };
            xhr.send();
        }

        // null: no prices (not asked, loading or failed): every courier shows, without a price
        function priceMap() {
            var entry = pricesWanted() && state.county ? state.prices[priceKey()] : null;
            return entry && entry.map ? entry.map : null;
        }

        function offered(slug) {
            var map = priceMap();
            return !map || !!map[slug];
        }

        function priceLabel(slug) {
            var map = priceMap();
            return map && map[slug] ? map[slug].label : '';
        }

        function onKey(e) {
            if (e.key === 'Escape' || e.keyCode === 27) { closeDialog(); }
        }

        function openDialog(e) {
            opener = e && e.target ? e.target : null;
            state.open = true;
            render();
        }

        function closeDialog() {
            state.open = false;
            render();
            if (opener && document.body.contains(opener)) { opener.focus(); }
            else { var again = root.querySelector('button'); if (again) { again.focus(); } }
        }

        function removeDialog() {
            if (overlay) {
                document.removeEventListener('keydown', onKey);
                if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
                overlay = null;
                dialogBody = null;
            }
        }

        function ensureDialog() {
            if (overlay) { return; }
            dialogBody = el('div', { className: 'livraria-lp-dialog-body' });
            var dialog = el('div', { className: 'livraria-lp-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-label': s.dialogTitle || '', tabindex: '-1' }, [
                el('div', { className: 'livraria-lp-dialog-head' }, [
                    el('strong', { text: s.dialogTitle || '' }),
                    el('button', { type: 'button', className: 'livraria-lp-close', 'aria-label': s.close || 'Close', text: '×', onclick: closeDialog })
                ]),
                dialogBody
            ]);
            overlay = el('div', { className: 'livraria-lp-overlay', onclick: function (e) { if (e.target === overlay) { closeDialog(); } } }, [dialog]);
            document.body.appendChild(overlay);
            document.addEventListener('keydown', onKey);
            dialog.focus();
        }

        // Modal mode: the line on the checkout (the chosen locker, or the gate) and the button
        function renderSummary() {
            root.innerHTML = '';
            var box = el('div', { className: 'livraria-lp livraria-lp-summary' });
            if (state.pick) {
                var price = state.pick.priceLabel ? ' — ' + state.pick.priceLabel : '';
                box.appendChild(el('div', { className: 'livraria-lp-chosen' }, [
                    el('span', { text: (s.chosen || '') + ' ' + display(state.pick) + price }),
                    el('button', { type: 'button', className: 'button livraria-lp-change', text: s.change || 'Change', onclick: openDialog })
                ]));
            } else {
                box.appendChild(el('p', { className: 'livraria-lp-gate', role: 'status', text: s.gate || '' }));
                box.appendChild(el('button', { type: 'button', className: 'button livraria-lp-open', text: s.open || s.select || 'Select', onclick: openDialog }));
            }
            root.appendChild(box);
        }

        function render() {
            if (!o.modal) { renderPanel(root); return; }
            renderSummary();
            if (state.open) {
                var focused = document.activeElement && overlay && overlay.contains(document.activeElement);
                ensureDialog();
                renderPanel(dialogBody);
                if (!focused && overlay) { overlay.firstChild.focus(); }
            } else {
                removeDialog();
            }
        }

        function choose(l) {
            state.pick = { id: l.id, name: l.name, courier: l.courier, locker: l, priceLabel: priceLabel(l.courier) };
            emit();
            if (o.modal) { closeDialog(); } else { render(); }
        }

        function renderPanel(host) {
            host.innerHTML = '';
            var box = el('div', { className: 'livraria-lp' });
            host.appendChild(box);

            if (o.showModeChoice !== false && !o.requireLocker) {
                var name = 'livraria-lp-mode-' + Math.random().toString(36).slice(2);
                var modes = el('div', { className: 'livraria-lp-modes', role: 'radiogroup', 'aria-label': s.modeLabel || '' });
                [['home', s.modeHome], ['locker', s.modeLocker]].forEach(function (m) {
                    var input = el('input', { type: 'radio', name: name, value: m[0] });
                    input.checked = state.mode === m[0];
                    input.addEventListener('change', function () {
                        state.mode = m[0];
                        if (state.mode === 'home') { state.pick = null; }
                        emit();
                        render();
                    });
                    modes.appendChild(el('label', { className: 'livraria-lp-mode' }, [input, el('span', { text: ' ' + m[1] })]));
                });
                box.appendChild(el('strong', { text: s.modeLabel || '' }));
                box.appendChild(modes);
            }
            if (state.mode !== 'locker') { return; }

            if (state.pick && !o.modal) {
                box.appendChild(el('div', { className: 'livraria-lp-chosen' }, [
                    el('span', { text: (s.chosen || '') + ' ' + display(state.pick) }),
                    el('button', { type: 'button', className: 'button livraria-lp-change', text: s.change || 'Change', onclick: function () { state.pick = null; emit(); render(); } })
                ]));
                return;
            }

            if (!o.modal) { box.appendChild(el('p', { className: 'livraria-lp-gate', role: 'status', text: s.gate || '' })); }
            if (state.counties === null && !state.loading && !state.error) { load(''); return; }
            if (state.error) {
                box.appendChild(el('p', { className: 'livraria-lp-error', text: state.error }));
                box.appendChild(el('button', { type: 'button', className: 'button', text: s.retry || 'Retry', onclick: retry }));
                return;
            }
            if (state.counties === null) { box.appendChild(el('p', { text: s.loading || '…' })); return; }

            // 1. the county
            var county = el('select', { 'aria-label': s.countyLabel || '', onchange: function (e) {
                state.county = e.target.value; state.city = ''; state.search = ''; state.courier = '';
                if (state.county && !state.byCounty[state.county]) { load(state.county); } else { render(); }
            } });
            county.appendChild(el('option', { value: '', text: s.countyPick || s.countyAll || '' }));
            state.counties.forEach(function (c) {
                var opt = el('option', { value: c.name, text: c.name + ' (' + c.count + ')' }); opt.selected = c.name === state.county; county.appendChild(opt);
            });
            box.appendChild(el('div', { className: 'livraria-lp-filters' }, [el('label', {}, [el('span', { text: (s.countyLabel || '') + ' ' }), county])]));
            if (!state.county) { return; }
            if (state.loading || !state.byCounty[state.county]) { box.appendChild(el('p', { text: s.loading || '…' })); return; }

            // 2. within the county: courier chips (with prices), city, search
            loadPrices();
            var priced = pricesWanted() ? state.prices[priceKey()] : null;
            if (priced && priced.loading) { box.appendChild(el('p', { className: 'livraria-lp-prices-loading', text: s.pricesLoading || '…' })); }
            var inCounty = state.byCounty[state.county].filter(function (l) { return offered(l.courier); });
            if (state.courier && !offered(state.courier)) { state.courier = ''; }
            var chips = el('div', { className: 'livraria-lp-chips' });
            [{ slug: '', label: s.filterAll || 'All' }].concat(couriers.filter(function (c) { return offered(c.slug); })).forEach(function (c) {
                var price = c.slug ? priceLabel(c.slug) : '';
                chips.appendChild(el('button', { type: 'button', className: 'livraria-lp-chip' + (state.courier === c.slug ? ' is-active' : ''), 'aria-pressed': state.courier === c.slug ? 'true' : 'false', text: c.label + (price ? ' · ' + price : ''), onclick: function () { state.courier = c.slug; render(); } }));
            });
            box.appendChild(chips);

            var city = el('select', { 'aria-label': s.cityLabel || '', onchange: function (e) { state.city = e.target.value; render(); } });
            city.appendChild(el('option', { value: '', text: s.cityAll || '' }));
            uniqueSorted(inCounty.filter(function (l) { return !state.courier || l.courier === state.courier; }).map(function (l) { return l.city; })).forEach(function (c) {
                var opt = el('option', { value: c, text: c }); opt.selected = c === state.city; city.appendChild(opt);
            });
            var search = el('input', { type: 'search', placeholder: s.search || '', value: state.search });
            search.addEventListener('input', function (e) {
                state.search = e.target.value;
                var position = e.target.selectionStart;
                render();
                var again = (o.modal && dialogBody ? dialogBody : root).querySelector('input[type=search]');
                if (again) { again.focus(); try { again.setSelectionRange(position, position); } catch (err) { /* ignore */ } }
            });
            box.appendChild(el('div', { className: 'livraria-lp-filters' }, [
                el('label', {}, [el('span', { text: (s.cityLabel || '') + ' ' }), city]),
                search,
                el('button', { type: 'button', className: 'button livraria-lp-clear', text: s.clearFilters || 'Clear', onclick: function () { state.courier = ''; state.city = ''; state.search = ''; render(); } })
            ]));

            var list = filtered().filter(function (l) { return offered(l.courier); }).sort(function (a, b) { return a.name.localeCompare(b.name, 'ro'); });
            if (!list.length) { box.appendChild(el('p', { text: s.none || '' })); return; }
            if (list.length > CAP) { box.appendChild(el('p', { className: 'livraria-lp-cap', text: format(s.showingFirst || '', { cap: CAP, total: list.length }) })); }
            var ul = el('ul', { className: 'livraria-lp-list' });
            list.slice(0, CAP).forEach(function (l) {
                var price = priceLabel(l.courier);
                ul.appendChild(el('li', {}, [
                    el('span', { className: 'livraria-lp-name', text: display(l) }),
                    price ? el('span', { className: 'livraria-lp-price', text: price }) : null,
                    el('span', { className: 'livraria-lp-address', text: [l.street, l.city, l.county].filter(Boolean).join(', ') }),
                    el('button', { type: 'button', className: 'button livraria-lp-select', text: s.select || 'Select', onclick: function () { choose(l); } })
                ]));
            });
            box.appendChild(ul);
        }

        render();
        emit();
        return {
            getPick: function () { return state.pick; },
            setPick: function (pick) { state.pick = pick && pick.id ? pick : null; state.mode = 'locker'; render(); },
            destroy: function () { removeDialog(); root.innerHTML = ''; }
        };
    }

    window.LivrariaLockerPicker = { mount: mount, fold: fold };
}(window, document));
