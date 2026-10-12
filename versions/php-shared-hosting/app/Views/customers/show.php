<?php use App\Helpers\View; use App\Services\AuthService; ?>
<div class="flex items-center justify-between page-head">
  <div>
    <h1 class="page-title mb-0"><?= View::e($customer['name']) ?></h1>
    <p class="page-subtitle"><?= View::e($customer['display_id']) ?></p>
  </div>
  <div class="flex gap-2">
    <?php if (AuthService::can('customers.manage')): ?>
      <a href="<?= e(url('/customers/' . $customer['id'] . '/edit')) ?>" class="btn btn-outline">Edit</a>
    <?php endif; ?>
    <?php if (AuthService::can('inquiries.manage')): ?>
      <a href="<?= e(url('/inquiries/new?customer_id=' . urlencode($customer['id']))) ?>" class="btn btn-outline">+ Inquiry</a>
    <?php endif; ?>
    <?php if (AuthService::can('quotations.manage')): ?>
      <a href="<?= e(url('/quotations/new?customer_id=' . urlencode($customer['id']))) ?>" class="btn btn-primary">+ Quotation</a>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid-cols-2">
  <div class="card"><div class="card-header">Contact Information</div><div class="card-body">
    <p><span class="text-muted">Company:</span> <?= View::e($customer['company_name'] ?? '—') ?></p>
    <p><span class="text-muted">Email:</span> <?= View::e($customer['email'] ?? '—') ?></p>
    <p><span class="text-muted">Phone:</span> <?= View::e($customer['contact_number'] ?? '—') ?></p>
    <p><span class="text-muted">Address:</span> <?= View::e($customer['address'] ?? '—') ?></p>
    <?php if ($customer['facebook_url'] && preg_match('#^https?://#i', $customer['facebook_url'])): ?>
      <p><span class="text-muted">Facebook:</span> <a href="<?= e($customer['facebook_url']) ?>" rel="noopener noreferrer" target="_blank"><?= View::e($customer['facebook_url']) ?></a></p>
    <?php endif; ?>
  </div></div>
  <div class="card"><div class="card-header">Account</div><div class="card-body">
    <p><span class="text-muted">Customer since:</span> <?= View::e(date('M j, Y', strtotime($customer['created_at']))) ?></p>
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
          <td><a href="<?= e(url('/orders/' . $o['id'])) ?>"><?= View::e($o['order_number']) ?></a></td>
          <td><?= status_badge($o['status']) ?></td>
          <td class="num"><?= View::money($o['total_amount']) ?></td>
          <td><?= View::e(date('M j, Y', strtotime($o['order_date']))) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="4" class="empty-state">No orders yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid grid-cols-2">
  <div class="card"><div class="card-header">Quotations</div><div class="card-body table-wrap">
    <table class="data-table">
      <thead><tr><th>Quote #</th><th>Status</th><th class="num">Total</th></tr></thead>
      <tbody>
      <?php foreach ($quotations as $q): ?>
        <tr><td><a href="<?= e(url('/quotations/' . $q['id'])) ?>"><?= View::e($q['quote_number']) ?></a></td><td><?= status_badge($q['status']) ?></td><td class="num"><?= View::money($q['total']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$quotations): ?><tr><td colspan="3" class="empty-state">No quotations yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div></div>
  <div class="card"><div class="card-header">Inquiries</div><div class="card-body table-wrap">
    <table class="data-table">
      <thead><tr><th>Product</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach ($inquiries as $i): ?>
        <tr><td><a href="<?= e(url('/inquiries/' . $i['id'])) ?>"><?= View::e($i['desired_product']) ?></a></td><td><?= status_badge($i['status']) ?></td><td><?= View::e(date('M j, Y', strtotime($i['created_at']))) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$inquiries): ?><tr><td colspan="3" class="empty-state">No inquiries yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div></div>
</div>
