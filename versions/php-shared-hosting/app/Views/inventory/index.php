<?php use App\Helpers\View; ?>
<h1 class="page-title">Inventory</h1>
<p class="page-subtitle">Stock levels for materials and supplies.</p>
<div class="card"><div class="card-body table-wrap">
<table class="data-table">
  <thead><tr><th>SKU</th><th>Name</th><th>Unit</th><th class="num">Current Qty</th><th class="num">Reorder Threshold</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($items as $i): ?>
    <?php $low = $i['current_qty'] <= $i['reorder_threshold']; ?>
    <tr>
      <td><?= View::e($i['sku']) ?></td>
      <td><?= View::e($i['name']) ?></td>
      <td><?= View::e($i['unit']) ?></td>
      <td class="num"><?= (int) $i['current_qty'] ?></td>
      <td class="num"><?= (int) $i['reorder_threshold'] ?></td>
      <td><?php if ($low): ?><span class="badge badge-yellow">Low</span><?php else: ?><span class="badge badge-green">OK</span><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$items): ?><tr><td colspan="6" class="text-muted">No inventory items yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div></div>
