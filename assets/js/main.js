/**
 * EduManage Pro — Main JavaScript
 * assets/js/main.js
 * Vanilla JS only. No frameworks.
 */

'use strict';

// ── DOM ready ────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    initSidebar();
    initDropdowns();
    initModals();
    initAlertClose();
    initRealTimeSearch();
    initConfirmDeletes();
    initFormValidation();
    initTableSort();
});

// ============================================================
// SIDEBAR
// ============================================================
function initSidebar() {
    const sidebar  = document.getElementById('sidebar');
    const toggle   = document.getElementById('sidebarToggle');
    const mainArea = document.querySelector('.main-area');
    const overlay  = document.getElementById('sidebarOverlay');

    if (!sidebar || !toggle) return;

    // Restore collapsed state
    const collapsed = localStorage.getItem('sidebar_collapsed') === '1';
    if (collapsed && window.innerWidth > 900) {
        setSidebarCollapsed(true, sidebar, mainArea);
    }

    toggle.addEventListener('click', function () {
        if (window.innerWidth <= 900) {
            // Mobile: slide in/out
            const isOpen = sidebar.classList.contains('mobile-open');
            sidebar.classList.toggle('mobile-open', !isOpen);
            if (overlay) overlay.classList.toggle('active', !isOpen);
        } else {
            // Desktop: collapse/expand
            const isCollapsed = sidebar.classList.contains('collapsed');
            setSidebarCollapsed(!isCollapsed, sidebar, mainArea);
            localStorage.setItem('sidebar_collapsed', !isCollapsed ? '1' : '0');
        }
    });

    if (overlay) {
        overlay.addEventListener('click', function () {
            sidebar.classList.remove('mobile-open');
            overlay.classList.remove('active');
        });
    }
}

function setSidebarCollapsed(collapsed, sidebar, mainArea) {
    sidebar.classList.toggle('collapsed', collapsed);
    if (mainArea) mainArea.classList.toggle('sidebar-collapsed', collapsed);
}

// ============================================================
// DROPDOWNS (Notifications + User menu)
// ============================================================
function initDropdowns() {
    setupDropdown('notifBtn',  'notifDropdown');
    setupDropdown('userBtn',   'userDropdown');
}

function setupDropdown(btnId, dropId) {
    const btn  = document.getElementById(btnId);
    const drop = document.getElementById(dropId);
    if (!btn || !drop) return;

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        const isOpen = drop.style.display === 'block';
        closeAllDropdowns();
        drop.style.display = isOpen ? 'none' : 'block';
    });

    document.addEventListener('click', function () {
        drop.style.display = 'none';
    });

    drop.addEventListener('click', function (e) { e.stopPropagation(); });
}

function closeAllDropdowns() {
    ['notifDropdown', 'userDropdown'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });
}

// ============================================================
// MODALS
// ============================================================
function initModals() {
    // Open via data-modal-target
    document.querySelectorAll('[data-modal-target]').forEach(btn => {
        btn.addEventListener('click', function () {
            openModal(this.dataset.modalTarget);
        });
    });

    // Close via data-modal-close
    document.querySelectorAll('[data-modal-close]').forEach(btn => {
        btn.addEventListener('click', function () {
            closeModal(this.closest('.modal-backdrop'));
        });
    });

    // Close on backdrop click
    document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
        backdrop.addEventListener('click', function (e) {
            if (e.target === this) closeModal(this);
        });
    });

    // ESC key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-backdrop').forEach(closeModal);
        }
    });
}

function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        // Focus first input
        setTimeout(() => {
            const input = modal.querySelector('input, select, textarea');
            if (input) input.focus();
        }, 100);
    }
}

function closeModal(el) {
    if (!el) return;
    const backdrop = el.classList.contains('modal-backdrop') ? el : el.closest('.modal-backdrop');
    if (backdrop) {
        backdrop.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// Global helpers
window.openModal  = openModal;
window.closeModal = closeModal;

// ============================================================
// ALERT AUTO-CLOSE
// ============================================================
function initAlertClose() {
    document.querySelectorAll('.alert').forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity .4s';
            setTimeout(() => alert.remove(), 400);
        }, 5000);
    });
}

// ============================================================
// REAL-TIME SEARCH (AJAX)
// ============================================================
function initRealTimeSearch() {
    const searchInput = document.querySelector('[data-ajax-search]');
    if (!searchInput) return;

    let timer;
    searchInput.addEventListener('input', function () {
        clearTimeout(timer);
        const q       = this.value.trim();
        const target  = this.dataset.ajaxSearch;     // selector for results container
        const url     = this.dataset.searchUrl;      // API endpoint
        const minLen  = parseInt(this.dataset.minLen || '1');

        if (q.length < minLen) {
            const container = document.querySelector(target);
            if (container) container.innerHTML = '';
            return;
        }

        timer = setTimeout(() => {
            ajaxSearch(url, q, target);
        }, 280);
    });
}

function ajaxSearch(url, query, targetSelector) {
    const container = document.querySelector(targetSelector);
    if (!container) return;

    container.innerHTML = '<tr><td colspan="20" class="table-empty">🔍 Searching...</td></tr>';

    fetch(url + '?q=' + encodeURIComponent(query), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.html) {
            container.innerHTML = data.html;
        } else {
            container.innerHTML = '<tr><td colspan="20" class="table-empty">No results found.</td></tr>';
        }
    })
    .catch(() => {
        container.innerHTML = '<tr><td colspan="20" class="table-empty text-danger">Search failed. Please try again.</td></tr>';
    });
}

// ============================================================
// CONFIRM DELETES
// ============================================================
function initConfirmDeletes() {
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', function (e) {
            const msg = this.dataset.confirm || 'Are you sure you want to delete this record? This cannot be undone.';
            if (!confirm(msg)) e.preventDefault();
        });
    });
}

// ============================================================
// CLIENT-SIDE FORM VALIDATION
// ============================================================
function initFormValidation() {
    document.querySelectorAll('form[data-validate]').forEach(form => {
        form.addEventListener('submit', function (e) {
            let valid = true;
            this.querySelectorAll('[required]').forEach(field => {
                const err = field.parentElement.querySelector('.form-error');
                if (!field.value.trim()) {
                    field.classList.add('is-invalid');
                    if (err) err.textContent = 'This field is required.';
                    valid = false;
                } else {
                    field.classList.remove('is-invalid');
                    if (err) err.textContent = '';
                }
            });
            if (!valid) e.preventDefault();
        });
    });
}

// ============================================================
// TABLE SORT
// ============================================================
function initTableSort() {
    document.querySelectorAll('th[data-sort]').forEach(th => {
        th.style.cursor = 'pointer';
        th.addEventListener('click', function () {
            const table = this.closest('table');
            const tbody = table.querySelector('tbody');
            const idx   = Array.from(this.parentElement.children).indexOf(this);
            const asc   = this.dataset.sortDir !== 'asc';
            this.dataset.sortDir = asc ? 'asc' : 'desc';

            Array.from(tbody.querySelectorAll('tr'))
                .sort((a, b) => {
                    const va = a.cells[idx]?.textContent.trim() || '';
                    const vb = b.cells[idx]?.textContent.trim() || '';
                    return asc
                        ? va.localeCompare(vb, undefined, { numeric: true })
                        : vb.localeCompare(va, undefined, { numeric: true });
                })
                .forEach(tr => tbody.appendChild(tr));

            // Update sort indicators
            table.querySelectorAll('th[data-sort]').forEach(t => t.textContent = t.textContent.replace(/ [▲▼]$/, ''));
            this.textContent += asc ? ' ▲' : ' ▼';
        });
    });
}

// ============================================================
// TABS
// ============================================================
function switchTab(tabId, groupId) {
    const group = document.getElementById(groupId || 'tabs');
    if (!group) return;

    group.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tabId);
    });
    group.querySelectorAll('.tab-content').forEach(pane => {
        pane.classList.toggle('active', pane.id === tabId);
    });
}
window.switchTab = switchTab;

// ============================================================
// FETCH HELPERS
// ============================================================
function postJson(url, data) {
    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify(data),
    }).then(r => r.json());
}

function postForm(url, formData) {
    return fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData,
    }).then(r => r.json());
}

window.postJson = postJson;
window.postForm = postForm;

// ============================================================
// UTILITY FUNCTIONS
// ============================================================

/** Show a toast message */
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = 'alert alert-' + type;
    toast.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999;min-width:280px;box-shadow:0 4px 20px rgba(0,0,0,.15)';
    toast.innerHTML = message + '<button class="alert-close" onclick="this.parentElement.remove()">×</button>';
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity .4s';
        setTimeout(() => toast.remove(), 400);
    }, 4000);
}
window.showToast = showToast;

/** Format numbers as currency */
function formatMoney(amount, symbol = 'D') {
    return symbol + ' ' + parseFloat(amount).toLocaleString('en-GB', { minimumFractionDigits: 2 });
}
window.formatMoney = formatMoney;

/** Get URL param */
function urlParam(name) {
    return new URLSearchParams(window.location.search).get(name);
}
window.urlParam = urlParam;

/** Simple client-side CSV preview */
function previewCSV(input, tableId) {
    const file = input.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function (e) {
        const lines = e.target.result.split('\n').filter(l => l.trim());
        if (!lines.length) return;

        const headers = lines[0].split(',').map(h => h.trim());
        const table   = document.getElementById(tableId);
        if (!table) return;

        let html = '<thead><tr>' + headers.map(h => `<th>${h}</th>`).join('') + '<th>Status</th></tr></thead><tbody>';
        lines.slice(1).forEach(line => {
            const cols = line.split(',').map(c => c.trim());
            if (!cols.some(c => c)) return; // skip empty rows
            html += '<tr>' + cols.map(c => `<td>${c}</td>`).join('') + '<td><span class="badge badge-success">Ready</span></td></tr>';
        });
        html += '</tbody>';
        table.innerHTML = html;

        const info = document.getElementById('csvRowCount');
        if (info) info.textContent = (lines.length - 1) + ' records found';
    };
    reader.readAsText(file);
}
window.previewCSV = previewCSV;

/** Auto-calculate result total */
function calcResultTotal() {
    const test  = parseFloat(document.getElementById('test_score')?.value  || 0);
    const asn   = parseFloat(document.getElementById('asn_score')?.value   || 0);
    const exam  = parseFloat(document.getElementById('exam_score')?.value  || 0);
    const total = test + asn + exam;

    const totalEl = document.getElementById('calc_total');
    const gradeEl = document.getElementById('calc_grade');

    if (totalEl) totalEl.textContent = total.toFixed(1);
    if (gradeEl) {
        const grades = [
            { min: 80, grade: 'A', color: 'badge-success' },
            { min: 70, grade: 'B', color: 'badge-primary' },
            { min: 60, grade: 'C', color: 'badge-warning' },
            { min: 50, grade: 'D', color: 'badge-purple' },
            { min: 0,  grade: 'F', color: 'badge-danger'  },
        ];
        const g = grades.find(g => total >= g.min) || grades[grades.length - 1];
        gradeEl.innerHTML = `<span class="badge ${g.color}">${g.grade}</span>`;
    }
}
window.calcResultTotal = calcResultTotal;

/** Payment balance auto-calc */
function calcBalance() {
    const due    = parseFloat(document.getElementById('amount_due')?.value  || 0);
    const paid   = parseFloat(document.getElementById('amount_paid')?.value || 0);
    const balance = due - paid;
    const el = document.getElementById('calc_balance');
    if (el) {
        el.textContent = 'D ' + balance.toFixed(2);
        el.style.color = balance <= 0 ? '#10B981' : '#EF4444';
    }
}
window.calcBalance = calcBalance;

/** WhatsApp link builder */
function openWhatsApp(phone, message) {
    const clean = phone.replace(/[^0-9]/g, '');
    const url   = 'https://wa.me/' + clean + '?text=' + encodeURIComponent(message);
    window.open(url, '_blank');
}
window.openWhatsApp = openWhatsApp;

/** Print section */
function printSection(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const w = window.open('', '_blank');
    w.document.write('<html><head><title>Print</title>');
    w.document.write('<link rel="stylesheet" href="' + window.location.origin + '/edumanage/assets/css/style.css">');
    w.document.write('</head><body style="padding:20px">');
    w.document.write(el.innerHTML);
    w.document.write('</body></html>');
    w.document.close();
    w.print();
}
window.printSection = printSection;
