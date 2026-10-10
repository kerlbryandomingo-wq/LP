<?php use App\Helpers\Csrf; use App\Helpers\View; ?>
<h1 class="page-title">New Inquiry</h1>
<div class="card"><div class="card-body">
<form method="post" action="/inquiries">
  <?= Csrf::field() ?>
  <div class="field">
    <label class="field-label">Customer *</label>
    <select name="customer_id" required>
      <option value="">Select a customer...</option>
      <?php foreach ($customers as $c): ?>
        <option value="<?= View::e($c['id']) ?>"><?= View::e($c['name']) ?> (<?= View::e($c['display_id']) ?>)</option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label class="field-label">Service (optional)</label>
    <select name="service_id">
      <option value="">— Not specified —</option>
      <?php foreach ($services as $s): ?>
        <option value="<?= View::e($s['id']) ?>"><?= View::e($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field"><label class="field-label">Desired Product *</label><input type="text" name="desired_product" required></div>
  <div class="grid grid-cols-2">
    <div class="field"><label class="field-label">Rough Quantity</label><input type="number" name="rough_qty" min="0"></div>
    <div class="field"><label class="field-label">Unit</label><input type="text" name="rough_qty_unit" placeholder="pcs, sqm, etc."></div>
  </div>
  <div class="field"><label class="field-label">Description *</label><textarea name="description" rows="3" required></textarea></div>
  <div class="flex gap-2">
    <a href="/inquiries" class="btn btn-outline">Cancel</a>
    <button type="submit" class="btn btn-primary">Save Inquiry</button>
  </div>
</form>
</div></div>
