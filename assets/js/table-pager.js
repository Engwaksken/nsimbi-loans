/*
 * table-pager.js — client-side pagination for every data table in the
 * admin, staff and client portals.
 *
 * Loaded once from each portal's footer. It pages any <table> with more
 * body rows than the smallest page size, and leaves alone:
 *   - tables marked data-no-paginate
 *   - DataTables tables (.data-table / .dataTable), which page themselves
 *   - tables whose card/section already renders server-side pagination
 *   - tables nested inside another table
 *
 * Rows hidden by a page's own search/filter (row.style.display = 'none' or
 * the hidden attribute) are left out of the count; the pager hides rows
 * with its own class, so the two never fight. Rows added later (AJAX) and
 * filter changes re-render the pager automatically. Printing shows all rows.
 */
(function () {
    'use strict';

    var PAGE_SIZES = [10, 25, 50, 100];
    var STORAGE_KEY = 'tablePager.pageSize';
    var HIDE = 'tp-hide';
    var SKIP_SELECTOR = '[data-no-paginate], .data-table, .dataTable';
    var SERVER_PAGER_SELECTOR = '.pagination, [class*="-pagination"], [class*="pagination-"], .dataTables_paginate';

    function readSize() {
        try {
            var v = parseInt(window.localStorage.getItem(STORAGE_KEY), 10);
            if (PAGE_SIZES.indexOf(v) !== -1 || v === 0) return v;
        } catch (e) { /* storage unavailable */ }
        return PAGE_SIZES[0];
    }

    function saveSize(v) {
        try { window.localStorage.setItem(STORAGE_KEY, String(v)); } catch (e) { /* ignore */ }
    }

    function injectStyles() {
        if (document.getElementById('tp-styles')) return;
        var css =
            '.' + HIDE + '{display:none !important}' +
            '.tp-bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem .75rem;' +
                'padding:.6rem .25rem;font-size:.85rem;color:inherit}' +
            '.tp-info{opacity:.75}' +
            '.tp-size{display:flex;align-items:center;gap:.4rem;opacity:.85}' +
            '.tp-size select{padding:.2rem .4rem;border:1px solid rgba(128,128,128,.35);border-radius:6px;' +
                'background:transparent;color:inherit;font-size:.85rem}' +
            '.tp-pages{display:flex;flex-wrap:wrap;gap:.25rem}' +
            '.tp-btn{min-width:2rem;height:2rem;padding:0 .55rem;border:1px solid rgba(128,128,128,.35);' +
                'border-radius:6px;background:transparent;color:inherit;font-size:.85rem;cursor:pointer;line-height:1}' +
            '.tp-btn:hover:not([disabled]){background:rgba(128,128,128,.12)}' +
            '.tp-btn[aria-current="page"]{background:#4f46e5;border-color:#4f46e5;color:#fff}' +
            '.tp-btn[disabled]{opacity:.4;cursor:default}' +
            '.tp-gap{align-self:center;padding:0 .2rem;opacity:.6}' +
            '@media (max-width:576px){.tp-bar{justify-content:center}.tp-info{width:100%;text-align:center}}' +
            '@media print{.' + HIDE + '{display:table-row !important}.tp-bar{display:none !important}}';
        var style = document.createElement('style');
        style.id = 'tp-styles';
        style.textContent = css;
        document.head.appendChild(style);
    }

    function bodyRows(table) {
        var rows = [];
        for (var b = 0; b < table.tBodies.length; b++) {
            var r = table.tBodies[b].rows;
            for (var i = 0; i < r.length; i++) rows.push(r[i]);
        }
        return rows;
    }

    function filteredOut(row) {
        return row.hidden || row.style.display === 'none';
    }

    function hasServerPager(table) {
        var node = table.parentElement;
        for (var depth = 0; node && node !== document.body && depth < 4; depth++, node = node.parentElement) {
            var found = node.querySelectorAll(SERVER_PAGER_SELECTOR);
            for (var i = 0; i < found.length; i++) {
                if (!found[i].closest('.tp-bar')) return true;
            }
        }
        return false;
    }

    // Label/value detail tables (each row starts with a <th>) are not lists.
    function isKeyValueTable(table) {
        var rows = bodyRows(table);
        if (!rows.length) return false;
        for (var i = 0; i < rows.length; i++) {
            var first = rows[i].cells[0];
            if (!first || first.tagName !== 'TH') return false;
        }
        return true;
    }

    function eligible(table) {
        if (table.__tablePager) return false;
        if (table.matches(SKIP_SELECTOR)) return false;
        if (table.closest('.dataTables_wrapper')) return false;
        if (table.parentElement && table.parentElement.closest('table')) return false;
        if (!table.tBodies.length) return false;
        if (isKeyValueTable(table)) return false;
        return !hasServerPager(table);
    }

    function Pager(table) {
        this.table = table;
        this.size = readSize();
        this.page = 1;
        this.lastCount = -1;
        this.scheduled = false;
        this.bar = document.createElement('div');
        this.bar.className = 'tp-bar';
        this.bar.setAttribute('role', 'navigation');
        this.bar.setAttribute('aria-label', 'Table pagination');

        var anchor = table.closest('.table-responsive, .table-wrap, .table-wrapper, .table-container') || table;
        anchor.parentNode.insertBefore(this.bar, anchor.nextSibling);

        var self = this;
        this.bar.addEventListener('click', function (e) {
            var btn = e.target.closest('.tp-btn');
            if (!btn || btn.disabled) return;
            self.page = parseInt(btn.getAttribute('data-page'), 10);
            self.render();
        });
        this.bar.addEventListener('change', function (e) {
            if (!e.target.matches('.tp-size select')) return;
            self.size = parseInt(e.target.value, 10);
            saveSize(self.size);
            self.page = 1;
            self.render();
        });

        // Re-render when rows are added/removed or a page filter shows/hides rows.
        // The pager itself only toggles a class, so it never triggers itself.
        this.observer = new MutationObserver(function () { self.schedule(); });
        this.observer.observe(table, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['style', 'hidden']
        });

        table.__tablePager = this;
        this.render();
    }

    Pager.prototype.schedule = function () {
        if (this.scheduled) return;
        this.scheduled = true;
        var self = this;
        window.requestAnimationFrame(function () {
            self.scheduled = false;
            self.render();
        });
    };

    Pager.prototype.render = function () {
        var rows = bodyRows(this.table);
        var visible = [];
        for (var i = 0; i < rows.length; i++) {
            if (filteredOut(rows[i])) {
                rows[i].classList.remove(HIDE);
            } else {
                visible.push(rows[i]);
            }
        }

        var total = visible.length;
        if (total !== this.lastCount) {
            if (this.lastCount !== -1) this.page = 1; // filter or data changed
            this.lastCount = total;
        }

        // Nothing to page: show everything and hide the bar.
        if (total <= PAGE_SIZES[0]) {
            for (var a = 0; a < visible.length; a++) visible[a].classList.remove(HIDE);
            this.bar.hidden = true;
            return;
        }

        var size = this.size === 0 ? total : this.size;
        var pages = Math.max(1, Math.ceil(total / size));
        this.page = Math.min(Math.max(1, this.page), pages);
        var start = (this.page - 1) * size;
        var end = Math.min(start + size, total);

        for (var j = 0; j < visible.length; j++) {
            visible[j].classList.toggle(HIDE, j < start || j >= end);
        }

        this.bar.hidden = false;
        this.bar.innerHTML =
            '<div class="tp-info">Showing ' + (start + 1) + '–' + end + ' of ' + total + '</div>' +
            '<label class="tp-size">Rows <select aria-label="Rows per page">' + this.sizeOptions() + '</select></label>' +
            '<div class="tp-pages">' + this.pageButtons(pages) + '</div>';
    };

    Pager.prototype.sizeOptions = function () {
        var html = '';
        for (var i = 0; i < PAGE_SIZES.length; i++) {
            html += '<option value="' + PAGE_SIZES[i] + '"' + (this.size === PAGE_SIZES[i] ? ' selected' : '') + '>' +
                PAGE_SIZES[i] + '</option>';
        }
        return html + '<option value="0"' + (this.size === 0 ? ' selected' : '') + '>All</option>';
    };

    Pager.prototype.pageButtons = function (pages) {
        var cur = this.page;
        function btn(p, label, opts) {
            opts = opts || {};
            return '<button type="button" class="tp-btn" data-page="' + p + '"' +
                (opts.disabled ? ' disabled' : '') +
                (opts.current ? ' aria-current="page"' : '') +
                (opts.aria ? ' aria-label="' + opts.aria + '"' : '') + '>' + label + '</button>';
        }

        var html = btn(cur - 1, '&lsaquo;', { disabled: cur === 1, aria: 'Previous page' });

        // 1 … cur-1 cur cur+1 … last
        var list = [1];
        for (var p = cur - 1; p <= cur + 1; p++) {
            if (p > 1 && p < pages) list.push(p);
        }
        if (pages > 1) list.push(pages);

        var prev = 0;
        for (var i = 0; i < list.length; i++) {
            if (list[i] - prev > 1) html += '<span class="tp-gap">…</span>';
            html += btn(list[i], String(list[i]), { current: list[i] === cur });
            prev = list[i];
        }

        return html + btn(cur + 1, '&rsaquo;', { disabled: cur === pages, aria: 'Next page' });
    };

    function scan(root) {
        var tables = (root || document).querySelectorAll('table');
        for (var i = 0; i < tables.length; i++) {
            if (eligible(tables[i])) new Pager(tables[i]);
        }
    }

    function init() {
        injectStyles();
        scan(document);

        // Tables injected later (AJAX-loaded fragments, modals).
        var pending = false;
        new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var added = mutations[i].addedNodes;
                for (var j = 0; j < added.length; j++) {
                    var n = added[j];
                    if (n.nodeType === 1 && (n.tagName === 'TABLE' || n.querySelector('table'))) {
                        if (!pending) {
                            pending = true;
                            window.requestAnimationFrame(function () { pending = false; scan(document); });
                        }
                        return;
                    }
                }
            }
        }).observe(document.body, { childList: true, subtree: true });
    }

    window.TablePager = { refresh: function () { scan(document); } };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
