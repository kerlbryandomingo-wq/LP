<?php use App\Helpers\Csrf; use App\Helpers\FormToken; use App\Helpers\View; ?>
<h1 class="page-title">New Inquiry</h1>
<?php require __DIR__ . '/../partials/errors.php'; ?>
<div class="card"><div class="card-body">
<form method="post" action="<?= e(url('/inquiries')) ?>" data-once>
  <?= Csrf::field() ?>
  <?= FormToken::field() ?>
  <div class="field">
    <label class="field-label" for="customer_id">Customer *</label>
    <select id="customer_id" name="customer_id" required>
      <option value="">Select a customer…</option>
      <?php foreach ($customers as $c): ?>
        <option value="<?= View::e($c['id']) ?>" <?= old($old, 'customer_id') === $c['id'] ? 'selected' : '' ?>><?= View::e($c['name']) ?> (<?= View::e($c['display_id']) ?>)</option>
      <?php endforeach; ?>
    </select>
    <?php if (!$customers): ?><p class="help-text">No customers yet — <a href="<?= e(url('/customers/new')) ?>">create one first</a>.</p><?php endif; ?>
  </div>
  <?php if ($services): ?>
  <div class="field">
    <label class="field-label" for="service_id">Service (optional)</label>
    <select id="service_id" name="service_id">
      <option value="">— Not specified —</option>
      <?php foreach ($services as $s): ?>
        <option value="<?= View::e($s['id']) ?>" <?= old($old, 'service_id') === $s['id'] ? 'selected' : '' ?>><?= View::e($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <div class="field"><label class="field-label" for="desired_product">Desired Product *</label><input type="text" id="desired_product" name="desired_product" required maxlength="255" value="<?= e(old($old, 'desired_product')) ?>"></div>
  <div class="grid grid-cols-2">
    <div class="field"><label class="field-label" for="rough_qty">Rough Quantity</label><input type="number" id="rough_qty" name="rough_qty" min="1" step="1" value="<?= e(old($old, 'rough_qty')) ?>"></div>
    <div class="field"><label class="field-label" for="rough_qty_unit">Unit</label><input type="text" id="rough_qty_unit" name="rough_qty_unit" maxlength="50" placeholder="pcs, sqm, etc." value="<?= e(old($old, 'rough_qty_unit')) ?>"></div>
  </div>
  <div class="field"><label class="field-label" for="description">Description *</label><textarea id="description" name="description" rows="3" required maxlength="5000"><?= e(old($old, 'description')) ?></textarea></div>
  <div class="flex gap-2">
    <a href="<?= e(url('/inquiries')) ?>" class="btn btn-outline">Cancel</a>
    <button type="submit" class="btn btn-primary">Save Inquiry</button>
  </div>
</form>
</div></div>
