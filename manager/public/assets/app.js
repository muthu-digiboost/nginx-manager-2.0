/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : app.js
 *  Date    : 26/11/2026
 *  Author  : Semansys Development Team
 *
 *  Copyright (c) 2026
 *  Tel: +91 97863 37914
 *  Email: dev.digiboost.muthu@gmail.com
 *  All rights reserved, see the LICENSE file for more information.
 *
 ******************************************************************************/

/**
 * File: manager/public/assets/app.js
 * Purpose: Small progressive enhancements - every page also works without JavaScript.
 * Features:
 *   - Tabs          [data-tabs] container, .tab[data-tab="x"] buttons, .tab-panel[data-panel="x"];
 *                   the active tab is kept in the URL hash so reloads stay on it
 *   - Lists         [data-list][data-page-size] = client-side pagination of [data-filter-text] items;
 *                   input[data-list-search="#a, #b"] searches those lists (pager resets to page 1)
 *   - Confirm       form[data-confirm="text"] asks inline (no browser dialog) before submitting;
 *                   form[data-confirm-dialog="Title"] (+ data-confirm-text / -button) opens a
 *                   confirmation box first (deletes)
 *   - Toggle rows   .toggle-row with a checkbox gets an "on" class for styling
 * Used by: views/layout.php (loaded on every page); page-specific scripts live inline.
 */
(function () {
    'use strict';

    // -- Tabs -------------------------------------------------------------------
    document.querySelectorAll('[data-tabs]').forEach(function (box) {
        var tabs = box.querySelectorAll('.tab');
        var panels = box.querySelectorAll('.tab-panel');
        function show(name) {
            tabs.forEach(function (t) { t.classList.toggle('active', t.dataset.tab === name); });
            panels.forEach(function (p) { p.classList.toggle('active', p.dataset.panel === name); });
        }
        tabs.forEach(function (t) {
            t.addEventListener('click', function () {
                show(t.dataset.tab);
                history.replaceState(null, '', '#' + t.dataset.tab);
            });
        });
        var initial = location.hash.slice(1);
        var exists = Array.prototype.some.call(tabs, function (t) { return t.dataset.tab === initial; });
        show(exists ? initial : (tabs[0] && tabs[0].dataset.tab));
    });

    // -- Lists: search + pagination ------------------------------------------------
    // <div data-list data-page-size="8"> ... items with [data-filter-text] ... </div>
    // <input data-list-search="#listA, #listB">  searches one or more lists.
    // A list may sit inside a [data-filter-group] (e.g. a site group card) that is hidden
    // when none of its items match. The pager is created right after each list.
    var PAGE_SIZES = [5, 10, 25, 50];
    function setupList(list) {
        // Per-page choice is remembered per list (by id) in localStorage.
        var key = list.id ? 'nm2.pageSize.' + list.id : null;
        var saved = key ? parseInt(localStorage.getItem(key) || '', 10) : NaN;
        var size = PAGE_SIZES.indexOf(saved) !== -1 ? saved : parseInt(list.dataset.pageSize || '10', 10);
        var items = Array.prototype.slice.call(list.querySelectorAll('[data-filter-text]'));
        var pager = document.createElement('div');
        pager.className = 'pager';
        list.insertAdjacentElement('afterend', pager);
        var state = { q: '', page: 1 };

        function render() {
            var matches = items.filter(function (el) {
                var text = state.q === '' || el.dataset.filterText.toLowerCase().indexOf(state.q) !== -1;
                return text && (!list._predicate || list._predicate(el));   // optional extra filter set by a page
            });
            var pages = Math.max(1, Math.ceil(matches.length / size));
            state.page = Math.min(state.page, pages);
            var from = (state.page - 1) * size;
            items.forEach(function (el) { el.classList.add('hidden'); });
            matches.slice(from, from + size).forEach(function (el) { el.classList.remove('hidden'); });

            var group = list.closest('[data-filter-group]');
            if (group) {
                group.classList.toggle('hidden', state.q !== '' && matches.length === 0);
                if (state.q !== '' && matches.length) { group.open = true; }
            }
            var empty = list.parentNode.querySelector('.list-empty');
            if (empty) { empty.classList.toggle('hidden', matches.length !== 0); }

            // The bar (count, per-page choice, page buttons) is shown for lists longer than
            // the smallest page size; a list of 1-5 items does not need it.
            pager.textContent = '';
            pager.classList.toggle('hidden', items.length <= PAGE_SIZES[0]);
            var info = document.createElement('span');
            info.className = 'pager-info';
            info.textContent = matches.length === 0
                ? 'No matches'
                : 'Showing ' + (from + 1) + '–' + Math.min(from + size, matches.length) + ' of ' + matches.length;
            pager.appendChild(info);

            var per = document.createElement('label');
            per.className = 'pager-size';
            per.appendChild(document.createTextNode('Per page '));
            var sel = document.createElement('select');
            // The list's own default size is always offered, even if it is not one of PAGE_SIZES.
            var options = PAGE_SIZES.indexOf(size) === -1 ? PAGE_SIZES.concat([size]).sort(function (a, b) { return a - b; }) : PAGE_SIZES;
            options.forEach(function (n) {
                var o = document.createElement('option');
                o.value = String(n); o.textContent = String(n); o.selected = n === size;
                sel.appendChild(o);
            });
            sel.addEventListener('change', function () {
                size = parseInt(sel.value, 10);
                if (key) { localStorage.setItem(key, String(size)); }
                state.page = 1;
                render();
            });
            per.appendChild(sel);
            pager.appendChild(per);
            function button(label, page, disabled, current) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'pager-btn' + (current ? ' current' : '');
                b.textContent = label;
                b.disabled = disabled;
                b.addEventListener('click', function () { state.page = page; render(); });
                pager.appendChild(b);
            }
            button('‹', state.page - 1, state.page === 1, false);
            for (var p = 1; p <= pages; p++) { button(String(p), p, false, p === state.page); }
            button('›', state.page + 1, state.page === pages, false);
        }
        list._search = function (q) { state.q = q; state.page = 1; render(); };
        list._refresh = function () { state.page = 1; render(); };
        render();
    }
    document.querySelectorAll('[data-list]').forEach(setupList);
    document.querySelectorAll('input[data-list-search]').forEach(function (input) {
        var lists = document.querySelectorAll(input.dataset.listSearch);
        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase();
            lists.forEach(function (l) { if (l._search) { l._search(q); } });
        });
    });

    // -- Confirmation box for deletes: form[data-confirm-dialog="Title"] -------------------
    //    data-confirm-text = the explanation, data-confirm-button = the red button's label.
    //    An in-page box (no browser confirm() dialog); Cancel, Esc and a click outside close it.
    function confirmBox(form) {
        var backdrop = document.createElement('div');
        backdrop.className = 'confirm-backdrop';
        var box = document.createElement('div');
        box.className = 'confirm-box';
        box.setAttribute('role', 'alertdialog');
        box.setAttribute('aria-modal', 'true');
        var title = document.createElement('h3');
        title.id = 'confirm-title';
        title.textContent = form.dataset.confirmDialog;
        box.setAttribute('aria-labelledby', 'confirm-title');
        var text = document.createElement('p');
        text.textContent = form.dataset.confirmText || 'This cannot be undone.';
        var actions = document.createElement('div');
        actions.className = 'confirm-actions';
        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn btn-ghost';
        cancel.textContent = 'Cancel';
        var ok = document.createElement('button');
        ok.type = 'button';
        ok.className = 'btn btn-danger';
        ok.textContent = form.dataset.confirmButton || 'Delete';
        actions.appendChild(cancel);
        actions.appendChild(ok);
        box.appendChild(title);
        box.appendChild(text);
        box.appendChild(actions);
        backdrop.appendChild(box);
        document.body.appendChild(backdrop);
        var opener = document.activeElement;
        function close() {
            document.removeEventListener('keydown', onKey);
            backdrop.remove();
            if (opener && opener.focus) { opener.focus(); }
        }
        function onKey(ev) {
            if (ev.key === 'Escape') { close(); }
            if (ev.key === 'Tab') {   // keep the focus inside the box
                ev.preventDefault();
                (document.activeElement === cancel ? ok : cancel).focus();
            }
        }
        cancel.addEventListener('click', close);
        backdrop.addEventListener('click', function (ev) { if (ev.target === backdrop) { close(); } });
        document.addEventListener('keydown', onKey);
        ok.addEventListener('click', function () {
            ok.disabled = true;
            cancel.disabled = true;
            form.dataset.confirmed = '1';
            HTMLFormElement.prototype.submit.call(form);
        });
        cancel.focus();   // the safe choice has the focus
    }
    document.querySelectorAll('form[data-confirm-dialog]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (form.dataset.confirmed === '1') { return; }
            ev.preventDefault();
            confirmBox(form);
        });
    });

    // -- Inline confirm (browser confirm() dialogs are avoided on purpose) ----------
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (form.dataset.confirmed === '1') { return; }
            ev.preventDefault();
            var btn = form.querySelector('[type=submit]');
            if (!btn) { return; }
            var original = btn.innerHTML;
            btn.textContent = form.dataset.confirm || 'Click again to confirm';
            btn.classList.add('btn-danger');
            form.dataset.confirmed = '1';
            setTimeout(function () {
                form.dataset.confirmed = '';
                btn.innerHTML = original;
            }, 4000);
        });
    });

    // -- "Select all" checkbox: input[data-check-all="#form"] toggles name="ids[]" boxes --
    document.querySelectorAll('input[data-check-all]').forEach(function (all) {
        var scope = document.querySelector(all.dataset.checkAll);
        if (!scope) { return; }
        all.addEventListener('change', function () {
            scope.querySelectorAll('input[type=checkbox][name="ids[]"]').forEach(function (cb) { cb.checked = all.checked; });
        });
    });

    // -- Show / hide panels: <button data-toggle="#panel"> toggles .hidden on #panel ------
    document.addEventListener('click', function (ev) {
        // <button data-focus="#field"> puts the cursor in a field that is already on screen.
        var focus = ev.target.closest('[data-focus]');
        if (focus) {
            var field = document.querySelector(focus.dataset.focus);
            if (field) { field.scrollIntoView({ block: 'center', behavior: 'smooth' }); field.focus(); }
            return;
        }
        var btn = ev.target.closest('[data-toggle]');
        if (!btn) { return; }
        var panel = document.querySelector(btn.dataset.toggle);
        if (!panel) { return; }
        panel.classList.toggle('hidden');
        if (!panel.classList.contains('hidden')) {
            var first = panel.querySelector('input:not([type=hidden]), select, textarea');
            if (first) { first.focus(); }
        }
    });

    // -- Button icons --------------------------------------------------------------------
    // Every .btn / .top-btn gets a light line icon chosen from its label, so all pages (and
    // future buttons) look consistent without editing each view. Buttons that already
    // contain an <svg> are left alone. A leading "+ " in the label is replaced by the icon.
    var ICONS = {
        plus:     '<path d="M12 5v14M5 12h14"/>',
        save:     '<path d="M5 3h11l3 3v15H5z"/><path d="M8 3v6h8V3M8 21v-7h8v7"/>',
        check:    '<path d="M5 12l5 5 9-10"/>',
        trash:    '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        play:     '<path d="M8 5l11 7-11 7z"/>',
        stop:     '<rect x="6" y="6" width="12" height="12" rx="1.5"/>',
        rotate:   '<path d="M21 12a9 9 0 1 1-3-6.7L21 8M21 3v5h-5"/>',
        edit:     '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M14 6l4 4"/>',
        external: '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        shield:   '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        unshield: '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9.5 9.5l5 5M14.5 9.5l-5 5"/>',
        download: '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
        up:       '<circle cx="12" cy="12" r="9"/><path d="M12 16V8M8.5 11.5L12 8l3.5 3.5"/>',
        close:    '<path d="M6 6l12 12M18 6L6 18"/>',
        back:     '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        search:   '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>',
        sliders:  '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
        history:  '<path d="M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5"/><path d="M12 8v4l3 2"/>',
        power:    '<path d="M12 3v8M6.3 7.3a8 8 0 1 0 11.4 0"/>',
        list:     '<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>',
        globe:    '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>'
    };
    var RULES = [
        [/^(\+|add|new|create)/, 'plus'], [/^(save|test & save)/, 'save'], [/^(apply|done)/, 'check'],
        [/^(delete|remove)/, 'trash'], [/^start/, 'play'], [/^stop/, 'stop'],
        [/^(restart|reload|renew|check for updates|refresh)/, 'rotate'], [/^edit/, 'edit'],
        [/^(visit|open)/, 'external'], [/^untrust/, 'unshield'], [/^trust/, 'shield'],
        [/^install/, 'download'], [/^update/, 'up'], [/^(cancel|close|discard)/, 'close'],
        [/^back/, 'back'], [/^find/, 'search'], [/^manage/, 'sliders'], [/^restore/, 'history'],
        [/^(enable|disable)/, 'power'], [/^(all php|view all)/, 'list'], [/^(show)/, 'search']
    ];
    function decorate(btn) {
        if (btn.querySelector('svg') || btn.dataset.noIcon !== undefined) { return; }
        var label = btn.textContent.trim().toLowerCase();
        for (var i = 0; i < RULES.length; i++) {
            if (RULES[i][0].test(label)) {
                if (btn.firstChild && btn.firstChild.nodeType === 3) {
                    btn.firstChild.nodeValue = btn.firstChild.nodeValue.replace(/^\s*\+\s*/, '');
                }
                var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                svg.setAttribute('viewBox', '0 0 24 24');
                svg.setAttribute('aria-hidden', 'true');
                svg.setAttribute('class', 'btn-ic');
                svg.innerHTML = ICONS[RULES[i][1]];   // static, trusted markup from ICONS above
                btn.insertBefore(svg, btn.firstChild);
                return;
            }
        }
    }
    document.querySelectorAll('.btn, .top-btn').forEach(decorate);

    // -- Toggle rows -------------------------------------------------------------
    document.querySelectorAll('.toggle-row input[type=checkbox]').forEach(function (cb) {
        var row = cb.closest('.toggle-row');
        var sync = function () { row.classList.toggle('on', cb.checked); };
        cb.addEventListener('change', sync);
        sync();
    });
})();
