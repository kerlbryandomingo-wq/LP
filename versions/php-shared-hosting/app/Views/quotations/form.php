<?php use App\Helpers\Csrf; use App\Helpers\View; ?>
<h1 class="page-title">New Quotation</h1>
<p class="page-subtitle">Prepare a quotation for customer approval.</p>

<form method="post" action="/quotations">
  <?= Csrf::field() ?>
  <?php if ($prefill): ?><input type="hidden" name="inquiry_id" value="<?= View::e($prefill['id']) ?>"><?php endif; ?>

  <div class="card"><div class="card-header">1. Customer Information</div><div class="card-body">
    <div class="field">
      <label class="field-label">Customer *</label>
      <select name="customer_id" required>
        <option value="">Select a customer...</option>
        <?php foreach ($customers as $c): ?>
          <option value="<?= View::e($c['id']) ?>" <?= ($prefill && $prefill['customer_id'] === $c['id']) ? 'selected' : '' ?>>
            <?= View::e($c['name']) ?> (<?= View::e($c['display_id']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div></div>

  <div class="card"><div class="card-header">2. Quotation Information</div><div class="card-body">
    <div class="field"><label class="field-label">Valid Until</label><input type="date" name="valid_until"></div>
  </div></div>

  <div class="card"><div class="card-header">3. Services / Line Items</div><div class="card-body">
    <table class="data-table" id="line-items-table">
      <thead><tr><th>Service</th><th>Description</th><th style="width:70px">Qty</th><th style="width:90px">Unit</th><th style="width:120px">Unit Price</th><th class="num" style="width:110px">Amount</th><th></th></tr></thead>
      <tbody>
        <tr>
          <td>
            <select name="service_id[]">
              <option value="">— None —</option>
              <?php foreach ($services as $s): ?><option value="<?= View::e($s['id']) ?>"><?= View::e($s['name']) ?></option><?php endforeach; ?>
            </select>
          </td>
          <td><input type="text" name="description[]" value="<?= $prefill ? View::e($prefill['desired_product']) : '' ?>" required></td>
          <td><input type="number" class="li-qty" name="qty[]" value="<?= $prefill ? (int)($prefill['rough_qty'] ?? 1) : 1 ?>" min="1"></td>
          <td><input type="text" name="unit[]" value="<?= $prefill ? View::e($prefill['rough_qty_unit'] ?? '') : '' ?>"></td>
          <td><input type="number" class="li-price" name="unit_price[]" value="0" min="0" step="0.01"></td>
          <td class="num li-amount">0.00</td>
          <td><button type="button" class="btn btn-ghost btn-sm li-remove">✕</button></td>
        </tr>
      </tbody>
    </table>
    <button type="button" id="add-line-item" class="btn btn-outline btn-sm mt-2">+ Add Line Item</button>

    <div class="grid grid-cols-2 mt-2" style="align-items:end">
      <div></div>
      <div>
        <div class="flex justify-between"><span>Subtotal</span><strong id="preview-subtotal">0.00</strong></div>
        <div class="field" style="margin-top:10px">
          <label class="field-label">Discount</label>
          <div class="flex gap-2">
            <select name="discount_type" style="max-width:140px">
              <option value="PERCENTAGE">Percentage</option>
              <option value="FIXED">Fixed Amount</option>
            </select>
            <input type="number" name="discount_value" value="0" min="0" step="0.01">
          </div>
        </div>
        <div class="flex justify-between"><span>Discount Amount</span><strong id="preview-discount">0.00</strong></div>
        <div class="field" style="margin-top:10px">
          <label class="field-label">Tax / VAT (%)</label>
          <input type="number" name="tax_pct" value="0" min="0" max="100" step="0.01">
        </div>
        <div class="flex justify-between"><span>Tax Amount</span><strong id="preview-tax">0.00</strong></div>
        <div class="flex justify-between" style="font-size:16px;margin-top:8px"><span>Grand Total</span><strong id="preview-total">0.00</strong></div>
        <p class="help-text">Final totals are recalculated and verified on the server when you submit.</p>
      </div>
    </div>
  </div></div>

  <div class="card"><div class="card-header">4. Notes / Terms</div><div class="card-body">
    <textarea name="notes" rows="3" placeholder="Add notes, terms, or special instructions..."></textarea>
  </div></div>

  <div class="flex gap-2">
    <a href="/quotations" class="btn btn-outline">Cancel</a>
    <button type="submit" class="btn btn-primary">Create Quotation</button>
  </div>
</form>
