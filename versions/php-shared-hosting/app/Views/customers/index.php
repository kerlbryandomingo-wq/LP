<?php use App\Helpers\View; ?>
<div class="flex items-center justify-between">
  <div>
    <h1 class="page-title mb-0">Customers</h1>
    <p class="page-subtitle">Manage customer records.</p>
  </div>
  <a href="/customers/new" class="btn btn-primary">+ New Customer</a>
</div>

<form method="get" class="field" style="max-width:320px">
  <input type="text" name="q" placeholder="Search name, ID, email, phone..." value="<?= View::e($search) ?>">
</form>

<div class="card">
  <div class="card-body table-wrap">
    <table class="data-table">
      <thead><tr><th>ID</th><th>Name</th><th>Company</th><th>Contact</th><th>Email</th></tr></thead>
      <tbody>
        <?php foreach ($customers as $c): ?>
        <tr>
          <td><a href="/customers/<?= View::e($c['id']) ?>"><?= View::e($c['display_id']) ?></a></td>
          <td><?= View::e($c['name']) ?></td>
          <td><?= View::e($c['company_name'] ?? '—') ?></td>
          <td><?= View::e($c['contact_number'] ?? '—') ?></td>
          <td><?= View::e($c['email'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$customers): ?>
        <tr><td colspan="5" class="text-muted">No customers found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
