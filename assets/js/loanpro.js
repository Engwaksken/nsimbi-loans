

'use strict';

/* =========================================================
   TOASTS
========================================================= */
window.Toast = {
    _container: null,

    _init() {
        if (!this._container) {
            this._container = document.querySelector('.toast-container');

            if (!this._container) {
                this._container = document.createElement('div');
                this._container.className = 'toast-container';
                document.body.appendChild(this._container);
            }
        }
    },

    show(message, type = 'info', duration = 4200) {
        this._init();

        const icons = {
            success: 'check-circle',
            danger: 'times-circle',
            error: 'times-circle',
            warning: 'exclamation-triangle',
            info: 'info-circle'
        };

        const cleanType = type === 'error' ? 'danger' : type;

        const toast = document.createElement('div');
        toast.className = `toast t-${cleanType}`;
        toast.innerHTML = `
            <span class="toast-ico">
                <i class="fas fa-${icons[type] || icons.info}"></i>
            </span>
            <span class="toast-msg">${escapeHtml(message)}</span>
        `;

        this._container.appendChild(toast);

        setTimeout(() => {
            toast.remove();
        }, duration);
    },

    success(message) {
        this.show(message, 'success');
    },

    error(message) {
        this.show(message, 'danger');
    },

    danger(message) {
        this.show(message, 'danger');
    },

    warn(message) {
        this.show(message, 'warning');
    },

    warning(message) {
        this.show(message, 'warning');
    },

    info(message) {
        this.show(message, 'info');
    }
};

/* =========================================================
   CUSTOM MODALS - NO BOOTSTRAP REQUIRED
========================================================= */
window.Modal = {
    _stack: [],

    _create(id, options = {}) {
        let modal = document.getElementById(id);

        if (!modal) {
            modal = document.createElement('div');
            modal.id = id;
            modal.className = 'modal-overlay';

            modal.innerHTML = `
                <div class="modal-box ${options.size || 'lg'}">
                    <div class="modal-header">
                        <div class="modal-title">
                            <div class="modal-ico">
                                <i class="fas fa-${options.icon || 'circle'}"></i>
                            </div>
                            <span class="modal-title-text">${escapeHtml(options.title || 'Details')}</span>
                        </div>

                        <button type="button" class="modal-close" data-modal-close="${id}">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <div class="modal-body" id="${id}-body">
                        ${loaderHtml()}
                    </div>

                    ${options.footer === false ? '' : `<div class="modal-footer" id="${id}-footer"></div>`}
                </div>
            `;

            document.body.appendChild(modal);

            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    Modal.close(id);
                }
            });

            const closeBtn = modal.querySelector('[data-modal-close]');

            if (closeBtn) {
                closeBtn.addEventListener('click', function () {
                    Modal.close(id);
                });
            }
        }

        return modal;
    },

    open(id, options = {}) {
        const modal = this._create(id, options);

        const title = modal.querySelector('.modal-title-text');
        const icon = modal.querySelector('.modal-ico i');

        if (title && options.title) {
            title.textContent = options.title;
        }

        if (icon && options.icon) {
            icon.className = `fas fa-${options.icon}`;
        }

        document.body.style.overflow = 'hidden';

        requestAnimationFrame(() => {
            modal.classList.add('open');
        });

        if (!this._stack.includes(id)) {
            this._stack.push(id);
        }

        return modal;
    },

    close(id) {
        const modal = document.getElementById(id);

        if (!modal) return;

        modal.classList.remove('open');

        this._stack = this._stack.filter(item => item !== id);

        if (this._stack.length === 0) {
            document.body.style.overflow = '';
        }
    },

    closeAll() {
        [...this._stack].forEach(id => this.close(id));
    },

    setBody(id, html) {
        const body = document.getElementById(`${id}-body`);

        if (body) {
            body.innerHTML = html;
        }
    },

    setFooter(id, html) {
        const footer = document.getElementById(`${id}-footer`);

        if (footer) {
            footer.innerHTML = html;
        }
    },

    loading(id) {
        this.setBody(id, loaderHtml());
    }
};

/* =========================================================
   AJAX HELPERS
========================================================= */
window.Ajax = {
    async get(url) {
        const response = await fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/html, application/xhtml+xml'
            }
        });

        const text = await response.text();

        if (!response.ok) {
            throw new Error(text || `HTTP ${response.status}`);
        }

        return text;
    },

    async post(url, data = {}) {
        const isFormData = data instanceof FormData;

        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            body: isFormData ? data : new URLSearchParams(data),
            headers: isFormData
                ? {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
                : {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
        });

        const text = await response.text();

        let json;

        try {
            json = JSON.parse(text);
        } catch (error) {
            json = {
                success: response.ok,
                message: text
            };
        }

        if (!response.ok) {
            throw new Error(json.message || text || `HTTP ${response.status}`);
        }

        return json;
    }
};

/* =========================================================
   CLIENT MODALS
========================================================= */
window.viewClient = async function (id) {
    const modalId = 'm-view-client';

    Modal.open(modalId, {
        title: 'Client Profile',
        icon: 'user',
        size: 'lg'
    });

    Modal.loading(modalId);

    try {
        const html = await Ajax.get(
            `view_client.php?user_id=${encodeURIComponent(id)}&modal=1&ajax=1`
        );

        Modal.setBody(modalId, normalizeModalHtml(html));
        bindAjaxForms(modalId);

    } catch (error) {
        console.error(error);
        Modal.setBody(modalId, errorHtml(error.message || 'Failed to load client profile.'));
    }
};

window.editClientModal = async function (id) {
    const modalId = 'm-edit-client';

    Modal.open(modalId, {
        title: 'Edit Client',
        icon: 'user-edit',
        size: 'lg'
    });

    Modal.loading(modalId);

    try {
        const html = await Ajax.get(
            `view_client.php?user_id=${encodeURIComponent(id)}&modal=1&ajax=1&tab=edit`
        );

        Modal.setBody(modalId, normalizeModalHtml(html));
        bindAjaxForms(modalId);

    } catch (error) {
        console.error(error);
        Modal.setBody(modalId, errorHtml(error.message || 'Failed to load edit form.'));
    }
};

window.clientLoanDetails = async function (id) {
    const modalId = 'm-client-loans';

    Modal.open(modalId, {
        title: 'Client Loan Details',
        icon: 'file-invoice-dollar',
        size: 'lg'
    });

    Modal.loading(modalId);

    try {
        const html = await Ajax.get(
            `client_loan_details.php?client_id=${encodeURIComponent(id)}&modal=1&ajax=1`
        );

        Modal.setBody(modalId, normalizeModalHtml(html));
        bindAjaxForms(modalId);

    } catch (error) {
        console.error(error);
        Modal.setBody(modalId, errorHtml(error.message || 'Failed to load loan details.'));
    }
};

window.deleteClient = async function (id) {
    if (!confirm('Are you sure you want to delete this client?')) {
        return;
    }

    try {
        const data = await Ajax.post('delete_client.php', {
            client_id: id
        });

        if (!data.success) {
            throw new Error(data.message || 'Delete failed.');
        }

        const row = document.getElementById(`client-row-${id}`);

        if (row) {
            row.style.opacity = '.35';

            setTimeout(() => {
                row.remove();
            }, 250);
        }

        Toast.success(data.message || 'Client deleted successfully.');

    } catch (error) {
        console.error(error);
        Toast.error(error.message || 'Delete request failed.');
    }
};

/* =========================================================
   LOAN MODALS
========================================================= */
window.viewLoan = async function (id) {
    const modalId = 'm-view-loan';

    Modal.open(modalId, {
        title: `Loan #${id}`,
        icon: 'file-invoice-dollar',
        size: 'lg'
    });

    Modal.loading(modalId);

    try {
        const html = await Ajax.get(`ajax/view_loan.php?id=${encodeURIComponent(id)}`);
        Modal.setBody(modalId, normalizeModalHtml(html));
        bindAjaxForms(modalId);

    } catch (error) {
        console.error(error);
        Modal.setBody(modalId, errorHtml('Failed to load loan details.'));
    }
};

window.addLoanModal = async function () {
    const modalId = 'm-add-loan';

    Modal.open(modalId, {
        title: 'Issue New Loan',
        icon: 'plus-circle',
        size: 'lg'
    });

    Modal.loading(modalId);

    try {
        const html = await Ajax.get('ajax/add_loan_form.php');
        Modal.setBody(modalId, normalizeModalHtml(html));
        bindAjaxForms(modalId);

    } catch (error) {
        console.error(error);
        Modal.setBody(modalId, errorHtml('Failed to load loan form.'));
    }
};

window.addPaymentModal = async function (loanId) {
    const modalId = 'm-add-payment';

    Modal.open(modalId, {
        title: `Add Payment — Loan #${loanId}`,
        icon: 'money-bill-wave',
        size: 'md'
    });

    Modal.loading(modalId);

    try {
        const html = await Ajax.get(`ajax/payment_form.php?loan_id=${encodeURIComponent(loanId)}`);
        Modal.setBody(modalId, normalizeModalHtml(html));
        bindAjaxForms(modalId);

    } catch (error) {
        console.error(error);
        Modal.setBody(modalId, errorHtml('Failed to load payment form.'));
    }
};

window.toggleClientStatus = async function (userId, current) {
    const action = Number(current) === 1 ? 'Block' : 'Activate';

    if (!confirm(`${action} this client?`)) {
        return;
    }

    try {
        const data = await Ajax.post('ajax/toggle_client.php', {
            user_id: userId,
            status: current
        });

        if (!data.success) {
            throw new Error(data.message || 'Action failed.');
        }

        Toast.success(data.message || `Client ${action.toLowerCase()}d successfully.`);

        setTimeout(() => {
            window.location.reload();
        }, 700);

    } catch (error) {
        console.error(error);
        Toast.error(error.message || 'Action failed.');
    }
};

/* =========================================================
   AJAX FORM BINDER
========================================================= */
function bindAjaxForms(modalId = '') {
    const scope = modalId
        ? document.getElementById(`${modalId}-body`)
        : document;

    if (!scope) return;

    const forms = scope.querySelectorAll('form.ajax-form, form[data-ajax="true"]');

    forms.forEach(form => {
        if (form.dataset.ajaxBound === '1') {
            return;
        }

        form.dataset.ajaxBound = '1';

        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            const button = form.querySelector('[type="submit"]');
            const oldHtml = button ? button.innerHTML : '';

            try {
                if (button) {
                    button.disabled = true;
                    button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';
                }

                const data = await Ajax.post(form.action, new FormData(form));

                if (!data.success) {
                    throw new Error(data.message || 'Save failed.');
                }

                Toast.success(data.message || 'Saved successfully.');

                setTimeout(() => {
                    window.location.reload();
                }, 700);

            } catch (error) {
                console.error(error);
                Toast.error(error.message || 'Request failed.');
            } finally {
                if (button) {
                    button.disabled = false;
                    button.innerHTML = oldHtml;
                }
            }
        });
    });
}

/* =========================================================
   LOAN VIEW PAGE INTERACTIONS
========================================================= */
window.LoanView = (() => {

    function injectTabFallbackCss() {
        if (document.getElementById('loan-view-tab-fallback-css')) {
            return;
        }

        const style = document.createElement('style');
        style.id = 'loan-view-tab-fallback-css';
        style.textContent = `
            .loan-view-page .tab-pane {
                display: none;
            }

            .loan-view-page .tab-pane.show.active,
            .loan-view-page .tab-pane.active {
                display: block;
            }
        `;

        document.head.appendChild(style);
    }

    function initTabs() {
        const tabKey = 'loanView_activeTab_' + window.location.pathname + window.location.search.replace(/([?&])export=pdf(&|$)/, '$1');

        const tabList =
            document.getElementById('loanTabs') ||
            document.querySelector('.loan-view-page .loan-tabs') ||
            document.querySelector('.loan-tabs');

        if (!tabList) return;

        injectTabFallbackCss();

        const tabButtons = Array.from(
            tabList.querySelectorAll('[data-bs-toggle="pill"], [data-bs-toggle="tab"], [data-tab-target]')
        );

        if (!tabButtons.length) return;

        const panes = Array.from(document.querySelectorAll('.loan-view-page .tab-pane'));

        function getTarget(button) {
            return (
                button.getAttribute('data-bs-target') ||
                button.getAttribute('data-target') ||
                button.getAttribute('href') ||
                button.getAttribute('data-tab-target') ||
                ''
            );
        }

        function showTab(button, save = true) {
            const target = getTarget(button);

            if (!target || target === '#') return;

            const pane = document.querySelector(target);

            if (!pane) return;

            tabButtons.forEach(btn => {
                btn.classList.remove('active');
                btn.setAttribute('aria-selected', 'false');
            });

            panes.forEach(item => {
                item.classList.remove('show', 'active');
                item.style.display = 'none';
            });

            button.classList.add('active');
            button.setAttribute('aria-selected', 'true');

            pane.classList.add('show', 'active');
            pane.style.display = 'block';

            if (save) {
                sessionStorage.setItem(tabKey, target);
            }

            if (target === '#payments' || target === '#payment-history-pane') {
                animateBalances();
            }
        }

        tabButtons.forEach(button => {
            if (button.dataset.loanTabBound === '1') {
                return;
            }

            button.dataset.loanTabBound = '1';

            button.addEventListener('click', function (event) {
                event.preventDefault();
                showTab(button, true);
            });
        });

        let savedTarget = sessionStorage.getItem(tabKey);

        let initialButton = null;

        if (savedTarget) {
            initialButton = tabButtons.find(btn => getTarget(btn) === savedTarget);
        }

        if (!initialButton) {
            initialButton =
                tabButtons.find(btn => btn.classList.contains('active')) ||
                tabButtons[0];
        }

        showTab(initialButton, false);
    }

    function initPaymentForm() {
        const form = document.getElementById('form-add-payment-inline');

        if (!form) return;

        const amountInput = form.querySelector('[name="amount"]');
        const maxBalance = parseFloat(form.dataset.balance || 0);
        const feedback = document.getElementById('payment-feedback');

        if (amountInput) {
            amountInput.addEventListener('input', () => {
                const value = parseFloat(amountInput.value) || 0;

                if (value > maxBalance) {
                    amountInput.value = maxBalance;
                }

                if (feedback) {
                    feedback.textContent = '';
                    feedback.style.display = 'none';
                }
            });
        }

        if (form.dataset.paymentBound === '1') {
            return;
        }

        form.dataset.paymentBound = '1';

        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (feedback) {
                feedback.textContent = '';
                feedback.style.display = 'none';
            }

            const amountValue = parseFloat(amountInput ? amountInput.value : 0) || 0;

            if (amountValue <= 0) {
                if (feedback) {
                    feedback.textContent = 'Please enter a valid payment amount.';
                    feedback.style.display = 'block';
                }

                Toast.warn('Please enter a valid payment amount.');
                return;
            }

            if (maxBalance > 0 && amountValue > maxBalance) {
                if (feedback) {
                    feedback.textContent = 'Payment amount cannot exceed the outstanding balance.';
                    feedback.style.display = 'block';
                }

                Toast.warn('Payment amount cannot exceed the outstanding balance.');
                return;
            }

            const button = form.querySelector('[type="submit"]');
            const oldHtml = button ? button.innerHTML : '';

            try {
                if (button) {
                    button.disabled = true;
                    button.innerHTML = '<span class="lp-spinner"></span> Saving...';
                }

                const actionUrl = form.getAttribute('action') || 'added_payment.php';
                const data = await Ajax.post(actionUrl, new FormData(form));

                if (!data.success) {
                    throw new Error(data.message || 'Failed to save payment.');
                }

                Toast.success(data.message || 'Payment recorded successfully.');

                setTimeout(() => {
                    window.location.reload();
                }, 900);

            } catch (error) {
                console.error(error);

                Toast.error(error.message || 'Server error. Please try again.');

                if (feedback) {
                    feedback.textContent = error.message || 'An error occurred.';
                    feedback.className = 'lp-form-error';
                    feedback.style.display = 'block';
                }
            } finally {
                if (button) {
                    button.innerHTML = oldHtml;
                    button.disabled = false;
                }
            }
        });
    }

    function animateBalances() {
        document.querySelectorAll('[data-balance]').forEach(element => {
            if (element.dataset.animated === '1') {
                return;
            }

            element.dataset.animated = '1';

            const target = parseFloat(element.dataset.balance) || 0;
            const start = target * 1.08;
            const duration = 600;
            const startTime = performance.now();

            function tick(now) {
                const percent = Math.min((now - startTime) / duration, 1);
                const eased = 1 - Math.pow(1 - percent, 3);
                const current = start + (target - start) * eased;

                element.textContent = formatUGX(current);

                if (percent < 1) {
                    requestAnimationFrame(tick);
                } else {
                    element.textContent = formatUGX(target);
                }
            }

            requestAnimationFrame(tick);
        });
    }

    function initPdfExport() {
        const button = document.getElementById('btn-export-pdf');

        if (!button) return;

        if (button.dataset.pdfBound === '1') {
            return;
        }

        button.dataset.pdfBound = '1';

        button.addEventListener('click', function () {
            const oldHtml = button.innerHTML;

            button.innerHTML = '<span class="lp-spinner"></span> Generating...';
            button.classList.add('disabled');

            setTimeout(() => {
                button.innerHTML = oldHtml;
                button.classList.remove('disabled');
            }, 7000);
        });
    }

    function init() {
        initTabs();
        initPaymentForm();
        initPdfExport();
    }

    return {
        init,
        initTabs,
        animateBalances
    };
})();

/* =========================================================
   PAGE UTILITIES
========================================================= */
window.toggleSidebar = function () {
    const sidebar = document.querySelector('.sidebar');

    if (sidebar) {
        sidebar.classList.toggle('open');
    }
};

window.toggleNotifications = function () {
    const panel = document.getElementById('notif-panel');

    if (!panel) return;

    panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
};

window.download_table_as_csv = function (tableId, filename = 'export.csv') {
    const table = document.getElementById(tableId);

    if (!table) return;

    const csv = Array.from(table.querySelectorAll('tr'))
        .map(row => {
            return Array.from(row.querySelectorAll('th,td'))
                .map(cell => `"${cell.innerText.replace(/"/g, '""').trim()}"`)
                .join(',');
        })
        .join('\n');

    const link = document.createElement('a');
    link.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    link.download = filename;
    link.click();
};

window.buildPagination = function (containerId, currentPage, totalPages, onPage) {
    const container = document.getElementById(containerId);

    if (!container) return;

    let html = '';

    html += `
        <button class="pg-btn ${currentPage <= 1 ? 'dis' : ''}" onclick="${onPage}(${currentPage - 1})">
            <i class="fas fa-chevron-left"></i>
        </button>
    `;

    for (let i = 1; i <= totalPages; i++) {
        if (
            totalPages > 7 &&
            i > 2 &&
            i < totalPages - 1 &&
            Math.abs(i - currentPage) > 1
        ) {
            if (i === 3 || i === totalPages - 2) {
                html += '<span class="pg-dots">...</span>';
            }
            continue;
        }

        html += `
            <button class="pg-btn ${i === currentPage ? 'act' : ''}" onclick="${onPage}(${i})">
                ${i}
            </button>
        `;
    }

    html += `
        <button class="pg-btn ${currentPage >= totalPages ? 'dis' : ''}" onclick="${onPage}(${currentPage + 1})">
            <i class="fas fa-chevron-right"></i>
        </button>
    `;

    container.innerHTML = html;
};

/* =========================================================
   GLOBAL EVENTS
========================================================= */
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        Modal.closeAll();
    }
});

document.addEventListener('click', function (event) {
    document.querySelectorAll('.dropdown.open').forEach(dropdown => {
        if (!dropdown.contains(event.target)) {
            dropdown.classList.remove('open');
        }
    });

    const dropdownTrigger = event.target.closest('[data-dd]');

    if (dropdownTrigger) {
        const dropdown = dropdownTrigger.closest('.dropdown');

        if (dropdown) {
            dropdown.classList.toggle('open');
        }
    }

    const notificationPanel = document.getElementById('notif-panel');

    if (
        notificationPanel &&
        notificationPanel.style.display === 'block' &&
        !notificationPanel.contains(event.target) &&
        !event.target.closest('[onclick*=toggleNotifications]')
    ) {
        notificationPanel.style.display = 'none';
    }

    const sidebar = document.querySelector('.sidebar');

    if (
        sidebar &&
        sidebar.classList.contains('open') &&
        !sidebar.contains(event.target) &&
        !event.target.closest('.hamburger')
    ) {
        sidebar.classList.remove('open');
    }
});

/* =========================================================
   INIT
========================================================= */
document.addEventListener('DOMContentLoaded', function () {
    if (window.LoanView) {
        LoanView.init();
    }

    bindAjaxForms();

    const currentPage = window.location.pathname.split('/').pop();

    document.querySelectorAll('.sidebar .nav-link, .main-nav .nav-link').forEach(link => {
        const href = link.getAttribute('href');

        if (href && href === currentPage) {
            link.classList.add('active');
        }
    });

    const flash = document.getElementById('_flash');

    if (flash) {
        const type = flash.dataset.type || 'info';
        const message = flash.textContent.trim();

        if (type === 'success') Toast.success(message);
        else if (type === 'error' || type === 'danger') Toast.error(message);
        else if (type === 'warning') Toast.warn(message);
        else Toast.info(message);

        flash.remove();
    }

    document.querySelectorAll('.js-view-client').forEach(button => {
        button.addEventListener('click', function () {
            viewClient(this.dataset.id);
        });
    });

    document.querySelectorAll('.js-edit-client').forEach(button => {
        button.addEventListener('click', function () {
            editClientModal(this.dataset.id);
        });
    });

    document.querySelectorAll('.js-loan-details').forEach(button => {
        button.addEventListener('click', function () {
            clientLoanDetails(this.dataset.id);
        });
    });

    document.querySelectorAll('.js-delete-client').forEach(button => {
        button.addEventListener('click', function () {
            deleteClient(this.dataset.id);
        });
    });
});

/* =========================================================
   HELPERS
========================================================= */
function loaderHtml() {
    return `
        <div class="ajax-loading">
            <div class="spinner"></div>
            <span>Loading...</span>
        </div>
    `;
}

function errorHtml(message) {
    return `
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <span>${escapeHtml(message)}</span>
        </div>
    `;
}

function normalizeModalHtml(html) {
    const raw = String(html || '').trim();

    if (!raw) {
        return `
            <div class="empty-state">
                <i class="fas fa-info-circle"></i>
                <h3>No content returned</h3>
            </div>
        `;
    }

    const parser = new DOMParser();
    const doc = parser.parseFromString(raw, 'text/html');

    const partial =
        doc.querySelector('[data-modal-content]') ||
        doc.querySelector('#modalContent') ||
        doc.querySelector('.modal-partial') ||
        doc.querySelector('.client-modal-partial');

    if (partial) {
        return partial.innerHTML;
    }

    const body = doc.body ? doc.body.innerHTML.trim() : '';

    return body || raw;
}

function formatUGX(amount) {
    return 'UGX ' + Math.round(amount).toLocaleString('en-UG');
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}