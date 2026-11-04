/**
 * Books History tab behaviour on view_client.php: collapsible filters and the
 * rows-per-page jump.
 */
(function () {
    'use strict';

    var filtersToggle = document.getElementById('booksFiltersToggle');
    var filtersBody = document.getElementById('booksFiltersBody');

    if (filtersToggle && filtersBody) {
        filtersToggle.addEventListener('click', function () {
            var expanded = filtersToggle.getAttribute('aria-expanded') === 'true';

            filtersToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            filtersBody.style.display = expanded ? 'none' : '';
        });
    }

    var pageSizeSelect = document.getElementById('booksPageSizeSelect');

    if (pageSizeSelect) {
        pageSizeSelect.addEventListener('change', function () {
            var href = this.options[this.selectedIndex].dataset.href;

            if (href) {
                window.location.href = href;
            }
        });
    }
})();
