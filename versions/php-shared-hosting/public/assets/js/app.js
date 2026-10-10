/* Vanilla JS interactions — no React/Next.js at runtime. Mirrors the
   visible behavior of the original app's modals/mobile nav/comboboxes. */

document.addEventListener('DOMContentLoaded', function () {
  // Mobile nav drawer
  var toggle = document.querySelector('.mobile-nav-toggle');
  var drawer = document.querySelector('.mobile-nav-drawer');
  if (toggle && drawer) {
    toggle.addEventListener('click', function () { drawer.classList.add('open'); });
    drawer.querySelector('.backdrop').addEventListener('click', function () { drawer.classList.remove('open'); });
  }

  // Generic modal open/close via data attributes:
  // <button data-modal-open="modal-id">...
  // <div id="modal-id" class="modal-overlay" hidden>...<button data-modal-close>
  document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var modal = document.getElementById(btn.getAttribute('data-modal-open'));
      if (modal) modal.hidden = false;
    });
  });
  document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var modal = btn.closest('.modal-overlay');
      if (modal) modal.hidden = true;
    });
  });

  // Live client-side table filter (server remains the source of truth for
  // any actual query — this only hides/shows already-rendered rows, same
  // "never trust the client" rule as the original app's search boxes).
  document.querySelectorAll('[data-table-filter]').forEach(function (input) {
    var tableId = input.getAttribute('data-table-filter');
    var table = document.getElementById(tableId);
    if (!table) return;
    input.addEventListener('input', function () {
      var q = input.value.toLowerCase();
      table.querySelectorAll('tbody tr').forEach(function (row) {
        row.style.display = row.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
      });
    });
  });

  // Quotation/Order line-item live totals preview (client-side display
  // only; the authoritative total is always recomputed server-side in
  // PricingService::computeTotals on submit — see SECURITY.md "never
  // trust client-side calculations").
  var lineItemsTable = document.getElementById('line-items-table');
  if (lineItemsTable) {
    function recalcPreview() {
      var subtotal = 0;
      lineItemsTable.querySelectorAll('tbody tr').forEach(function (row) {
        var qty = parseFloat(row.querySelector('.li-qty').value) || 0;
        var price = parseFloat(row.querySelector('.li-price').value) || 0;
        var amount = qty * price;
        row.querySelector('.li-amount').textContent = amount.toFixed(2);
        subtotal += amount;
      });
      var subtotalEl = document.getElementById('preview-subtotal');
      if (subtotalEl) subtotalEl.textContent = subtotal.toFixed(2);

      var discountType = document.querySelector('[name=discount_type]');
      var discountValue = parseFloat(document.querySelector('[name=discount_value]').value) || 0;
      var taxPct = parseFloat(document.querySelector('[name=tax_pct]').value) || 0;
      var discountAmount = discountType && discountType.value === 'FIXED' ? Math.min(discountValue, subtotal) : subtotal * Math.min(100, discountValue) / 100;
      var taxable = Math.max(0, subtotal - discountAmount);
      var taxAmount = taxable * Math.min(100, taxPct) / 100;
      var total = taxable + taxAmount;

      var discountEl = document.getElementById('preview-discount');
      if (discountEl) discountEl.textContent = discountAmount.toFixed(2);
      var taxEl = document.getElementById('preview-tax');
      if (taxEl) taxEl.textContent = taxAmount.toFixed(2);
      var totalEl = document.getElementById('preview-total');
      if (totalEl) totalEl.textContent = total.toFixed(2);
    }
    lineItemsTable.addEventListener('input', recalcPreview);
    document.addEventListener('input', function (e) {
      if (e.target.name === 'discount_type' || e.target.name === 'discount_value' || e.target.name === 'tax_pct') recalcPreview();
    });
    recalcPreview();

    document.getElementById('add-line-item')?.addEventListener('click', function () {
      var tbody = lineItemsTable.querySelector('tbody');
      var rows = tbody.querySelectorAll('tr');
      var newRow = rows[rows.length - 1].cloneNode(true);
      newRow.querySelectorAll('input').forEach(function (i) { i.value = i.classList.contains('li-qty') ? '1' : ''; });
      tbody.appendChild(newRow);
      recalcPreview();
    });
    lineItemsTable.addEventListener('click', function (e) {
      if (e.target.matches('.li-remove')) {
        var tbody = lineItemsTable.querySelector('tbody');
        if (tbody.querySelectorAll('tr').length > 1) {
          e.target.closest('tr').remove();
          recalcPreview();
        }
      }
    });
  }
});
