/*
 * Livraria locker picker: one merged Sameday + DPD list with courier chips,
 * county / city filters, a name or address search, clear buttons and one
 * chosen-locker line with "change". Used by the classic checkout, the blocks
 * checkout and the order panel's Route pickers. No dependencies.
 *
 * window.LivrariaLockerPicker.mount(element, options) -> { setPick, getPick, destroy }
 * options: ajaxUrl, action, nonce, couriers [{slug, label}], requireLocker,
 *          showModeChoice, strings, pick {mode, id, name, courier}, onChange(pick)
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
            lockers: null, loading: false, error: '',
            courier: '', county: '', city: '', search: ''
        };

        function emit() {
            if (typeof o.onChange === 'function') {
                o.onChange({
                    mode: state.mode,
                    id: state.mode === 'locker' && state.pick ? state.pick.id : '',
                    name: state.mode === 'locker' && state.pick ? state.pick.name : '',
                    courier: state.mode === 'locker' && state.pick ? state.pick.courier : ''
                });
            }
        }

        function load() {
            state.loading = true;
            state.error = '';
            render();
            var url = o.ajaxUrl + (o.ajaxUrl.indexOf('?') === -1 ? '?' : '&') + 'action=' + encodeURIComponent(o.action) + '&nonce=' + encodeURIComponent(o.nonce || '');
            var xhr = new XMLHttpRequest();
            xhr.open('GET', url);
            xhr.onload = function () {
                state.loading = false;
                var body = null;
                try { body = JSON.parse(xhr.responseText); } catch (e) { body = null; }
                if (xhr.status === 200 && body && body.success && body.data && body.data.lockers) {
                    state.lockers = body.data.lockers.filter(function (l) { return labels[l.courier]; });
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

        function filtered() {
            var q = fold(state.search.trim());
            return (state.lockers || []).filter(function (l) {
                return (!state.courier || l.courier === state.courier) &&
                    (!state.county || l.county === state.county) &&
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

        function render() {
            root.innerHTML = '';
            var box = el('div', { className: 'livraria-lp' });
            root.appendChild(box);

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

            if (state.pick) {
                box.appendChild(el('div', { className: 'livraria-lp-chosen' }, [
                    el('span', { text: (s.chosen || '') + ' ' + display(state.pick) }),
                    el('button', { type: 'button', className: 'button livraria-lp-change', text: s.change || 'Change', onclick: function () { state.pick = null; emit(); render(); } })
                ]));
                return;
            }

            box.appendChild(el('p', { className: 'livraria-lp-gate', role: 'status', text: s.gate || '' }));
            if (state.lockers === null && !state.loading && !state.error) { load(); return; }
            if (state.loading) { box.appendChild(el('p', { text: s.loading || '…' })); return; }
            if (state.error) {
                box.appendChild(el('p', { className: 'livraria-lp-error', text: state.error }));
                box.appendChild(el('button', { type: 'button', className: 'button', text: s.retry || 'Retry', onclick: load }));
                return;
            }

            var chips = el('div', { className: 'livraria-lp-chips' });
            [{ slug: '', label: s.filterAll || 'All' }].concat(couriers).forEach(function (c) {
                var chip = el('button', { type: 'button', className: 'livraria-lp-chip' + (state.courier === c.slug ? ' is-active' : ''), 'aria-pressed': state.courier === c.slug ? 'true' : 'false', text: c.label, onclick: function () { state.courier = c.slug; render(); } });
                chips.appendChild(chip);
            });
            box.appendChild(chips);

            var inCourier = (state.lockers || []).filter(function (l) { return !state.courier || l.courier === state.courier; });
            var county = el('select', { 'aria-label': s.countyLabel || '', onchange: function (e) { state.county = e.target.value; state.city = ''; render(); } });
            county.appendChild(el('option', { value: '', text: s.countyAll || '' }));
            uniqueSorted(inCourier.map(function (l) { return l.county; })).forEach(function (c) {
                var opt = el('option', { value: c, text: c }); opt.selected = c === state.county; county.appendChild(opt);
            });
            var city = el('select', { 'aria-label': s.cityLabel || '', onchange: function (e) { state.city = e.target.value; render(); } });
            city.appendChild(el('option', { value: '', text: s.cityAll || '' }));
            uniqueSorted(inCourier.filter(function (l) { return !state.county || l.county === state.county; }).map(function (l) { return l.city; })).forEach(function (c) {
                var opt = el('option', { value: c, text: c }); opt.selected = c === state.city; city.appendChild(opt);
            });
            var search = el('input', { type: 'search', placeholder: s.search || '', value: state.search });
            search.addEventListener('input', function (e) {
                state.search = e.target.value;
                var position = e.target.selectionStart;
                render();
                var again = root.querySelector('input[type=search]');
                if (again) { again.focus(); try { again.setSelectionRange(position, position); } catch (err) { /* ignore */ } }
            });
            box.appendChild(el('div', { className: 'livraria-lp-filters' }, [
                el('label', {}, [el('span', { text: (s.countyLabel || '') + ' ' }), county]),
                el('label', {}, [el('span', { text: (s.cityLabel || '') + ' ' }), city]),
                search,
                el('button', { type: 'button', className: 'button livraria-lp-clear', text: s.clearFilters || 'Clear', onclick: function () { state.courier = ''; state.county = ''; state.city = ''; state.search = ''; render(); } })
            ]));

            var list = filtered().sort(function (a, b) { return a.name.localeCompare(b.name, 'ro'); });
            if (!list.length) { box.appendChild(el('p', { text: s.none || '' })); return; }
            if (list.length > CAP) { box.appendChild(el('p', { className: 'livraria-lp-cap', text: format(s.showingFirst || '', { cap: CAP, total: list.length }) })); }
            var ul = el('ul', { className: 'livraria-lp-list' });
            list.slice(0, CAP).forEach(function (l) {
                ul.appendChild(el('li', {}, [
                    el('span', { className: 'livraria-lp-name', text: display(l) }),
                    el('span', { className: 'livraria-lp-address', text: [l.street, l.city, l.county].filter(Boolean).join(', ') }),
                    el('button', { type: 'button', className: 'button livraria-lp-select', text: s.select || 'Select', onclick: function () { state.pick = { id: l.id, name: l.name, courier: l.courier, locker: l }; emit(); render(); } })
                ]));
            });
            box.appendChild(ul);
        }

        render();
        emit();
        return {
            getPick: function () { return state.pick; },
            setPick: function (pick) { state.pick = pick && pick.id ? pick : null; state.mode = 'locker'; render(); },
            destroy: function () { root.innerHTML = ''; }
        };
    }

    window.LivrariaLockerPicker = { mount: mount, fold: fold };
}(window, document));
