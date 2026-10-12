<?php
use App\Helpers\Csrf;
use App\Helpers\View;
use App\Services\AuthService;
use App\Services\QuotationService;
$st = $quotation['status'];
$canManage = AuthService::can('quotations.manage');
$canApprove = AuthService::can('quotations.approve');
$base = '/quotations/' . $quotation['id'];
$modal = function (string $id, string $title, string $action, string $field, string $label, string $button, string $tone, string $help = '') {
    ?>
<div id="<?= e($id) ?>" class="modal-overlay" hidden>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="<?= e($id) ?>-title">
    <div class="modal-header"><span id="<?= e($id) ?>-title"><?= e($title) ?></span> <button type="button" data-modal-close aria-label="Close">&times;</button></div>
    <form method="post" action="<?= e(url($action)) ?>" data-once>
      <?= Csrf::field() ?>
      <div class="modal-body">
        <?php if ($help): ?><p class="help-text"><?= e($help) ?></p><?php endif; ?>
        <div class="field"><label class="field-label" for="<?= e($id) ?>-field"><?= e($label) ?> *</label><textarea id="<?= e($id) ?>-field" name="<?= e($field) ?>" rows="3" required minlength="3" maxlength="1000"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" data-modal-close>Close</button>
        <button type="submit" class="btn btn-<?= e($tone) ?>"><?= e($button) ?></button>
      </div>
    </form>
  </div>
</div>
    <?php
};
?>
<div class="flex items-center justify-between page-head">
  <div>
    <h1 class="page-title mb-0"><?= View::e($quotation['quote_number']) ?></h1>
    <p class="page-subtitle"><a href="<?= e(url('/customers/' . $quotation['customer_id'])) ?>"><?= View::e($quotation['customer_name']) ?></a> · <?= status_badge($st) ?></p>
  </div>
  <div class="flex gap-2 actions">
    <?php if ($canManage && in_array($st, QuotationService::EDITABLE, true)): ?>
      <a class="btn btn-outline" href="<?= e(url($base . '/edit')) ?>">Edit</a>
    <?php endif; ?>
    <?php if ($canManage && in_array($st, QuotationService::SENDABLE, true)): ?>
      <form method="post" action="<?= e(url($base . '/send')) ?>" data-once><?= Csrf::field() ?><button class="btn btn-outline">Mark as Sent</button></form>
    <?php endif; ?>
    <?php if ($canApprove && in_array($st, QuotationService::APPROVABLE, true)): ?>
      <button type="button" class="btn btn-success" data-modal-open="approve-modal">Approve</button>
    <?php endif; ?>
    <?php if ($canApprove && $st === 'SENT'): ?>
      <button type="button" class="btn btn-outline" data-modal-open="revision-modal">Customer Wants Changes</button>
      <button type="button" class="btn btn-danger" data-modal-open="reject-modal">Customer Rejected</button>
    <?php endif; ?>
    <?php if ($canManage && in_array($st, QuotationService::CANCELLABLE, true)): ?>
      <button type="button" class="btn btn-ghost" data-modal-open="cancel-modal">Cancel Quotation</button>
    <?php endif; ?>
    <?php if ($canManage && $st === 'CANCELLED'): ?>
      <form method="post" action="<?= e(url($base . '/restore')) ?>" data-once><?= Csrf::field() ?><button class="btn btn-outline">Restore</button></form>
    <?php endif; ?>
    <?php if ($st === 'APPROVED' && $order): ?>
      <a class="btn btn-primary" href="<?= e(url('/orders/' . $order['id'])) ?>">View Order <?= View::e($order['order_number']) ?></a>
    <?php elseif ($st === 'APPROVED' && AuthService::can('orders.manage')): ?>
      <form method="post" action="<?= e(url($base . '/convert')) ?>" data-once><?= Csrf::field() ?><button class="btn btn-primary">Create Order</button></form>
    <?php endif; ?>
  </div>
</div>

<?php if ($st === 'APPROVED' && $quotation['approval_reason']): ?>
  <div class="alert alert-info">Approved<?= $quotation['approved_by_name'] ? ' by ' . View::e($quotation['approved_by_name']) : '' ?> on the customer's behalf: <?= View::e($quotation['approval_reason']) ?></div>
<?php endif; ?>
<?php if ($st === 'REJECTED' && $quotation['reject_reason']): ?>
  <div class="alert alert-error">Customer rejected: <?= View::e($quotation['reject_reason']) ?></div>
<?php endif; ?>
<?php if ($st === 'CANCELLED'): ?>
  <div class="alert alert-warning">Cancelled: <?= View::e($quotation['cancel_reason'] ?? '') ?></div>
<?php endif; ?>

<div class="card"><div class="card-body table-wrap">
  <table class="data-table">
    <thead><tr><th>Description</th><th class="num">Qty</th><th>Unit</th><th class="num">Unit Price</th><th class="num">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($quotation['line_items'] as $li): ?>
      <tr>
        <td><?= View::e($li['description']) ?></td>
        <td class="num"><?= (int) $li['qty'] ?></td>
        <td><?= View::e($li['unit'] ?? '—') ?></td>
        <td class="num"><?= View::money($li['unit_price']) ?></td>
        <td class="num"><?= View::money(((int) $li['qty']) * (float) $li['unit_price']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$quotation['line_items']): ?><tr><td colspan="5" class="empty-state">No line items.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>

<div class="card"><div class="card-body totals-box" style="margin-left:auto">
  <div class="flex justify-between"><span>Subtotal</span><span data-total="subtotal"><?= View::money($quotation['subtotal']) ?></span></div>
  <?php if ((float) $quotation['discount_amount'] > 0): ?>
    <div class="flex justify-between"><span><?= View::e($quotation['discount_label'] ?? 'Discount') ?></span><span data-total="discount">-<?= View::money($quotation['discount_amount']) ?></span></div>
  <?php endif; ?>
  <?php if ((float) $quotation['tax_amount'] > 0): ?>
    <div class="flex justify-between"><span>Tax (<?= View::e(rtrim(rtrim((string) $quotation['tax_pct'], '0'), '.')) ?>%)</span><span data-total="tax"><?= View::money($quotation['tax_amount']) ?></span></div>
  <?php endif; ?>
  <div class="flex justify-between grand-total"><span>Grand Total</span><span data-total="total"><?= View::money($quotation['total']) ?></span></div>
  <?php if ($quotation['valid_until']): ?><p class="help-text">Valid until <?= View::e(date('M j, Y', strtotime($quotation['valid_until']))) ?></p><?php endif; ?>
</div></div>

<?php if ($quotation['notes']): ?>
<div class="card"><div class="card-header">Notes</div><div class="card-body"><?= nl2br(View::e($quotation['notes'])) ?></div></div>
<?php endif; ?>

<?php
if ($canApprove && in_array($st, QuotationService::APPROVABLE, true)) {
    $modal('approve-modal', 'Approve on Customer\'s Behalf', $base . '/approve', 'reason', 'How did the customer approve?', 'Approve & Create Order', 'success',
        'Approving creates the order immediately. Production starts once the required partial payment is confirmed (or the customer has approved payment terms).');
}
if ($canApprove && $st === 'SENT') {
    $modal('reject-modal', 'Record Customer Rejection', $base . '/reject', 'reason', 'Customer\'s reason', 'Record Rejection', 'danger');
    $modal('revision-modal', 'Record Requested Changes', $base . '/revision', 'message', 'What does the customer want changed?', 'Record Request', 'primary');
}
if ($canManage && in_array($st, QuotationService::CANCELLABLE, true)) {
    $modal('cancel-modal', 'Cancel Quotation', $base . '/cancel', 'reason', 'Reason', 'Cancel Quotation', 'danger');
}
?>
