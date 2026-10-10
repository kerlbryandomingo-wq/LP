<?php use App\Helpers\Csrf; use App\Helpers\View; ?>
<h1 class="page-title">Payments</h1>
<p class="page-subtitle">All recorded payments across orders.</p>
<div class="card"><div class="card-body table-wrap">
<table class="data-table">
  <thead><tr><th>Order</th><th>Customer</th><th class="num">Amount</th><th>Method</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($payments as $p): ?>
    <tr>
      <td><a href="/orders/<?= View::e($p['order_id']) ?>"><?= View::e($p['order_number']) ?></a></td>
      <td><?= View::e($p['customer_name']) ?></td>
      <td class="num"><?= View::money($p['amount']) ?></td>
      <td><?= View::e($p['method']) ?></td>
      <td>
        <?php $tone = $p['status'] === 'CONFIRMED' ? 'green' : ($p['status'] === 'REJECTED' ? 'red' : 'yellow'); ?>
        <span class="badge badge-<?= $tone ?>"><?= View::e($p['status']) ?></span>
      </td>
      <td><?= View::e(date('M j, Y', strtotime($p['payment_date']))) ?></td>
      <td>
        <?php if ($p['status'] === 'PENDING'): ?>
          <form method="post" action="/payments/<?= View::e($p['id']) ?>/confirm" style="display:inline"><?= Csrf::field() ?><button class="btn btn-sm btn-success">Confirm</button></form>
          <form method="post" action="/payments/<?= View::e($p['id']) ?>/reject" style="display:inline"><?= Csrf::field() ?><button class="btn btn-sm btn-danger">Reject</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$payments): ?><tr><td colspan="7" class="text-muted">No payments yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div></div>
