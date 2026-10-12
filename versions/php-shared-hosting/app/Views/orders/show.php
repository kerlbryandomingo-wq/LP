<?php
use App\Controllers\PaymentController;
use App\Helpers\Csrf;
use App\Helpers\FormToken;
use App\Helpers\View;
use App\Services\AuthService;
$st = $order['status'];
$live = !in_array($st, ['COMPLETED', 'CANCELLED'], true);
$base = '/orders/' . $order['id'];
$hasException = (bool) $order['release_exception'] && !empty($order['release_exception_by']);
$linesWithoutJo = array_filter($lineItems, fn ($li) => empty($li['jo_id']));
?>
<div class="flex items-center justify-between page-head">
  <div>
    <h1 class="page-title mb-0"><?= View::e($order['order_number']) ?></h1>
    <p class="page-subtitle"><a href="<?= e(url('/customers/' . $order['customer_id'])) ?>"><?= View::e($order['customer_name']) ?></a> · <?= status_badge($st) ?>
      <?php if ($order['quote_number']): ?> · from <a href="<?= e(url('/quotations/' . $order['quotation_id'])) ?>"><?= View::e($order['quote_number']) ?></a><?php endif; ?></p>
  </div>
  <div class="flex gap-2 actions">
    <?php if ($st === 'FULFILLING' && AuthService::can('orders.manage')): ?>
      <form method="post" action="<?= e(url($base . '/complete')) ?>" data-once><?= Csrf::field() ?><button class="btn btn-success">Mark Order Completed</button></form>
    <?php endif; ?>
    <?php if ($live && AuthService::can('orders.manage')): ?>
      <button type="button" class="btn btn-outline" data-modal-open="cancel-order-modal">Cancel Order</button>
    <?php endif; ?>
  </div>
</div>

<?php if ($st === 'CANCELLED'): ?>
  <div class="alert alert-warning">Cancelled<?= $order['cancelled_by_name'] ? ' by ' . View::e($order['cancelled_by_name']) : '' ?>: <?= View::e($order['cancel_reason'] ?? '') ?></div>
<?php endif; ?>

<div class="grid grid-cols-2">
  <div class="card"><div class="card-header">Customer</div><div class="card-body">
    <p><?= View::e($order['customer_name']) ?> <span class="text-muted">(<?= View::e($order['customer_display_id']) ?>)</span></p>
    <p class="text-muted"><?= View::e($order['contact_number'] ?? '') ?></p>
    <p class="text-muted"><?= View::e($order['email'] ?? '') ?></p>
    <p><span class="text-muted">Payment terms:</span>
      <?= $order['payment_term_type'] === 'APPROVED_TERMS' ? 'Approved terms' . ($order['terms_approved_by'] ? ' — ' . View::e($order['terms_approved_by']) : '') : 'Standard — ' . (int) $order['required_partial_pct'] . '% partial before production' ?></p>
    <?php if ($order['due_date']): ?><p><span class="text-muted">Due date:</span> <?= View::e(date('M j, Y', strtotime($order['due_date']))) ?></p><?php endif; ?>
    <?php if ($hasException): ?><p><span class="badge badge-yellow">Release exception</span> <?= View::e($order['release_exception_by']) ?>: <?= View::e($order['release_exception_reason'] ?? '') ?></p><?php endif; ?>
  </div></div>
  <div class="card"><div class="card-header">Payment Status</div><div class="card-body" data-summary>
    <div class="flex justify-between"><span>Total</span><strong data-total="total"><?= View::money($summary['total']) ?></strong></div>
    <div class="flex justify-between"><span>Confirmed</span><strong data-total="confirmed"><?= View::money($summary['confirmed']) ?></strong></div>
    <?php if ($order['payment_term_type'] !== 'APPROVED_TERMS' && $summary['total'] > 0): ?>
      <div class="flex justify-between"><span>Required to start production</span><span><?= View::money($summary['requiredPartial']) ?> <?= $summary['partialMet'] ? '✓' : '' ?></span></div>
    <?php endif; ?>
    <?php if ($summary['balance'] > 0): ?>
      <div class="flex justify-between balance-due"><strong>Balance</strong><strong data-total="balance"><?= View::money($summary['balance']) ?></strong></div>
    <?php else: ?>
      <p class="mt-2"><span class="badge badge-green" data-total="balance">Fully Paid</span></p>
    <?php endif; ?>
    <?php if ($summary['balance'] > 0 && $st !== 'CANCELLED' && AuthService::can('payments.record')): ?>
      <button type="button" class="btn btn-primary mt-2" data-modal-open="record-payment-modal">Record Payment</button>
    <?php endif; ?>
    <div class="flex gap-2 mt-2">
    <?php if ($live && AuthService::can('orders.approve_terms')): ?>
      <button type="button" class="btn btn-ghost btn-sm" data-modal-open="terms-modal">Change Payment Terms</button>
    <?php endif; ?>
    <?php if ($live && !$hasException && !$summary['fullyPaid'] && AuthService::can('orders.release_exception')): ?>
      <button type="button" class="btn btn-ghost btn-sm" data-modal-open="release-exception-modal">Allow Release Before Full Payment</button>
    <?php endif; ?>
    </div>
  </div></div>
</div>

<div class="card"><div class="card-header">Items</div><div class="card-body table-wrap">
  <table class="data-table">
    <thead><tr><th>Description</th><th class="num">Qty</th><th>Unit</th><th class="num">Unit Price</th><th class="num">Amount</th><th>Job Order</th></tr></thead>
    <tbody>
    <?php foreach ($lineItems as $li): ?>
      <tr>
        <td><?= View::e($li['description']) ?></td>
        <td class="num"><?= (int) $li['qty'] ?></td>
        <td><?= View::e($li['unit'] ?? '—') ?></td>
        <td class="num"><?= View::money($li['unit_price']) ?></td>
        <td class="num"><?= View::money(((int) $li['qty']) * (float) $li['unit_price']) ?></td>
        <td><?= $li['jo_id'] ? '<a href="' . e(url('/job-orders/' . $li['jo_id'])) . '">' . View::e($li['jo_number']) . '</a>' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="totals-box" style="margin-left:auto">
    <div class="flex justify-between"><span>Subtotal</span><span><?= View::money($order['subtotal']) ?></span></div>
    <?php if ((float) $order['discount_amount'] > 0): ?><div class="flex justify-between"><span><?= View::e($order['discount_label'] ?? 'Discount') ?></span><span>-<?= View::money($order['discount_amount']) ?></span></div><?php endif; ?>
    <?php if ((float) $order['tax_amount'] > 0): ?><div class="flex justify-between"><span>Tax (<?= View::e(rtrim(rtrim((string) $order['tax_pct'], '0'), '.')) ?>%)</span><span><?= View::money($order['tax_amount']) ?></span></div><?php endif; ?>
    <div class="flex justify-between grand-total"><span>Grand Total</span><span><?= View::money($order['total_amount']) ?></span></div>
  </div>
</div></div>

<div class="card"><div class="card-header">Payment History</div><div class="card-body table-wrap">
  <table class="data-table">
    <thead><tr><th>Date</th><th class="num">Amount</th><th>Method</th><th>Reference</th><th>Proof</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
      <tr>
        <td><?= View::e(date('M j, Y', strtotime($p['payment_date']))) ?></td>
        <td class="num"><?= View::money($p['amount']) ?></td>
        <td><?= View::e(PaymentController::METHODS[$p['method']] ?? $p['method']) ?></td>
        <td><?= View::e($p['reference_number'] ?? '—') ?></td>
        <td><?= $p['proof_file_path'] ? '<a href="' . e(url('/files/' . $p['proof_file_path'])) . '" target="_blank" rel="noopener">' . View::e($p['proof_name'] ?? 'View') . '</a>' : '—' ?></td>
        <td><?= status_badge($p['status']) ?>
          <?php if ($p['status'] === 'REJECTED' && $p['reject_reason']): ?><div class="help-text"><?= View::e($p['reject_reason']) ?></div><?php endif; ?>
          <?php if ($p['reviewed_by_name']): ?><div class="help-text">by <?= View::e($p['reviewed_by_name']) ?></div><?php endif; ?>
        </td>
        <td>
          <?php if ($p['status'] === 'PENDING' && AuthService::can('payments.verify')): ?>
            <form method="post" action="<?= e(url('/payments/' . $p['id'] . '/confirm')) ?>" style="display:inline" data-once><?= Csrf::field() ?><button class="btn btn-sm btn-success">Confirm</button></form>
            <button type="button" class="btn btn-sm btn-danger" data-modal-open="reject-<?= e($p['id']) ?>">Reject</button>
            <div id="reject-<?= e($p['id']) ?>" class="modal-overlay" hidden>
              <div class="modal-box" role="dialog" aria-modal="true" aria-label="Reject payment">
                <div class="modal-header">Reject Payment <button type="button" data-modal-close aria-label="Close">&times;</button></div>
                <form method="post" action="<?= e(url('/payments/' . $p['id'] . '/reject')) ?>" data-once>
                  <?= Csrf::field() ?>
                  <div class="modal-body"><div class="field"><label class="field-label" for="rr-<?= e($p['id']) ?>">Reason *</label><textarea id="rr-<?= e($p['id']) ?>" name="reason" rows="3" required maxlength="1000"></textarea></div></div>
                  <div class="modal-footer"><button type="button" class="btn btn-outline" data-modal-close>Close</button><button type="submit" class="btn btn-danger">Reject Payment</button></div>
                </form>
              </div>
            </div>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$payments): ?><tr><td colspan="7" class="empty-state">No payments recorded.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>

<div class="card"><div class="card-header">Job Orders</div><div class="card-body table-wrap">
  <table class="data-table">
    <thead><tr><th>JO #</th><th>Description</th><th>Status</th><th class="num">Quantity</th></tr></thead>
    <tbody>
    <?php foreach ($jobOrders as $jo): ?>
      <tr><td><a href="<?= e(url('/job-orders/' . $jo['id'])) ?>"><?= View::e($jo['jo_number']) ?></a></td><td><?= View::e($jo['description']) ?></td><td><?= status_badge($jo['status']) ?></td><td class="num"><?= (int) $jo['quantity'] ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$jobOrders): ?><tr><td colspan="4" class="empty-state">
      <?= $order['payment_term_type'] === 'APPROVED_TERMS' || $summary['partialMet'] ? 'No job orders yet.' : 'The first job order is created automatically once the required partial payment is confirmed.' ?>
    </td></tr><?php endif; ?>
    </tbody>
  </table>
  <?php if ($live && $linesWithoutJo && AuthService::can('production.manage')): ?>
  <form method="post" action="<?= e(url($base . '/job-orders')) ?>" class="filters mt-2" data-once>
    <?= Csrf::field() ?><?= FormToken::field() ?>
    <select name="line_item_id" aria-label="Line item for new job order" required>
      <?php foreach ($linesWithoutJo as $li): ?><option value="<?= e($li['id']) ?>"><?= View::e(mb_strimwidth($li['description'], 0, 60, '…')) ?> (×<?= (int) $li['qty'] ?>)</option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-outline btn-sm">+ Add Job Order</button>
  </form>
  <?php endif; ?>
</div></div>

<?php if ($summary['balance'] > 0 && $st !== 'CANCELLED' && AuthService::can('payments.record')): ?>
<div id="record-payment-modal" class="modal-overlay" hidden>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="rp-title">
    <div class="modal-header"><span id="rp-title">Record Payment</span> <button type="button" data-modal-close aria-label="Close">&times;</button></div>
    <form method="post" action="<?= e(url($base . '/payments')) ?>" enctype="multipart/form-data" data-once>
      <?= Csrf::field() ?><?= FormToken::field() ?>
      <div class="modal-body">
        <p class="help-text">Outstanding balance: <strong><?= View::money($summary['balance']) ?></strong></p>
        <div class="field"><label class="field-label" for="pay-amount">Amount (PHP) *</label><input id="pay-amount" type="number" name="amount" min="0.01" max="<?= e(number_format($summary['balance'], 2, '.', '')) ?>" step="0.01" required data-balance="<?= e(number_format($summary['balance'], 2, '.', '')) ?>"></div>
        <p class="help-text" data-balance-preview></p>
        <div class="field"><label class="field-label" for="pay-date">Payment date *</label><input id="pay-date" type="date" name="payment_date" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>"></div>
        <div class="field"><label class="field-label" for="pay-method">Payment Method *</label>
          <select id="pay-method" name="method">
            <?php foreach (PaymentController::METHODS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label class="field-label" for="pay-ref">Reference Number</label><input id="pay-ref" type="text" name="reference_number" maxlength="100"><p class="help-text">Required for non-cash payments.</p></div>
        <div class="field"><label class="field-label" for="pay-proof">Proof of Payment (JPG, PNG, WEBP or PDF, max 8 MB)</label><input id="pay-proof" type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf"></div>
        <div class="field"><label class="field-label" for="pay-notes">Notes</label><textarea id="pay-notes" name="notes" rows="2" maxlength="1000"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Record Payment</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($live && AuthService::can('orders.manage')): ?>
<div id="cancel-order-modal" class="modal-overlay" hidden>
  <div class="modal-box" role="dialog" aria-modal="true" aria-label="Cancel order">
    <div class="modal-header">Cancel Order <button type="button" data-modal-close aria-label="Close">&times;</button></div>
    <form method="post" action="<?= e(url($base . '/cancel')) ?>" data-once>
      <?= Csrf::field() ?>
      <div class="modal-body"><div class="field"><label class="field-label" for="co-reason">Reason *</label><textarea id="co-reason" name="reason" rows="3" required maxlength="1000"></textarea></div>
        <p class="help-text">Job orders stop; confirmed payments stay on record.</p></div>
      <div class="modal-footer"><button type="button" class="btn btn-outline" data-modal-close>Keep Order</button><button type="submit" class="btn btn-danger">Cancel Order</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($live && AuthService::can('orders.approve_terms')): ?>
<div id="terms-modal" class="modal-overlay" hidden>
  <div class="modal-box" role="dialog" aria-modal="true" aria-label="Payment terms">
    <div class="modal-header">Payment Terms <button type="button" data-modal-close aria-label="Close">&times;</button></div>
    <form method="post" action="<?= e(url($base . '/payment-terms')) ?>" data-once>
      <?= Csrf::field() ?>
      <div class="modal-body">
        <div class="field"><label class="field-label" for="pt-type">Terms</label>
          <select id="pt-type" name="payment_term_type">
            <option value="STANDARD_PARTIAL" <?= $order['payment_term_type'] === 'STANDARD_PARTIAL' ? 'selected' : '' ?>>Standard — partial payment before production</option>
            <option value="APPROVED_TERMS" <?= $order['payment_term_type'] === 'APPROVED_TERMS' ? 'selected' : '' ?>>Approved terms — production may start before payment</option>
          </select></div>
        <div class="field"><label class="field-label" for="pt-pct">Required partial %</label><input id="pt-pct" type="number" name="required_partial_pct" min="0" max="100" step="1" value="<?= (int) $order['required_partial_pct'] ?>"></div>
        <div class="field"><label class="field-label" for="pt-by">Approved by (approved terms only)</label><input id="pt-by" type="text" name="terms_approved_by" maxlength="255" value="<?= e($order['terms_approved_by'] ?? '') ?>"></div>
        <div class="field"><label class="field-label" for="pt-reason">Reason (approved terms only)</label><textarea id="pt-reason" name="terms_reason" rows="2" maxlength="1000"><?= e($order['terms_reason'] ?? '') ?></textarea></div>
        <div class="field"><label class="field-label" for="pt-due">Payment due date</label><input id="pt-due" type="date" name="due_date" value="<?= e($order['due_date'] ?? '') ?>"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline" data-modal-close>Close</button><button type="submit" class="btn btn-primary">Save Terms</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($live && !$hasException && !$summary['fullyPaid'] && AuthService::can('orders.release_exception')): ?>
<div id="release-exception-modal" class="modal-overlay" hidden>
  <div class="modal-box" role="dialog" aria-modal="true" aria-label="Release exception">
    <div class="modal-header">Allow Release Before Full Payment <button type="button" data-modal-close aria-label="Close">&times;</button></div>
    <form method="post" action="<?= e(url($base . '/release-exception')) ?>" data-once>
      <?= Csrf::field() ?>
      <div class="modal-body"><p class="help-text">The balance stays on the order as money owed. This is recorded in the audit log under your name.</p>
        <div class="field"><label class="field-label" for="re-reason">Reason *</label><textarea id="re-reason" name="reason" rows="3" required minlength="3" maxlength="1000"></textarea></div></div>
      <div class="modal-footer"><button type="button" class="btn btn-outline" data-modal-close>Close</button><button type="submit" class="btn btn-primary">Allow Release</button></div>
    </form>
  </div>
</div>
<?php endif; ?>
