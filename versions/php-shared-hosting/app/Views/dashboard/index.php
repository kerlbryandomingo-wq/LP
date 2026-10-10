<?php use App\Helpers\View; ?>
<h1 class="page-title">Dashboard</h1>
<p class="page-subtitle">Here's what's happening with your business today.</p>

<div class="grid grid-cols-5">
  <div class="card kpi"><div class="card-body">
    <div class="kpi-label">New Inquiries</div>
    <div class="kpi-value"><?= (int) $kpis['inquiries'] ?></div>
  </div></div>
  <div class="card kpi"><div class="card-body">
    <div class="kpi-label">Quotations Awaiting Approval</div>
    <div class="kpi-value"><?= (int) $kpis['quotations_pending'] ?></div>
  </div></div>
  <div class="card kpi"><div class="card-body">
    <div class="kpi-label">Open Orders</div>
    <div class="kpi-value"><?= (int) $kpis['open_orders'] ?></div>
  </div></div>
  <div class="card kpi"><div class="card-body">
    <div class="kpi-label">Pending Payments</div>
    <div class="kpi-value"><?= (int) $kpis['pending_payments'] ?></div>
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
          <td><?= View::e($o['order_number']) ?></td>
          <td><?= View::e($o['customer_name']) ?></td>
          <td><span class="badge badge-blue"><?= View::e($o['status']) ?></span></td>
          <td class="num"><?= View::money($o['total_amount']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$recentOrders): ?>
        <tr><td colspan="4" class="text-muted">No orders yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
