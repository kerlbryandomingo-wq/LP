<?php use App\Helpers\View; ?>
<h1 class="page-title"><?= View::e($customer['name']) ?></h1>
<p class="page-subtitle"><?= View::e($customer['display_id']) ?></p>

<div class="grid grid-cols-2">
  <div class="card"><div class="card-header">Contact Information</div><div class="card-body">
    <p><span class="text-muted">Company:</span> <?= View::e($customer['company_name'] ?? '—') ?></p>
    <p><span class="text-muted">Email:</span> <?= View::e($customer['email'] ?? '—') ?></p>
    <p><span class="text-muted">Phone:</span> <?= View::e($customer['contact_number'] ?? '—') ?></p>
    <p><span class="text-muted">Address:</span> <?= View::e($customer['address'] ?? '—') ?></p>
  </div></div>
  <div class="card"><div class="card-header">Loyalty</div><div class="card-body">
    <p><span class="text-muted">Reward points balance:</span> <?= (int) $customer['reward_points_balance'] ?></p>
    <p><span class="text-muted">Qualified for payment terms:</span> <?= $customer['is_qualified_for_terms'] ? 'Yes' : 'No' ?></p>
  </div></div>
</div>

<div class="card">
  <div class="card-header">Orders</div>
  <div class="card-body table-wrap">
    <table class="data-table">
      <thead><tr><th>Order #</th><th>Status</th><th class="num">Total</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><a href="/orders/<?= View::e($o['id']) ?>"><?= View::e($o['order_number']) ?></a></td>
          <td><span class="badge badge-blue"><?= View::e($o['status']) ?></span></td>
          <td class="num"><?= View::money($o['total_amount']) ?></td>
          <td><?= View::e(date('M j, Y', strtotime($o['order_date']))) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?>
        <tr><td colspan="4" class="text-muted">No orders yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
