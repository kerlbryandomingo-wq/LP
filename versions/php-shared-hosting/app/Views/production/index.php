<?php use App\Helpers\View; ?>
<h1 class="page-title">Production</h1>
<p class="page-subtitle">Active job orders across all stages.</p>
<div class="card"><div class="card-body table-wrap">
<table class="data-table">
  <thead><tr><th>JO #</th><th>Order</th><th>Customer</th><th>Status</th><th>Priority</th></tr></thead>
  <tbody>
  <?php foreach ($jobOrders as $jo): ?>
    <tr>
      <td><a href="/job-orders/<?= View::e($jo['id']) ?>"><?= View::e($jo['jo_number']) ?></a></td>
      <td><a href="/orders/<?= View::e($jo['order_id']) ?>"><?= View::e($jo['order_number']) ?></a></td>
      <td><?= View::e($jo['customer_name']) ?></td>
      <td><span class="badge badge-slate"><?= View::e($jo['status']) ?></span></td>
      <td><?= View::e($jo['priority']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$jobOrders): ?><tr><td colspan="5" class="text-muted">No active job orders.</td></tr><?php endif; ?>
  </tbody>
</table>
</div></div>
