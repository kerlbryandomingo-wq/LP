<?php use App\Controllers\InquiryController; use App\Helpers\View; use App\Services\AuthService; ?>
<div class="flex items-center justify-between page-head">
  <div><h1 class="page-title mb-0">Inquiries</h1><p class="page-subtitle">Customer inquiries awaiting quotation.</p></div>
  <?php if (AuthService::can('inquiries.manage')): ?>
  <a href="<?= e(url('/inquiries/new')) ?>" class="btn btn-primary">+ New Inquiry</a>
  <?php endif; ?>
</div>
<form method="get" action="<?= e(url('/inquiries')) ?>" class="filters">
  <input type="search" name="q" aria-label="Search inquiries" placeholder="Search customer or product…" value="<?= e($search) ?>">
  <select name="status" aria-label="Status">
    <option value="">All statuses</option>
    <?php foreach (InquiryController::STATUSES as $s): ?><option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-outline btn-sm">Filter</button>
</form>
<div class="card"><div class="card-body table-wrap">
<table class="data-table">
  <thead><tr><th>Customer</th><th>Desired Product</th><th>Status</th><th>Date</th></tr></thead>
  <tbody>
  <?php foreach ($inquiries as $i): ?>
    <tr>
      <td><a href="<?= e(url('/inquiries/' . $i['id'])) ?>"><?= View::e($i['customer_name']) ?></a></td>
      <td><?= View::e($i['desired_product']) ?></td>
      <td><?= status_badge($i['status']) ?></td>
      <td><?= View::e(date('M j, Y', strtotime($i['created_at']))) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$inquiries): ?><tr><td colspan="4" class="empty-state">No inquiries found.</td></tr><?php endif; ?>
  </tbody>
</table>
<?= $pager->links() ?>
</div></div>
