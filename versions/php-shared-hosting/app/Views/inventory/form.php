<?php use App\Helpers\Csrf; use App\Helpers\FormToken; $editing = $item !== null; ?>
<h1 class="page-title"><?= $editing ? 'Edit ' . e($item['sku']) : 'New Inventory Item' ?></h1>
<?php require __DIR__ . '/../partials/errors.php'; ?>
<div class="card" style="max-width:640px"><div class="card-body">
<form method="post" action="<?= e(url($editing ? '/inventory/' . $item['id'] : '/inventory')) ?>" data-once>
  <?= Csrf::field() ?><?= $editing ? '' : FormToken::field() ?>
  <div class="grid grid-cols-2">
    <div class="field"><label class="field-label" for="sku">SKU *</label><input id="sku" type="text" name="sku" required maxlength="100" value="<?= e(old($old, 'sku')) ?>"></div>
    <div class="field"><label class="field-label" for="unit">Unit *</label><input id="unit" type="text" name="unit" required maxlength="50" placeholder="roll, sheet, liter…" value="<?= e(old($old, 'unit')) ?>"></div>
  </div>
  <div class="field"><label class="field-label" for="name">Name *</label><input id="name" type="text" name="name" required maxlength="255" value="<?= e(old($old, 'name')) ?>"></div>
  <div class="grid grid-cols-2">
    <div class="field"><label class="field-label" for="current_qty">Current Quantity *</label><input id="current_qty" type="number" name="current_qty" min="0" step="1" required value="<?= e(old($old, 'current_qty', '0')) ?>"></div>
    <div class="field"><label class="field-label" for="reorder_threshold">Reorder Threshold</label><input id="reorder_threshold" type="number" name="reorder_threshold" min="0" step="1" value="<?= e(old($old, 'reorder_threshold', '0')) ?>"></div>
  </div>
  <div class="flex gap-2">
    <a href="<?= e(url('/inventory')) ?>" class="btn btn-outline">Cancel</a>
    <button type="submit" class="btn btn-primary"><?= $editing ? 'Save Changes' : 'Create Item' ?></button>
  </div>
</form>
</div></div>
