/**
 * EduManage Pro — Real-Time Search Engine
 * assets/js/search.js
 *
 * Standalone AJAX search module.
 * Triggers on keypress with debounce — no page reload.
 * Automatically initialises for any element with [data-search].
 *
 * Usage in HTML:
 *   <input type="text"
 *          data-search
 *          data-search-url="/api/search.php?type=students"
 *          data-search-target="#studentsTbody"
 *          data-search-min="1"
 *          data-search-cols="10"
 *          placeholder="Search…">
 */

'use strict';

(function () {

    // ── Configuration ─────────────────────────────────────────
    const DEFAULTS = {
        debounce : 280,    // ms to wait after last keystroke
        minLength: 1,      // minimum chars before firing
        spinnerHtml: '<tr><td colspan="20" style="text-align:center;padding:24px;color:#64748B">🔍 Searching…</td></tr>',
        emptyHtml   : '<tr><td colspan="20" style="text-align:center;padding:32px;color:#94A3B8">No results found.</td></tr>',
        errorHtml   : '<tr><td colspan="20" style="text-align:center;padding:24px;color:#EF4444">⚠️ Search failed. Please try again.</td></tr>',
    };

    // Active XHR controllers keyed by input element
    const controllers = new WeakMap();

    // ── Init all search inputs ────────────────────────────────
    function initAll() {
        document.querySelectorAll('[data-search]').forEach(initInput);
    }

    function initInput(input) {
        if (input._searchInit) return;
        input._searchInit = true;

        let timer = null;
        const url      = input.dataset.searchUrl    || '/api/search.php';
        const target   = input.dataset.searchTarget || null;
        const minLen   = parseInt(input.dataset.searchMin  || DEFAULTS.minLength);
        const cols     = parseInt(input.dataset.searchCols || 10);
        const delay    = parseInt(input.dataset.searchDelay|| DEFAULTS.debounce);
        const container = target ? document.querySelector(target) : null;

        input.addEventListener('input', function () {
            clearTimeout(timer);
            const q = this.value.trim();

            // Cancel previous request
            if (controllers.has(input)) {
                controllers.get(input).abort();
            }

            if (q.length < minLen) {
                if (container) container.innerHTML = '';
                updateCount(input, null);
                return;
            }

            // Show spinner
            if (container) container.innerHTML = DEFAULTS.spinnerHtml.replace('20', cols);

            timer = setTimeout(() => doSearch(input, url, q, container, cols), delay);
        });

        // Clear button support
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                this.value = '';
                if (container) container.innerHTML = '';
                updateCount(input, null);
            }
        });
    }

    // ── Execute search ────────────────────────────────────────
    function doSearch(input, url, query, container, cols) {
        const controller = new AbortController();
        controllers.set(input, controller);

        const fullUrl = url + (url.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(query);

        fetch(fullUrl, {
            signal : controller.signal,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept'          : 'application/json',
            },
        })
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(data => {
            if (!container) return;

            if (data.html && data.html.trim()) {
                container.innerHTML = highlightTerms(data.html, query);
            } else {
                container.innerHTML = DEFAULTS.emptyHtml.replace('20', cols);
            }

            updateCount(input, data.count ?? null);
            animateRows(container);
        })
        .catch(err => {
            if (err.name === 'AbortError') return; // cancelled — ignore
            if (container) {
                container.innerHTML = DEFAULTS.errorHtml.replace('20', cols);
            }
            console.warn('[EduSearch] Error:', err);
        });
    }

    // ── Highlight search terms in returned HTML ───────────────
    function highlightTerms(html, query) {
        if (!query || query.length < 2) return html;
        // Only highlight in text nodes — don't break tag attributes
        const escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return html.replace(
            new RegExp(`(?<!<[^>]*)(${escaped})(?![^<]*>)`, 'gi'),
            '<mark style="background:#FEF08A;border-radius:3px;padding:0 2px">$1</mark>'
        );
    }

    // ── Animate newly inserted rows ───────────────────────────
    function animateRows(container) {
        if (!container) return;
        container.querySelectorAll('tr').forEach((tr, i) => {
            tr.style.opacity   = '0';
            tr.style.transform = 'translateY(4px)';
            tr.style.transition= 'opacity .15s ease, transform .15s ease';
            setTimeout(() => {
                tr.style.opacity   = '1';
                tr.style.transform = 'translateY(0)';
            }, i * 20);
        });
    }

    // ── Update result count display ───────────────────────────
    function updateCount(input, count) {
        const countEl = document.getElementById(input.dataset.searchCount || '');
        if (!countEl) return;
        if (count === null || count === undefined) {
            countEl.textContent = '';
        } else {
            countEl.textContent = count + ' result' + (count !== 1 ? 's' : '') + ' found';
        }
    }

    // ── Global helper: manually trigger search ────────────────
    window.EduSearch = {
        init     : initAll,
        initInput: initInput,
        search   : function (inputEl) {
            if (!inputEl) return;
            inputEl.dispatchEvent(new Event('input'));
        },
    };

    // ── Auto-init on DOMContentLoaded ────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    // ── Re-init after dynamic content (MutationObserver) ─────
    const observer = new MutationObserver(mutations => {
        mutations.forEach(m => {
            m.addedNodes.forEach(node => {
                if (node.nodeType !== 1) return;
                if (node.matches('[data-search]')) initInput(node);
                node.querySelectorAll('[data-search]').forEach(initInput);
            });
        });
    });
    observer.observe(document.body, { childList: true, subtree: true });

})();
