<?php use App\Controllers\OrderController; use App\Helpers\View; use App\Services\AuthService; ?>
<div class="flex items-center justify-between page-head">
  <div><h1 class="page-title mb-0">Orders</h1><p class="page-subtitle">Orders are created when a quotation is approved.</p></div>
  <?php if (AuthService::can('exports.run')): ?>
  <a class="btn btn-outline" href="<?= e(url('/orders/export?' . http_build_query(['q' => $search, 'status' => $status]))) ?>">Export CSV</a>
  <?php endif; ?>
</div>
<form method="get" action="<?= e(url('/orders')) ?>" class="filters">
  <input type="search" name="q" aria-label="Search orders" placeholder="Search order # or customer…" value="<?= e($search) ?>">
  <select name="status" aria-label="Status">
    <option value="">All statuses</option>
    <?php foreach (OrderController::STATUSES as $s): ?><option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $s)) ?></option><?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-outline btn-sm">Filter</button>
</form>
<div class="card"><div class="card-body table-wrap">
<table class="data-table">
  <thead><tr><th>Order #</th><th>Customer</th><th>Status</th><th class="num">Total</th><th class="num">Balance</th><th>Date</th></tr></thead>
  <tbody>
  <?php foreach ($orders as $o): $bal = max(0, round((float) $o['total_amount'] - (float) $o['paid'], 2)); ?>
    <tr>
      <td><a href="<?= e(url('/orders/' . $o['id'])) ?>"><?= View::e($o['order_number']) ?></a></td>
      <td><?= View::e($o['customer_name']) ?></td>
      <td><?= status_badge($o['status']) ?></td>
      <td class="num"><?= View::money($o['total_amount']) ?></td>
      <td class="num"><?= $o['status'] === 'CANCELLED' ? '—' : ($bal > 0 ? View::money($bal) : '<span class="badge badge-green">Paid</span>') ?></td>
      <td><?= View::e(date('M j, Y', strtotime($o['order_date']))) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$orders): ?><tr><td colspan="6" class="empty-state">No orders found. Orders appear here when a quotation is approved.</td></tr><?php endif; ?>
  </tbody>
</table>
<?= $pager->links() ?>
</div></div>
