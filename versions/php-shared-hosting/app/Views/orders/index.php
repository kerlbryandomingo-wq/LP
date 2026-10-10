<?php use App\Helpers\View; ?>
<h1 class="page-title">Orders</h1>
<p class="page-subtitle">Track all customer orders.</p>
<div class="card"><div class="card-body table-wrap">
<table class="data-table">
  <thead><tr><th>Order #</th><th>Customer</th><th>Status</th><th class="num">Total</th><th>Date</th></tr></thead>
  <tbody>
  <?php foreach ($orders as $o): ?>
    <tr>
      <td><a href="/orders/<?= View::e($o['id']) ?>"><?= View::e($o['order_number']) ?></a></td>
      <td><?= View::e($o['customer_name']) ?></td>
      <td><span class="badge badge-blue"><?= View::e($o['status']) ?></span></td>
      <td class="num"><?= View::money($o['total_amount']) ?></td>
      <td><?= View::e(date('M j, Y', strtotime($o['order_date']))) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$orders): ?><tr><td colspan="5" class="text-muted">No orders yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div></div>
