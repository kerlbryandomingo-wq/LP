<?php use App\Helpers\Csrf; use App\Helpers\View; use App\Services\AuthService; ?>
<div class="flex items-center justify-between page-head">
  <div>
    <h1 class="page-title mb-0">Inquiry — <?= View::e($inquiry['customer_name']) ?></h1>
    <p class="page-subtitle"><?= status_badge($inquiry['status']) ?> · <?= View::e(date('M j, Y', strtotime($inquiry['created_at']))) ?></p>
  </div>
  <?php if ($inquiry['status'] === 'NEW'): ?>
  <div class="flex gap-2">
    <?php if (AuthService::can('inquiries.manage')): ?>
      <button type="button" class="btn btn-outline" data-modal-open="cancel-inquiry-modal">Cancel Inquiry</button>
    <?php endif; ?>
    <?php if (AuthService::can('quotations.manage')): ?>
    <form method="post" action="<?= e(url('/inquiries/' . $inquiry['id'] . '/convert')) ?>">
      <?= Csrf::field() ?>
      <button type="submit" class="btn btn-primary">Convert to Quotation</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="card"><div class="card-body">
  <p><span class="text-muted">Customer:</span> <a href="<?= e(url('/customers/' . $inquiry['customer_id'])) ?>"><?= View::e($inquiry['customer_name']) ?></a></p>
  <p><span class="text-muted">Desired Product:</span> <?= View::e($inquiry['desired_product']) ?></p>
  <?php if ($inquiry['service_name']): ?><p><span class="text-muted">Service:</span> <?= View::e($inquiry['service_name']) ?></p><?php endif; ?>
  <p><span class="text-muted">Description:</span> <?= nl2br(View::e($inquiry['description'])) ?></p>
  <?php if ($inquiry['rough_qty']): ?>
    <p><span class="text-muted">Rough Quantity:</span> <?= (int) $inquiry['rough_qty'] ?> <?= View::e($inquiry['rough_qty_unit'] ?? '') ?></p>
  <?php endif; ?>
  <?php if ($inquiry['status'] === 'CANCELLED'): ?>
    <p><span class="text-muted">Cancel reason:</span> <?= View::e($inquiry['cancel_reason'] ?? '—') ?></p>
  <?php endif; ?>
</div></div>

<?php if ($quotations): ?>
<div class="card"><div class="card-header">Quotations from this inquiry</div><div class="card-body table-wrap">
  <table class="data-table">
    <thead><tr><th>Quote #</th><th>Status</th><th class="num">Total</th></tr></thead>
    <tbody>
    <?php foreach ($quotations as $q): ?>
      <tr><td><a href="<?= e(url('/quotations/' . $q['id'])) ?>"><?= View::e($q['quote_number']) ?></a></td><td><?= status_badge($q['status']) ?></td><td class="num"><?= View::money($q['total']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<?php endif; ?>

<div id="cancel-inquiry-modal" class="modal-overlay" hidden>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="cancel-inquiry-title">
    <div class="modal-header"><span id="cancel-inquiry-title">Cancel Inquiry</span> <button type="button" data-modal-close aria-label="Close">&times;</button></div>
    <form method="post" action="<?= e(url('/inquiries/' . $inquiry['id'] . '/cancel')) ?>" data-once>
      <?= Csrf::field() ?>
      <div class="modal-body">
        <div class="field"><label class="field-label" for="inq-cancel-reason">Reason *</label><textarea id="inq-cancel-reason" name="reason" rows="3" required maxlength="1000"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" data-modal-close>Keep Inquiry</button>
        <button type="submit" class="btn btn-danger">Cancel Inquiry</button>
      </div>
    </form>
  </div>
</div>
