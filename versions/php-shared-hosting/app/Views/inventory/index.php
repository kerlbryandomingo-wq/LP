<?php use App\Helpers\View; use App\Services\AuthService; $canManage = AuthService::can('inventory.manage'); ?>
<div class="flex items-center justify-between page-head">
  <div><h1 class="page-title mb-0">Inventory</h1><p class="page-subtitle">Stock levels for materials and supplies.</p></div>
  <?php if ($canManage): ?><a href="<?= e(url('/inventory/new')) ?>" class="btn btn-primary">+ New Item</a><?php endif; ?>
</div>
<form method="get" action="<?= e(url('/inventory')) ?>" class="filters">
  <input type="search" name="q" aria-label="Search inventory" placeholder="Search SKU or name…" value="<?= e($search) ?>">
  <label class="checkbox"><input type="checkbox" name="low" value="1" <?= $lowOnly ? 'checked' : '' ?>> Low stock only</label>
  <button type="submit" class="btn btn-outline btn-sm">Filter</button>
</form>
<div class="card"><div class="card-body table-wrap">
<table class="data-table">
  <thead><tr><th>SKU</th><th>Name</th><th>Unit</th><th class="num">Current Qty</th><th class="num">Reorder At</th><th>Status</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
  <tbody>
  <?php foreach ($items as $i): $low = (int) $i['current_qty'] <= (int) $i['reorder_threshold']; ?>
    <tr>
      <td><?= View::e($i['sku']) ?></td>
      <td><?= View::e($i['name']) ?></td>
      <td><?= View::e($i['unit']) ?></td>
      <td class="num"><?= (int) $i['current_qty'] ?></td>
      <td class="num"><?= (int) $i['reorder_threshold'] ?></td>
      <td><?= $low ? '<span class="badge badge-yellow">Low</span>' : '<span class="badge badge-green">OK</span>' ?></td>
      <?php if ($canManage): ?><td><a class="btn btn-ghost btn-sm" href="<?= e(url('/inventory/' . $i['id'] . '/edit')) ?>">Edit</a></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  <?php if (!$items): ?><tr><td colspan="<?= $canManage ? 7 : 6 ?>" class="empty-state">No inventory items found.</td></tr><?php endif; ?>
  </tbody>
</table>
<?= $pager->links() ?>
</div></div>
