/**
 * Client books dashboard behaviour: collapsible filter and rows-per-page jump.
 */
(function () {
    'use strict';

    var toggle = document.getElementById('booksDashFiltersToggle');
    var body = document.getElementById('booksDashFiltersBody');

    if (toggle && body) {
        toggle.addEventListener('click', function () {
            var expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            body.style.display = expanded ? 'none' : '';
        });
    }

    var pageSizeSelect = document.getElementById('booksDashPageSizeSelect');

    if (pageSizeSelect) {
        pageSizeSelect.addEventListener('change', function () {
            var href = this.options[this.selectedIndex].dataset.href;
            if (href) {
                window.location.href = href;
            }
        });
    }
})();
