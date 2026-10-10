<?php use App\Helpers\View; ?>
<div class="flex items-center justify-between">
  <div><h1 class="page-title mb-0">Inquiries</h1><p class="page-subtitle">Customer inquiries awaiting quotation.</p></div>
  <a href="/inquiries/new" class="btn btn-primary">+ New Inquiry</a>
</div>
<div class="card"><div class="card-body table-wrap">
<table class="data-table">
  <thead><tr><th>Customer</th><th>Desired Product</th><th>Status</th><th>Date</th></tr></thead>
  <tbody>
  <?php foreach ($inquiries as $i): ?>
    <tr>
      <td><a href="/inquiries/<?= View::e($i['id']) ?>"><?= View::e($i['customer_name']) ?></a></td>
      <td><?= View::e($i['desired_product']) ?></td>
      <td><span class="badge badge-slate"><?= View::e($i['status']) ?></span></td>
      <td><?= View::e(date('M j, Y', strtotime($i['created_at']))) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$inquiries): ?><tr><td colspan="4" class="text-muted">No inquiries yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div></div>
