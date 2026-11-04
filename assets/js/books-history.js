/**
 * Rows-per-page jump for the Books History report.
 */
(function () {
    'use strict';

    var pageSizeSelect = document.getElementById('booksHistoryPageSize');

    if (!pageSizeSelect) {
        return;
    }

    pageSizeSelect.addEventListener('change', function () {
        var href = this.options[this.selectedIndex].dataset.href;

        if (href) {
            window.location.href = href;
        }
    });
})();
