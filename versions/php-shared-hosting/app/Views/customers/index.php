<?php use App\Helpers\View; use App\Services\AuthService; ?>
<div class="flex items-center justify-between page-head">
  <div>
    <h1 class="page-title mb-0">Customers</h1>
    <p class="page-subtitle">Manage customer records.</p>
  </div>
  <?php if (AuthService::can('customers.manage')): ?>
  <a href="<?= e(url('/customers/new')) ?>" class="btn btn-primary">+ New Customer</a>
  <?php endif; ?>
</div>

<form method="get" action="<?= e(url('/customers')) ?>" class="filters">
  <input type="search" name="q" aria-label="Search customers" placeholder="Search name, ID, email, phone…" value="<?= e($search) ?>">
  <button type="submit" class="btn btn-outline btn-sm">Search</button>
  <?php if ($search !== ''): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('/customers')) ?>">Clear</a><?php endif; ?>
</form>

<div class="card">
  <div class="card-body table-wrap">
    <table class="data-table">
      <thead><tr><th>ID</th><th>Name</th><th>Company</th><th>Contact</th><th>Email</th></tr></thead>
      <tbody>
        <?php foreach ($customers as $c): ?>
        <tr>
          <td><a href="<?= e(url('/customers/' . $c['id'])) ?>"><?= View::e($c['display_id']) ?></a></td>
          <td><?= View::e($c['name']) ?></td>
          <td><?= View::e($c['company_name'] ?? '—') ?></td>
          <td><?= View::e($c['contact_number'] ?? '—') ?></td>
          <td><?= View::e($c['email'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$customers): ?>
        <tr><td colspan="5" class="empty-state"><?= $search !== '' ? 'No customers match your search.' : 'No customers yet.' ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    <?= $pager->links() ?>
  </div>
</div>
