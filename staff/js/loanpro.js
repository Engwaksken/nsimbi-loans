/* LoanPro common JavaScript */
(function (window, document) {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function initDataTables() {
    if (!window.jQuery || !jQuery.fn || !jQuery.fn.DataTable) return;
    jQuery('.data-table').each(function () {
      if (jQuery.fn.DataTable.isDataTable(this)) return;
      jQuery(this).DataTable({
        order: [[0, 'desc']],
        pageLength: 20,
        responsive: true,
        language: {
          search: '',
          searchPlaceholder: 'Search…',
          lengthMenu: 'Show _MENU_',
          info: '_START_–_END_ of _TOTAL_'
        }
      });
    });
  }

  function initSidebarToggle() {
    var icon = document.getElementById('iconSidenav');
    var body = document.body;
    if (!icon || !body) return;
    icon.addEventListener('click', function () {
      body.classList.toggle('g-sidenav-pinned');
    });
  }

  function initAutoDismissAlerts() {
    document.querySelectorAll('.alert[data-auto-dismiss="true"]').forEach(function (alert) {
      setTimeout(function () { alert.style.display = 'none'; }, 5000);
    });
  }

  window.LoanPro = window.LoanPro || {};
  window.LoanPro.initDataTables = initDataTables;

  ready(function () {
    initSidebarToggle();
    initAutoDismissAlerts();
    initDataTables();
  });
})(window, document);
