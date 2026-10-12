<?php use App\Controllers\PaymentController; use App\Helpers\View; use App\Services\AuthService; ?>
<div class="flex items-center justify-between page-head">
  <div><h1 class="page-title mb-0">Payments</h1><p class="page-subtitle">All recorded payments. Confirm or reject them from the order page.</p></div>
  <?php if (AuthService::can('exports.run')): ?>
  <a class="btn btn-outline" href="<?= e(url('/payments/export?' . http_build_query(['q' => $search, 'status' => $status]))) ?>">Export CSV</a>
  <?php endif; ?>
</div>
<form method="get" action="<?= e(url('/payments')) ?>" class="filters">
  <input type="search" name="q" aria-label="Search payments" placeholder="Search order #, customer, reference…" value="<?= e($search) ?>">
  <select name="status" aria-label="Status">
    <option value="">All statuses</option>
    <?php foreach (PaymentController::STATUSES as $s): ?><option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-outline btn-sm">Filter</button>
</form>
<div class="card"><div class="card-body table-wrap">
<table class="data-table">
  <thead><tr><th>Date</th><th>Order</th><th>Customer</th><th class="num">Amount</th><th>Method</th><th>Reference</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($payments as $p): ?>
    <tr>
      <td><?= View::e(date('M j, Y', strtotime($p['payment_date']))) ?></td>
      <td><a href="<?= e(url('/orders/' . $p['order_id'])) ?>"><?= View::e($p['order_number']) ?></a></td>
      <td><?= View::e($p['customer_name']) ?></td>
      <td class="num"><?= View::money($p['amount']) ?></td>
      <td><?= View::e(PaymentController::METHODS[$p['method']] ?? $p['method']) ?></td>
      <td><?= View::e($p['reference_number'] ?? '—') ?></td>
      <td><?= status_badge($p['status']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$payments): ?><tr><td colspan="7" class="empty-state">No payments found.</td></tr><?php endif; ?>
  </tbody>
</table>
<?= $pager->links() ?>
</div></div>
