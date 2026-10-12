<?php
use App\Helpers\Csrf;
use App\Helpers\FormToken;
use App\Helpers\View;
/** @var array|null $quotation @var array $old @var array $lines */
$editing = $quotation !== null;
$action = $editing ? url('/quotations/' . $quotation['id']) : url('/quotations');
$lockedCustomer = $editing && !empty($quotation['inquiry_id']);
$serviceOptions = function (string $selected) use ($services): string {
    $html = '<option value="">— None —</option>';
    foreach ($services as $s) {
        $html .= '<option value="' . e($s['id']) . '"' . ($selected === $s['id'] ? ' selected' : '') . '>' . e($s['name']) . '</option>';
    }
    return $html;
};
?>
<h1 class="page-title"><?= $editing ? 'Edit Quotation ' . View::e($quotation['quote_number']) : 'New Quotation' ?></h1>
<p class="page-subtitle"><?= $editing ? 'Changes are re-totalled on the server when you save.' : 'Prepare a quotation for customer approval.' ?></p>
<?php require __DIR__ . '/../partials/errors.php'; ?>

<form method="post" action="<?= e($action) ?>" data-once id="quotation-form">
  <?= Csrf::field() ?>
  <?= $editing ? '' : FormToken::field() ?>
  <?php if (!$editing && old($old, 'inquiry_id') !== ''): ?><input type="hidden" name="inquiry_id" value="<?= e(old($old, 'inquiry_id')) ?>"><?php endif; ?>

  <div class="card"><div class="card-header">1. Customer Information</div><div class="card-body">
    <div class="field">
      <label class="field-label" for="customer_id">Customer *</label>
      <?php if ($lockedCustomer): ?>
        <input type="hidden" name="customer_id" value="<?= e($quotation['customer_id']) ?>">
        <p><?= View::e($quotation['customer_name']) ?> <span class="help-text">(from the linked inquiry)</span></p>
      <?php else: ?>
      <select id="customer_id" name="customer_id" required>
        <option value="">Select a customer…</option>
        <?php foreach ($customers as $c): ?>
          <option value="<?= View::e($c['id']) ?>" <?= old($old, 'customer_id') === $c['id'] ? 'selected' : '' ?>><?= View::e($c['name']) ?> (<?= View::e($c['display_id']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <?php if (!$customers): ?><p class="help-text">No customers yet — <a href="<?= e(url('/customers/new')) ?>">create one first</a>.</p><?php endif; ?>
      <?php endif; ?>
    </div>
  </div></div>

  <div class="card"><div class="card-header">2. Quotation Information</div><div class="card-body">
    <div class="field" style="max-width:240px"><label class="field-label" for="valid_until">Valid Until</label><input type="date" id="valid_until" name="valid_until" value="<?= e(old($old, 'valid_until')) ?>"></div>
  </div></div>

  <div class="card"><div class="card-header">3. Services / Line Items</div><div class="card-body table-wrap">
    <table class="data-table line-items" id="line-items-table">
      <thead><tr><?php if ($services): ?><th>Service</th><?php endif; ?><th>Description *</th><th style="width:80px">Qty *</th><th style="width:90px">Unit</th><th style="width:130px">Unit Price *</th><th class="num" style="width:120px">Amount</th><th style="width:40px"><span class="sr-only">Remove</span></th></tr></thead>
      <tbody>
        <?php foreach ($lines as $li): ?>
        <tr>
          <?php if ($services): ?><td><select name="service_id[]" aria-label="Service"><?= $serviceOptions((string) ($li['service_id'] ?? '')) ?></select></td><?php endif; ?>
          <td><?php if (!$services): ?><input type="hidden" name="service_id[]" value=""><?php endif; ?><input type="text" name="description[]" aria-label="Description" maxlength="2000" value="<?= e((string) $li['description']) ?>"></td>
          <td><input type="number" class="li-qty" name="qty[]" aria-label="Quantity" min="1" step="1" value="<?= e((string) $li['qty']) ?>"></td>
          <td><input type="text" name="unit[]" aria-label="Unit" maxlength="50" value="<?= e((string) $li['unit']) ?>"></td>
          <td><input type="number" class="li-price" name="unit_price[]" aria-label="Unit price" min="0" step="0.01" value="<?= e((string) $li['unit_price']) ?>"></td>
          <td class="num li-amount">0.00</td>
          <td><button type="button" class="btn btn-ghost btn-sm li-remove" aria-label="Remove line">✕</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <button type="button" id="add-line-item" class="btn btn-outline btn-sm mt-2">+ Add Line Item</button>

    <div class="totals-box mt-2">
      <div class="flex justify-between"><span>Subtotal</span><strong id="preview-subtotal">0.00</strong></div>
      <div class="field" style="margin-top:10px">
        <label class="field-label" for="discount_value">Discount</label>
        <div class="flex gap-2">
          <select name="discount_type" aria-label="Discount type" style="max-width:150px">
            <option value="PERCENTAGE" <?= old($old, 'discount_type', 'PERCENTAGE') === 'PERCENTAGE' ? 'selected' : '' ?>>Percentage (%)</option>
            <option value="FIXED" <?= old($old, 'discount_type') === 'FIXED' ? 'selected' : '' ?>>Fixed Amount (₱)</option>
          </select>
          <input type="number" id="discount_value" name="discount_value" min="0" step="0.01" value="<?= e(old($old, 'discount_value', '0')) ?>">
        </div>
      </div>
      <div class="flex justify-between"><span>Discount Amount</span><strong id="preview-discount">0.00</strong></div>
      <div class="field" style="margin-top:10px">
        <label class="field-label" for="tax_pct">Tax / VAT (%)</label>
        <input type="number" id="tax_pct" name="tax_pct" min="0" max="100" step="0.01" value="<?= e(old($old, 'tax_pct', '0')) ?>">
      </div>
      <div class="flex justify-between"><span>Tax Amount</span><strong id="preview-tax">0.00</strong></div>
      <div class="flex justify-between grand-total"><span>Grand Total</span><strong id="preview-total">0.00</strong></div>
      <p class="help-text">Preview only — the server recalculates and stores the final totals when you save.</p>
    </div>
  </div></div>

  <div class="card"><div class="card-header">4. Notes / Terms</div><div class="card-body">
    <textarea name="notes" rows="3" maxlength="5000" aria-label="Notes" placeholder="Add notes, terms, or special instructions…"><?= e(old($old, 'notes')) ?></textarea>
  </div></div>

  <div class="flex gap-2">
    <a href="<?= e(url($editing ? '/quotations/' . $quotation['id'] : '/quotations')) ?>" class="btn btn-outline">Cancel</a>
    <button type="submit" class="btn btn-primary"><?= $editing ? 'Save Changes' : 'Create Quotation' ?></button>
  </div>
</form>
