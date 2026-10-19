/* assets/js/green-ui.js
 * Hameedia green theme - custom dropdown (select) + calendar (input[type=date])
 */
(function () {
    'use strict';
    if (window.__greenUI) return;
    window.__greenUI = true;

    /* ---------- settings you may change ---------- */
    var WEEK_START = 0;               // 0 = Sunday, 1 = Monday
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var DAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

    /* ---------- CSS (theme: #217346 green) ---------- */
    var CSS = '' +
    '.gui-wrap{position:relative;display:inline-block;max-width:100%;vertical-align:middle}' +
    '.gui-native{position:absolute!important;left:0!important;bottom:0!important;width:1px!important;height:1px!important;opacity:0!important;pointer-events:none!important;margin:0!important;padding:0!important;border:0!important;min-height:0!important}' +
    '.gui-wrap .gui-trigger{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;min-width:120px;min-height:38px;margin:0;padding:0 12px;box-sizing:border-box;background:#f3faf4;border:1px solid #b8d9bf;border-radius:10px;color:#183b27;font-weight:500;line-height:1.2;text-align:left;cursor:pointer;transition:background .18s ease,border-color .18s ease,box-shadow .18s ease}' +
    '.gui-wrap .gui-trigger:hover{background:#e3f3e6;border-color:#82b990}' +
    '.gui-wrap .gui-trigger:focus-visible,.gui-wrap .gui-trigger.is-open{outline:none;background:#fff;border-color:#69a97a;box-shadow:0 0 0 3px rgba(33,115,70,.2)}' +
    '.gui-wrap .gui-trigger:disabled{opacity:.55;cursor:not-allowed}' +
    '.gui-trigger .gui-text{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
    '.gui-trigger .gui-text.is-placeholder{color:#7d9a86}' +
    '.gui-trigger svg{flex:none;width:16px;height:16px;stroke:#217346;fill:none;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round;transition:transform .18s ease}' +
    '.gui-trigger.is-open .gui-chev{transform:rotate(180deg)}' +

    '.gui-pop{position:fixed;z-index:2147483000;box-sizing:border-box;background:rgba(255,255,255,.97);-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px);border:1px solid #b8d9bf;border-radius:14px;color:#183b27;font-family:Inter,"Segoe UI",system-ui,sans-serif;font-size:14px;box-shadow:0 18px 40px rgba(26,92,58,.22),0 2px 8px rgba(26,92,58,.10);animation:guiIn .14s ease-out}' +
    '@keyframes guiIn{from{opacity:0;transform:translateY(-4px) scale(.98)}to{opacity:1;transform:none}}' +
    '.gui-pop *{box-sizing:border-box}' +

    /* dropdown list */
    '.gui-list{padding:6px;max-height:280px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:#a9d1b2 transparent}' +
    '.gui-list::-webkit-scrollbar{width:8px}.gui-list::-webkit-scrollbar-thumb{background:#a9d1b2;border-radius:8px}' +
    '.gui-opt{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 12px;border-radius:8px;font-weight:500;cursor:pointer;user-select:none}' +
    '.gui-opt:hover,.gui-opt.is-active{background:#e3f3e6;color:#123e25}' +
    '.gui-opt.is-sel{background:linear-gradient(135deg,#217346,#1a5c3a);color:#fff;font-weight:600}' +
    '.gui-opt.is-dis{opacity:.4;cursor:not-allowed}' +
    '.gui-opt svg{width:14px;height:14px;stroke:#fff;fill:none;stroke-width:2.6;stroke-linecap:round;stroke-linejoin:round;flex:none}' +
    '.gui-group{padding:8px 12px 4px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#6b7a8f}' +

    /* calendar */
    '.gui-cal{width:296px;padding:14px}' +
    '.gui-cal-head{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:10px}' +
    '.gui-pop button{font-family:inherit;margin:0;min-height:0;box-shadow:none;text-transform:none;letter-spacing:normal}' +
    '.gui-pop .gui-nav{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;padding:0;background:#e3f3e6;border:1px solid #b8d9bf;border-radius:9px;color:#183b27;cursor:pointer;transition:background .15s ease,border-color .15s ease}' +
    '.gui-pop .gui-nav:hover{background:#cfead4;border-color:#82b990}' +
    '.gui-pop .gui-nav svg{width:15px;height:15px;stroke:#217346;fill:none;stroke-width:2.6;stroke-linecap:round;stroke-linejoin:round}' +
    '.gui-pop .gui-title{flex:1;height:32px;padding:0 8px;background:transparent;border:0;border-radius:9px;color:#123e25;font-size:15px;font-weight:700;cursor:pointer}' +
    '.gui-pop .gui-title:hover{background:#e3f3e6}' +
    '.gui-dow,.gui-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}' +
    '.gui-dow span{padding:6px 0;text-align:center;font-size:11px;font-weight:700;letter-spacing:.04em;color:#6b7a8f;text-transform:uppercase}' +
    '.gui-pop .gui-day{height:36px;padding:0;background:transparent;border:1px solid transparent;border-radius:9px;color:#183b27;font-size:13px;font-weight:600;cursor:pointer;transition:background .12s ease,border-color .12s ease}' +
    '.gui-pop .gui-day:hover:not(:disabled){background:#e3f3e6;border-color:#b8d9bf}' +
    '.gui-pop .gui-day.is-out{color:#9db5a4;font-weight:500}' +
    '.gui-pop .gui-day.is-today{border-color:#217346;color:#217346}' +
    '.gui-pop .gui-day.is-sel,.gui-pop .gui-day.is-sel:hover{background:linear-gradient(135deg,#217346,#1a5c3a);border-color:#1a5c3a;color:#fff;box-shadow:0 4px 10px rgba(33,115,70,.3)}' +
    '.gui-pop .gui-day:disabled{opacity:.3;cursor:not-allowed}' +
    '.gui-big{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}' +
    '.gui-pop .gui-big .gui-day{height:42px}' +
    '.gui-foot{display:flex;justify-content:space-between;align-items:center;margin-top:12px;padding-top:10px;border-top:1px solid #dcedde}' +
    '.gui-pop .gui-link{padding:6px 12px;background:transparent;border:0;border-radius:8px;color:#217346;font-size:13px;font-weight:700;cursor:pointer}' +
    '.gui-pop .gui-link:hover:not(:disabled){background:#e3f3e6}' +
    '.gui-pop .gui-link:disabled{opacity:.35;cursor:not-allowed}' +
    '@media (max-width:420px){.gui-cal{width:calc(100vw - 24px)}}';

    var styleEl = document.createElement('style');
    styleEl.id = 'green-ui-css';
    styleEl.textContent = CSS;
    document.head.appendChild(styleEl);

    /* ---------- helpers ---------- */
    var ICON_CHEV = '<svg class="gui-chev" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>';
    var ICON_CAL = '<svg viewBox="0 0 24 24"><rect x="3" y="4.5" width="18" height="16.5" rx="3"/><path d="M8 2.5v4M16 2.5v4M3 10h18"/></svg>';
    var ICON_CHECK = '<svg viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>';
    var ICON_L = '<svg viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></svg>';
    var ICON_R = '<svg viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>';

    var selValue = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
    var inpValue = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function parse(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
        return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
    }
    function fmt(d) { return pad(d.getMonth() + 1) + '/' + pad(d.getDate()) + '/' + d.getFullYear(); } // mm/dd/yyyy
    function el(tag, cls, html) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (html != null) e.innerHTML = html;
        return e;
    }
    function fire(node) {
        node.dispatchEvent(new Event('input', { bubbles: true }));
        node.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /* wrap a native control and create the trigger button; keeps layout size of the original */
    function wrapControl(native, iconHtml) {
        var parent = native.parentNode;
        var w = native.offsetWidth, h = native.offsetHeight;
        var cs = getComputedStyle(native);
        var wrap = el('div', 'gui-wrap');
        if (w > 0) {
            var full = parent && parent.clientWidth && w >= parent.clientWidth - 2;
            wrap.style.width = full ? '100%' : w + 'px';
        }
        parent.insertBefore(wrap, native);
        wrap.appendChild(native);
        native.classList.add('gui-native');
        native.tabIndex = -1;

        var btn = el('button', 'gui-trigger', '<span class="gui-text"></span>' + iconHtml);
        btn.type = 'button';
        if (h > 0) btn.style.height = h + 'px';
        if (parseFloat(cs.fontSize)) btn.style.fontSize = cs.fontSize;
        if (cs.fontFamily) btn.style.fontFamily = cs.fontFamily;
        wrap.appendChild(btn);

        native.addEventListener('focus', function () { btn.focus(); });   // <label for="id"> click -> focus trigger
        return btn;
    }

    /* ---------- popup manager (only one open at a time) ---------- */
    var current = null;
    function closePop() {
        if (!current) return;
        var c = current; current = null;
        if (c.pop.parentNode) c.pop.parentNode.removeChild(c.pop);
        c.btn.classList.remove('is-open');
        document.removeEventListener('keydown', c.onKey, true);
        if (c.onClose) c.onClose();
    }
    function place(c) {
        var r = c.btn.getBoundingClientRect();
        var pw = c.pop.offsetWidth, ph = c.pop.offsetHeight;
        var left = Math.min(Math.max(8, r.left), Math.max(8, window.innerWidth - pw - 8));
        var top = r.bottom + 6;
        if (top + ph > window.innerHeight - 8 && r.top - ph - 6 > 8) top = r.top - ph - 6;
        c.pop.style.left = left + 'px';
        c.pop.style.top = top + 'px';
    }
    function openPop(btn, pop, onKey, onClose) {
        closePop();
        document.body.appendChild(pop);
        current = { btn: btn, pop: pop, onKey: onKey, onClose: onClose };
        btn.classList.add('is-open');
        place(current);
        document.addEventListener('keydown', onKey, true);
    }
    document.addEventListener('mousedown', function (e) {
        if (current && !current.pop.contains(e.target) && !current.btn.contains(e.target)) closePop();
    }, true);
    window.addEventListener('resize', function () { if (current) place(current); });
    window.addEventListener('scroll', function (e) {
        if (!current) return;
        if (current.pop.contains(e.target)) return;
        place(current);
    }, true);

    /* ---------- SELECT ---------- */
    function enhanceSelect(sel) {
        if (sel.__gui || sel.multiple || sel.size > 1 || sel.hasAttribute('data-native')) return;
        sel.__gui = true;
        var btn = wrapControl(sel, ICON_CHEV);
        var txt = btn.querySelector('.gui-text');

        function refresh() {
            var o = sel.options[sel.selectedIndex];
            txt.textContent = o ? o.text.trim() : '';
            btn.disabled = sel.disabled;
        }
        Object.defineProperty(sel, 'value', {
            configurable: true,
            get: function () { return selValue.get.call(this); },
            set: function (v) { selValue.set.call(this, v); refresh(); }
        });
        sel.addEventListener('change', refresh);
        if (sel.form) sel.form.addEventListener('reset', function () { setTimeout(refresh, 0); });
        new MutationObserver(refresh).observe(sel, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'selected'] });
        refresh();

        function open() {
            if (sel.disabled) return;
            if (current && current.btn === btn) { closePop(); return; }
            var pop = el('div', 'gui-pop gui-list');
            pop.setAttribute('role', 'listbox');
            pop.style.minWidth = btn.offsetWidth + 'px';
            var items = [];
            var active = sel.selectedIndex;

            function add(o, host) {
                var row = el('div', 'gui-opt', '<span></span>');
                row.firstChild.textContent = o.text.trim();
                if (o.index === sel.selectedIndex) { row.classList.add('is-sel'); row.insertAdjacentHTML('beforeend', ICON_CHECK); }
                if (o.disabled) row.classList.add('is-dis');
                row.setAttribute('role', 'option');
                row.addEventListener('mouseenter', function () { if (!o.disabled) setActive(o.index); });
                row.addEventListener('click', function () { if (!o.disabled) choose(o.index); });
                host.appendChild(row);
                items[o.index] = row;
            }
            Array.prototype.forEach.call(sel.children, function (ch) {
                if (ch.tagName === 'OPTGROUP') {
                    pop.appendChild(el('div', 'gui-group')).textContent = ch.label;
                    Array.prototype.forEach.call(ch.children, function (o) { add(o, pop); });
                } else if (ch.tagName === 'OPTION') {
                    add(ch, pop);
                }
            });

            function setActive(i) {
                if (items[active]) items[active].classList.remove('is-active');
                active = i;
                if (items[i]) { items[i].classList.add('is-active'); items[i].scrollIntoView({ block: 'nearest' }); }
            }
            function choose(i) {
                var changed = sel.selectedIndex !== i;
                sel.selectedIndex = i;
                closePop();
                refresh();
                if (changed) fire(sel);
                btn.focus();
            }
            function step(dir) {
                var i = active;
                do { i += dir; } while (i >= 0 && i < sel.options.length && sel.options[i].disabled);
                if (i >= 0 && i < sel.options.length) setActive(i);
            }
            var typed = '', typedAt = 0;
            function onKey(e) {
                if (e.key === 'ArrowDown') { e.preventDefault(); step(1); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); step(-1); }
                else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); e.stopPropagation(); choose(active); }
                else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closePop(); btn.focus(); }
                else if (e.key === 'Tab') { closePop(); }
                else if (e.key.length === 1) {                       // type-ahead
                    var now = Date.now();
                    typed = (now - typedAt > 700 ? '' : typed) + e.key.toLowerCase();
                    typedAt = now;
                    for (var i = 0; i < sel.options.length; i++) {
                        if (!sel.options[i].disabled && sel.options[i].text.trim().toLowerCase().indexOf(typed) === 0) { setActive(i); break; }
                    }
                }
            }
            openPop(btn, pop, onKey);
            if (items[active]) { items[active].classList.add('is-active'); items[active].scrollIntoView({ block: 'center' }); }
        }
        btn.addEventListener('click', open);
        btn.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); if (!current || current.btn !== btn) open(); }
        });
    }

    /* ---------- DATE ---------- */
    function enhanceDate(inp) {
        if (inp.__gui || inp.hasAttribute('data-native')) return;
        inp.__gui = true;
        var btn = wrapControl(inp, ICON_CAL);
        var txt = btn.querySelector('.gui-text');

        function refresh() {
            var d = parse(inpValue.get.call(inp));
            txt.textContent = d ? fmt(d) : 'mm/dd/yyyy';
            txt.classList.toggle('is-placeholder', !d);
            btn.disabled = inp.disabled;
        }
        Object.defineProperty(inp, 'value', {
            configurable: true,
            get: function () { return inpValue.get.call(this); },
            set: function (v) { inpValue.set.call(this, v); refresh(); }
        });
        inp.addEventListener('change', refresh);
        new MutationObserver(refresh).observe(inp, { attributes: true, attributeFilter: ['value', 'disabled', 'min', 'max'] });
        if (inp.form) inp.form.addEventListener('reset', function () { setTimeout(refresh, 0); });
        refresh();

        function open() {
            if (inp.disabled) return;
            if (current && current.btn === btn) { closePop(); return; }
            var min = parse(inp.min), max = parse(inp.max);
            var today = new Date(); today = new Date(today.getFullYear(), today.getMonth(), today.getDate());
            var selected = parse(inp.value);
            var base = selected || today;
            var view = { y: base.getFullYear(), m: base.getMonth(), mode: 'days' };
            var pop = el('div', 'gui-pop gui-cal');

            function inRange(d) { return !(min && d < min) && !(max && d > max); }

            function render() {
                var h = '<div class="gui-cal-head"><button type="button" class="gui-nav" data-act="prev" aria-label="Previous">' + ICON_L + '</button>';
                var title;
                if (view.mode === 'days') title = MONTHS[view.m] + ' ' + view.y;
                else if (view.mode === 'months') title = '' + view.y;
                else { var s = view.y - (view.y % 12); title = s + ' - ' + (s + 11); }
                h += '<button type="button" class="gui-title" data-act="mode">' + title + '</button>';
                h += '<button type="button" class="gui-nav" data-act="next" aria-label="Next">' + ICON_R + '</button></div>';

                if (view.mode === 'days') {
                    h += '<div class="gui-dow">';
                    for (var k = 0; k < 7; k++) h += '<span>' + DAYS[(k + WEEK_START) % 7] + '</span>';
                    h += '</div><div class="gui-grid">';
                    var first = new Date(view.y, view.m, 1);
                    var offset = (first.getDay() - WEEK_START + 7) % 7;
                    for (var i = 0; i < 42; i++) {
                        var d = new Date(view.y, view.m, 1 - offset + i);
                        var c = 'gui-day';
                        if (d.getMonth() !== view.m) c += ' is-out';
                        if (+d === +today) c += ' is-today';
                        if (selected && +d === +selected) c += ' is-sel';
                        h += '<button type="button" class="' + c + '" data-d="' + iso(d) + '"' + (inRange(d) ? '' : ' disabled') + '>' + d.getDate() + '</button>';
                    }
                    h += '</div>';
                } else if (view.mode === 'months') {
                    h += '<div class="gui-big">';
                    for (var mi = 0; mi < 12; mi++) {
                        var mc = 'gui-day';
                        if (selected && selected.getFullYear() === view.y && selected.getMonth() === mi) mc += ' is-sel';
                        else if (today.getFullYear() === view.y && today.getMonth() === mi) mc += ' is-today';
                        h += '<button type="button" class="' + mc + '" data-m="' + mi + '">' + MONTHS[mi].slice(0, 3) + '</button>';
                    }
                    h += '</div>';
                } else {
                    var start = view.y - (view.y % 12);
                    h += '<div class="gui-big">';
                    for (var yi = start; yi < start + 12; yi++) {
                        var yc = 'gui-day';
                        if (selected && selected.getFullYear() === yi) yc += ' is-sel';
                        else if (today.getFullYear() === yi) yc += ' is-today';
                        h += '<button type="button" class="' + yc + '" data-y="' + yi + '">' + yi + '</button>';
                    }
                    h += '</div>';
                }

                h += '<div class="gui-foot"><button type="button" class="gui-link" data-act="clear"' + (inp.required ? ' disabled' : '') + '>Clear</button>' +
                     '<button type="button" class="gui-link" data-act="today"' + (inRange(today) ? '' : ' disabled') + '>Today</button></div>';
                pop.innerHTML = h;
            }

            function setDate(d) {
                var changed = inp.value !== (d ? iso(d) : '');
                inp.value = d ? iso(d) : '';
                closePop();
                if (changed) fire(inp);
                btn.focus();
            }

            pop.addEventListener('click', function (e) {
                var t = e.target.closest('button');
                if (!t || t.disabled) return;
                if (t.hasAttribute('data-d')) return setDate(parse(t.getAttribute('data-d')));
                if (t.hasAttribute('data-m')) { view.m = +t.getAttribute('data-m'); view.mode = 'days'; return render(); }
                if (t.hasAttribute('data-y')) { view.y = +t.getAttribute('data-y'); view.mode = 'months'; return render(); }
                var act = t.getAttribute('data-act');
                if (act === 'today') return setDate(today);
                if (act === 'clear') return setDate(null);
                if (act === 'mode') { view.mode = view.mode === 'days' ? 'months' : (view.mode === 'months' ? 'years' : 'days'); return render(); }
                var dir = act === 'next' ? 1 : -1;
                if (view.mode === 'days') { view.m += dir; if (view.m < 0) { view.m = 11; view.y--; } if (view.m > 11) { view.m = 0; view.y++; } }
                else if (view.mode === 'months') view.y += dir;
                else view.y += dir * 12;
                render();
            });

            function onKey(e) {
                if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closePop(); btn.focus(); }
                else if (e.key === 'Tab') closePop();
            }
            render();
            openPop(btn, pop, onKey);
        }
        btn.addEventListener('click', open);
        btn.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); if (!current || current.btn !== btn) open(); }
        });
    }

    /* ---------- init ---------- */
    function enhanceAll(root) {
        Array.prototype.forEach.call((root || document).querySelectorAll('select'), enhanceSelect);
        Array.prototype.forEach.call((root || document).querySelectorAll('input[type="date"]'), enhanceDate);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { enhanceAll(); });
    else enhanceAll();
    window.GreenUI = { refresh: enhanceAll, close: closePop };   // call GreenUI.refresh() after adding selects dynamically
})();