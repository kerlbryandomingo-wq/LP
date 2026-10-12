<?php use App\Controllers\QuotationController; use App\Helpers\View; use App\Services\AuthService; ?>
<div class="flex items-center justify-between page-head">
  <div><h1 class="page-title mb-0">Quotations</h1><p class="page-subtitle">Create and track customer quotations.</p></div>
  <?php if (AuthService::can('quotations.manage')): ?>
  <a href="<?= e(url('/quotations/new')) ?>" class="btn btn-primary">+ New Quotation</a>
  <?php endif; ?>
</div>
<form method="get" action="<?= e(url('/quotations')) ?>" class="filters">
  <input type="search" name="q" aria-label="Search quotations" placeholder="Search quote # or customer…" value="<?= e($search) ?>">
  <select name="status" aria-label="Status">
    <option value="">All statuses</option>
    <?php foreach (QuotationController::STATUSES as $s): ?><option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $s)) ?></option><?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-outline btn-sm">Filter</button>
</form>
<div class="card"><div class="card-body table-wrap">
<table class="data-table">
  <thead><tr><th>Quote #</th><th>Customer</th><th>Status</th><th class="num">Total</th><th>Date</th></tr></thead>
  <tbody>
  <?php foreach ($quotations as $q): ?>
    <tr>
      <td><a href="<?= e(url('/quotations/' . $q['id'])) ?>"><?= View::e($q['quote_number']) ?></a></td>
      <td><?= View::e($q['customer_name']) ?></td>
      <td><?= status_badge($q['status']) ?></td>
      <td class="num"><?= View::money($q['total']) ?></td>
      <td><?= View::e(date('M j, Y', strtotime($q['created_at']))) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$quotations): ?><tr><td colspan="5" class="empty-state">No quotations found.</td></tr><?php endif; ?>
  </tbody>
</table>
<?= $pager->links() ?>
</div></div>
