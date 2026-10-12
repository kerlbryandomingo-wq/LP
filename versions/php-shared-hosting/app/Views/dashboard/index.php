<?php use App\Helpers\View; ?>
<h1 class="page-title">Dashboard</h1>
<p class="page-subtitle">Here's what's happening with your business today.</p>

<div class="grid grid-cols-3">
  <a class="card kpi" href="<?= e(url('/inquiries?status=NEW')) ?>"><div class="card-body">
    <div class="kpi-label">New Inquiries</div>
    <div class="kpi-value" data-kpi="new_inquiries"><?= (int) $kpis['new_inquiries'] ?></div>
  </div></a>
  <a class="card kpi" href="<?= e(url('/quotations?status=SENT')) ?>"><div class="card-body">
    <div class="kpi-label">Quotations Awaiting Approval</div>
    <div class="kpi-value" data-kpi="quotations_sent"><?= (int) $kpis['quotations_sent'] ?></div>
  </div></a>
  <a class="card kpi" href="<?= e(url('/orders')) ?>"><div class="card-body">
    <div class="kpi-label">Open Orders</div>
    <div class="kpi-value" data-kpi="open_orders"><?= (int) $kpis['open_orders'] ?></div>
  </div></a>
  <a class="card kpi" href="<?= e(url('/payments?status=PENDING')) ?>"><div class="card-body">
    <div class="kpi-label">Payments Awaiting Confirmation</div>
    <div class="kpi-value" data-kpi="pending_payments"><?= (int) $kpis['pending_payments'] ?></div>
  </div></a>
  <div class="card kpi"><div class="card-body">
    <div class="kpi-label">Confirmed Payments This Month</div>
    <div class="kpi-value" data-kpi="confirmed_this_month"><?= View::money($kpis['confirmed_this_month']) ?></div>
  </div></div>
  <div class="card kpi"><div class="card-body">
    <div class="kpi-label">Outstanding Balance</div>
    <div class="kpi-value" data-kpi="outstanding"><?= View::money($kpis['outstanding']) ?></div>
    <div class="kpi-sub">All open and completed orders, excluding cancelled</div>
  </div></div>
</div>

<div class="card">
  <div class="card-header">Recent Orders</div>
  <div class="card-body table-wrap">
    <table class="data-table">
      <thead><tr><th>Order #</th><th>Customer</th><th>Status</th><th class="num">Total</th></tr></thead>
      <tbody>
        <?php foreach ($recentOrders as $o): ?>
        <tr>
          <td><a href="<?= e(url('/orders/' . $o['id'])) ?>"><?= View::e($o['order_number']) ?></a></td>
          <td><?= View::e($o['customer_name']) ?></td>
          <td><?= status_badge($o['status']) ?></td>
          <td class="num"><?= View::money($o['total_amount']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$recentOrders): ?>
        <tr><td colspan="4" class="empty-state">No orders yet. Orders are created by converting an approved quotation.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
