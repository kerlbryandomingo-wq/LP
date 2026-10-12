<?php use App\Controllers\JobOrderController; use App\Helpers\View; ?>
<h1 class="page-title">Production</h1>
<p class="page-subtitle">Job orders, highest priority first.</p>
<form method="get" action="<?= e(url('/job-orders')) ?>" class="filters">
  <input type="search" name="q" aria-label="Search job orders" placeholder="Search JO #, order #, customer…" value="<?= e($search) ?>">
  <select name="status" aria-label="Status">
    <option value="">Active (not yet released)</option>
    <option value="ALL" <?= $status === 'ALL' ? 'selected' : '' ?>>All</option>
    <?php foreach (JobOrderController::STATUSES as $s): ?><option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $s)) ?></option><?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-outline btn-sm">Filter</button>
</form>
<div class="card"><div class="card-body table-wrap">
<table class="data-table">
  <thead><tr><th>JO #</th><th>Order</th><th>Customer</th><th>Item</th><th>Stage</th><th>Status</th><th>Priority</th></tr></thead>
  <tbody>
  <?php foreach ($jobOrders as $jo): ?>
    <tr>
      <td><a href="<?= e(url('/job-orders/' . $jo['id'])) ?>"><?= View::e($jo['jo_number']) ?></a></td>
      <td><?= View::e($jo['order_number']) ?></td>
      <td><?= View::e($jo['customer_name']) ?></td>
      <td><?= View::e(mb_strimwidth($jo['description'], 0, 50, '…')) ?> ×<?= (int) $jo['quantity'] ?></td>
      <td><?= View::e($jo['stage_name'] ?? '—') ?></td>
      <td><?= status_badge($jo['status']) ?></td>
      <td><?= View::e($jo['priority']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$jobOrders): ?><tr><td colspan="7" class="empty-state">No job orders found.</td></tr><?php endif; ?>
  </tbody>
</table>
<?= $pager->links() ?>
</div></div>
