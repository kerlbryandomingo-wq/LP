<?php use App\Helpers\View; ?>
<div class="flex items-center justify-between">
  <div><h1 class="page-title mb-0">Quotations</h1><p class="page-subtitle">Create and track customer quotations.</p></div>
  <a href="/quotations/new" class="btn btn-primary">+ New Quotation</a>
</div>
<div class="card"><div class="card-body table-wrap">
<table class="data-table">
  <thead><tr><th>Quote #</th><th>Customer</th><th>Status</th><th class="num">Total</th><th>Date</th></tr></thead>
  <tbody>
  <?php foreach ($quotations as $q): ?>
    <tr>
      <td><a href="/quotations/<?= View::e($q['id']) ?>"><?= View::e($q['quote_number']) ?></a></td>
      <td><?= View::e($q['customer_name']) ?></td>
      <td><span class="badge badge-blue"><?= View::e($q['status']) ?></span></td>
      <td class="num"><?= View::money($q['total']) ?></td>
      <td><?= View::e(date('M j, Y', strtotime($q['created_at']))) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$quotations): ?><tr><td colspan="5" class="text-muted">No quotations yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div></div>
