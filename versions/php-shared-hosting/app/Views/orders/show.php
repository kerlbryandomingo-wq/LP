<?php use App\Helpers\Csrf; use App\Helpers\View; ?>
<div class="flex items-center justify-between">
  <div>
    <h1 class="page-title mb-0"><?= View::e($order['order_number']) ?></h1>
    <p class="page-subtitle"><?= View::e($order['customer_name']) ?> · <span class="badge badge-blue"><?= View::e($order['status']) ?></span></p>
  </div>
  <?php if (!in_array($order['status'], ['COMPLETED', 'CANCELLED'], true)): ?>
  <form method="post" action="/orders/<?= View::e($order['id']) ?>/cancel" onsubmit="return confirm('Cancel this order?')">
    <?= Csrf::field() ?>
    <button class="btn btn-outline">Cancel Order</button>
  </form>
  <?php endif; ?>
</div>

<div class="grid grid-cols-2">
  <div class="card"><div class="card-header">Customer</div><div class="card-body">
    <p><?= View::e($order['customer_name']) ?></p>
    <p class="text-muted"><?= View::e($order['contact_number'] ?? '') ?></p>
    <p class="text-muted"><?= View::e($order['email'] ?? '') ?></p>
  </div></div>
  <div class="card"><div class="card-header">Payment Status</div><div class="card-body">
    <div class="flex justify-between"><span>Total</span><strong><?= View::money($summary['total']) ?></strong></div>
    <div class="flex justify-between"><span>Confirmed</span><strong><?= View::money($summary['confirmed']) ?></strong></div>
    <?php $balance = $summary['total'] - $summary['confirmed']; ?>
    <?php if ($balance > 0): ?>
      <div class="flex justify-between" style="background:var(--color-error-100);color:var(--color-error-800);border-radius:6px;padding:6px 8px;margin-top:6px">
        <strong>Balance</strong><strong><?= View::money($balance) ?></strong>
      </div>
    <?php else: ?>
      <p class="badge badge-green mt-2">Fully Paid</p>
    <?php endif; ?>

    <?php if ($balance > 0 && !in_array($order['status'], ['CANCELLED'], true)): ?>
    <button type="button" class="btn btn-primary mt-2" data-modal-open="record-payment-modal">Record Payment</button>
    <?php endif; ?>
  </div></div>
</div>

<div class="card"><div class="card-body table-wrap">
  <table class="data-table">
    <thead><tr><th>Description</th><th class="num">Qty</th><th>Unit</th><th class="num">Unit Price</th><th class="num">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($lineItems as $li): ?>
      <tr>
        <td><?= View::e($li['description']) ?></td>
        <td class="num"><?= (int) $li['qty'] ?></td>
        <td><?= View::e($li['unit'] ?? '—') ?></td>
        <td class="num"><?= View::money($li['unit_price']) ?></td>
        <td class="num"><?= View::money($li['qty'] * $li['unit_price']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>

<div class="card"><div class="card-header">Payment History</div><div class="card-body table-wrap">
  <table class="data-table">
    <thead><tr><th>Date</th><th class="num">Amount</th><th>Method</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
      <tr>
        <td><?= View::e(date('M j, Y', strtotime($p['payment_date']))) ?></td>
        <td class="num"><?= View::money($p['amount']) ?></td>
        <td><?= View::e($p['method']) ?></td>
        <td>
          <?php $tone = $p['status'] === 'CONFIRMED' ? 'green' : ($p['status'] === 'REJECTED' ? 'red' : 'yellow'); ?>
          <span class="badge badge-<?= $tone ?>"><?= View::e($p['status']) ?></span>
        </td>
        <td>
          <?php if ($p['status'] === 'PENDING'): ?>
            <form method="post" action="/payments/<?= View::e($p['id']) ?>/confirm" style="display:inline"><?= Csrf::field() ?><button class="btn btn-sm btn-success">Confirm</button></form>
            <form method="post" action="/payments/<?= View::e($p['id']) ?>/reject" style="display:inline"><?= Csrf::field() ?><button class="btn btn-sm btn-danger">Reject</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$payments): ?><tr><td colspan="5" class="text-muted">No payments recorded.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>

<?php if ($jobOrders): ?>
<div class="card"><div class="card-header">Job Orders</div><div class="card-body table-wrap">
  <table class="data-table">
    <thead><tr><th>JO #</th><th>Status</th><th>Quantity</th></tr></thead>
    <tbody>
    <?php foreach ($jobOrders as $jo): ?>
      <tr><td><a href="/job-orders/<?= View::e($jo['id']) ?>"><?= View::e($jo['jo_number']) ?></a></td><td><span class="badge badge-slate"><?= View::e($jo['status']) ?></span></td><td><?= (int) $jo['quantity'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<?php elseif (!in_array($order['status'], ['CANCELLED'], true)): ?>
<form method="post" action="/orders/<?= View::e($order['id']) ?>/job-orders">
  <?= Csrf::field() ?>
  <button type="submit" class="btn btn-outline">+ Add Job Order</button>
</form>
<?php endif; ?>

<div id="record-payment-modal" class="modal-overlay" hidden>
  <div class="modal-box">
    <div class="modal-header">Record Payment <button type="button" data-modal-close>&times;</button></div>
    <form method="post" action="/orders/<?= View::e($order['id']) ?>/payments" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <div class="modal-body">
        <div class="field"><label class="field-label">Amount (PHP) *</label><input type="number" name="amount" min="0.01" step="0.01" required></div>
        <div class="field"><label class="field-label">Payment Method</label>
          <select name="method">
            <option value="CASH">Cash</option><option value="BANK_TRANSFER">Bank Transfer</option>
            <option value="GCASH">GCash</option><option value="MAYA">Maya</option>
            <option value="CHEQUE">Cheque</option><option value="OTHER">Other</option>
          </select>
        </div>
        <div class="field"><label class="field-label">Reference Number</label><input type="text" name="reference_number"></div>
        <div class="field"><label class="field-label">Proof of Payment (optional)</label><input type="file" name="proof" accept="image/*,application/pdf"></div>
        <div class="field"><label class="field-label">Notes</label><textarea name="notes" rows="2"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Record Payment</button>
      </div>
    </form>
  </div>
</div>
