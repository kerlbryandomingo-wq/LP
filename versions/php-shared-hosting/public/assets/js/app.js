/* Vanilla JS interactions — no framework at runtime. Everything here is a
   convenience; the server validates and recomputes everything itself. */

document.addEventListener('DOMContentLoaded', function () {
  // Mobile nav drawer
  var toggle = document.querySelector('.mobile-nav-toggle');
  var drawer = document.querySelector('.mobile-nav-drawer');
  if (toggle && drawer) {
    toggle.addEventListener('click', function () { drawer.classList.add('open'); });
    drawer.querySelector('.backdrop').addEventListener('click', function () { drawer.classList.remove('open'); });
  }

  // Modals: <button data-modal-open="id"> / <div id="id" class="modal-overlay" hidden> / [data-modal-close]
  function openModal(modal) {
    modal.hidden = false;
    var first = modal.querySelector('input:not([type=hidden]), textarea, select');
    if (first) first.focus();
  }
  document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var modal = document.getElementById(btn.getAttribute('data-modal-open'));
      if (modal) openModal(modal);
    });
  });
  document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var modal = btn.closest('.modal-overlay');
      if (modal) modal.hidden = true;
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-overlay:not([hidden])').forEach(function (m) { m.hidden = true; });
    }
  });

  // Confirmation prompts: <form data-confirm="Are you sure?">
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.confirm(form.getAttribute('data-confirm'))) {
        e.preventDefault();
        e.stopImmediatePropagation();
      }
    });
  });

  // Double-click protection: <form data-once> disables its submit button
  // after the first valid submit. (The server also refuses duplicates.)
  document.querySelectorAll('form[data-once]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return;
      if (form.classList.contains('is-submitting')) { e.preventDefault(); return; }
      form.classList.add('is-submitting');
      form.querySelectorAll('button[type=submit], button:not([type])').forEach(function (b) {
        b.setAttribute('aria-disabled', 'true');
      });
    });
  });
  // Restore forms when the page is shown from the back/forward cache.
  window.addEventListener('pageshow', function () {
    document.querySelectorAll('form.is-submitting').forEach(function (f) { f.classList.remove('is-submitting'); });
  });

  // Record Payment: live remaining-balance preview.
  document.querySelectorAll('input[data-balance]').forEach(function (input) {
    var out = input.closest('form').querySelector('[data-balance-preview]');
    var balance = parseFloat(input.getAttribute('data-balance')) || 0;
    function update() {
      if (!out) return;
      var v = parseFloat(input.value);
      if (!(v > 0)) { out.textContent = ''; return; }
      var after = Math.round((balance - v) * 100) / 100;
      out.textContent = after < 0
        ? 'This is more than the outstanding balance.'
        : 'Balance after this payment is confirmed: ₱' + after.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    input.addEventListener('input', update);
  });

  // Quotation line items: live totals preview (display only — the server
  // recomputes with PricingService::computeTotals on save).
  var lineItemsTable = document.getElementById('line-items-table');
  if (lineItemsTable) {
    var round2 = function (n) { return Math.round((n + Number.EPSILON) * 100) / 100; };
    var fmt = function (n) { return n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var form = lineItemsTable.closest('form');
    function recalc() {
      var subtotal = 0;
      lineItemsTable.querySelectorAll('tbody tr').forEach(function (row) {
        var qty = parseInt(row.querySelector('.li-qty').value, 10) || 0;
        var price = parseFloat(row.querySelector('.li-price').value) || 0;
        var amount = round2(qty * price);
        row.querySelector('.li-amount').textContent = fmt(amount);
        subtotal += amount;
      });
      subtotal = round2(subtotal);
      var type = form.querySelector('[name=discount_type]').value;
      var dv = Math.max(0, parseFloat(form.querySelector('[name=discount_value]').value) || 0);
      var tax = Math.min(100, Math.max(0, parseFloat(form.querySelector('[name=tax_pct]').value) || 0));
      var discount = type === 'FIXED' ? round2(Math.min(dv, subtotal)) : round2(subtotal * Math.min(100, dv) / 100);
      var taxable = Math.max(0, round2(subtotal - discount));
      var taxAmount = round2(taxable * tax / 100);
      document.getElementById('preview-subtotal').textContent = fmt(subtotal);
      document.getElementById('preview-discount').textContent = fmt(discount);
      document.getElementById('preview-tax').textContent = fmt(taxAmount);
      document.getElementById('preview-total').textContent = fmt(round2(taxable + taxAmount));
    }
    form.addEventListener('input', recalc);
    form.addEventListener('change', recalc);
    recalc();

    document.getElementById('add-line-item').addEventListener('click', function () {
      var tbody = lineItemsTable.querySelector('tbody');
      var rows = tbody.querySelectorAll('tr');
      var newRow = rows[rows.length - 1].cloneNode(true);
      newRow.querySelectorAll('input').forEach(function (i) {
        if (i.type !== 'hidden') i.value = i.classList.contains('li-qty') ? '1' : '';
      });
      newRow.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
      tbody.appendChild(newRow);
      var first = newRow.querySelector('input[name="description[]"]');
      if (first) first.focus();
      recalc();
    });
    lineItemsTable.addEventListener('click', function (e) {
      if (e.target.closest('.li-remove')) {
        var tbody = lineItemsTable.querySelector('tbody');
        if (tbody.querySelectorAll('tr').length > 1) {
          e.target.closest('tr').remove();
          recalc();
        }
      }
    });
  }
});
