<?php
use App\Helpers\Csrf;
use App\Helpers\FormToken;
use App\Helpers\View;
use App\Services\AuthService;
$st = $jobOrder['status'];
$frozen = in_array($jobOrder['order_status'], ['CANCELLED', 'COMPLETED'], true);
$expected = '<input type="hidden" name="expected_stage" value="' . (int) $jobOrder['current_stage_order'] . '"><input type="hidden" name="expected_status" value="' . e($st) . '">';
$canAdvance = AuthService::can('production.advance') && !$frozen && !in_array($st, ['RELEASED', 'COMPLETED'], true);
$advanceLabel = match ($st) {
    'ON_HOLD' => 'Start Production',
    'READY' => 'Release to Customer',
    'QC' => 'QC Passed — Continue',
    default => 'Advance to Next Stage',
};
$blocked = match (true) {
    $st === 'ON_HOLD' && !$canStart => 'Waiting for the required partial payment (or approved payment terms) before production can start.',
    $st === 'READY' && !$canRelease => 'Release needs full payment or an administrator\'s release exception.',
    $st === 'QC' && !$qcResults => 'Record at least one QC result before continuing.',
    $st === 'QC' && (bool) $failing => 'QC failed for: ' . implode(', ', $failing) . '. Record a passing re-check first.',
    default => null,
};
?>
<div class="flex items-center justify-between page-head">
  <div>
    <h1 class="page-title mb-0"><?= View::e($jobOrder['jo_number']) ?></h1>
    <p class="page-subtitle"><?= View::e($jobOrder['customer_name']) ?> · Order
      <?php if (AuthService::can('orders.view')): ?><a href="<?= e(url('/orders/' . $jobOrder['order_id'])) ?>"><?= View::e($jobOrder['order_number']) ?></a><?php else: ?><?= View::e($jobOrder['order_number']) ?><?php endif; ?>
      · <?= status_badge($st) ?></p>
  </div>
  <div class="flex gap-2 actions">
    <?php if (AuthService::can('production.advance') && !$frozen && $history): ?>
      <form method="post" action="<?= e(url('/job-orders/' . $jobOrder['id'] . '/undo')) ?>" data-once data-confirm="Undo the last stage change?"><?= Csrf::field() ?><?= $expected ?><button class="btn btn-outline">Undo Last Stage Change</button></form>
    <?php endif; ?>
    <?php if ($canAdvance): ?>
      <form method="post" action="<?= e(url('/job-orders/' . $jobOrder['id'] . '/advance')) ?>" data-once><?= Csrf::field() ?><?= $expected ?><button class="btn btn-primary" <?= $blocked ? 'disabled title="' . e($blocked) . '"' : '' ?>><?= e($advanceLabel) ?></button></form>
    <?php endif; ?>
  </div>
</div>
<?php if ($frozen): ?><div class="alert alert-warning">The order is <?= e(strtolower($jobOrder['order_status'])) ?>; this job order can no longer change.</div><?php endif; ?>
<?php if ($blocked && $canAdvance): ?><div class="alert alert-info" data-blocked><?= e($blocked) ?></div><?php endif; ?>

<div class="grid grid-cols-2">
  <div class="card"><div class="card-body">
    <p><span class="text-muted">Item:</span> <?= View::e($jobOrder['description']) ?></p>
    <p><span class="text-muted">Quantity:</span> <?= (int) $jobOrder['quantity'] ?></p>
    <p><span class="text-muted">Priority:</span> <?= View::e($jobOrder['priority']) ?></p>
    <?php if ($jobOrder['production_instructions']): ?><p><span class="text-muted">Instructions:</span> <?= nl2br(View::e($jobOrder['production_instructions'])) ?></p><?php endif; ?>
  </div></div>
  <?php if ($summary): ?>
  <div class="card"><div class="card-header">Payment</div><div class="card-body">
    <div class="flex justify-between"><span>Confirmed</span><span><?= View::money($summary['confirmed']) ?> of <?= View::money($summary['total']) ?></span></div>
    <div class="flex justify-between"><span>Can start production</span><span><?= $canStart ? 'Yes' : 'No' ?></span></div>
    <div class="flex justify-between"><span>Can release</span><span><?= $canRelease ? 'Yes' : 'No' ?></span></div>
  </div></div>
  <?php endif; ?>
</div>

<div class="card"><div class="card-header">Workflow Stages</div><div class="card-body">
  <ol class="stage-list">
    <?php foreach ($stages as $s): $cur = (int) $s['stage_order'] === (int) $jobOrder['current_stage_order']; $done = (int) $s['stage_order'] < (int) $jobOrder['current_stage_order'] || in_array($st, ['RELEASED', 'COMPLETED'], true); ?>
      <li class="<?= $cur ? 'current' : ($done ? 'done' : '') ?>" <?= $cur ? 'aria-current="step"' : '' ?>>
        <?= View::e($s['name']) ?><?= $s['is_qc_stage'] ? ' (QC)' : '' ?>
      </li>
    <?php endforeach; ?>
  </ol>
</div></div>

<div class="card"><div class="card-header">QC Checklist</div><div class="card-body table-wrap">
  <table class="data-table">
    <thead><tr><th>Item</th><th>Outcome</th><th>Notes</th><th>Checked</th></tr></thead>
    <tbody>
    <?php foreach ($qcResults as $qc): ?>
      <tr>
        <td><?= View::e($qc['item_label']) ?></td>
        <td><?= status_badge($qc['outcome']) ?></td>
        <td><?= View::e($qc['notes'] ?? '—') ?></td>
        <td><?= View::e(date('M j, Y g:i A', strtotime((string) $qc['checked_at']))) ?><?= $qc['checked_by_name'] ? ' · ' . View::e($qc['checked_by_name']) : '' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$qcResults): ?><tr><td colspan="4" class="empty-state">No QC checks recorded.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <?php if ($st === 'QC' && !$frozen && AuthService::can('qc.record')): ?>
  <form method="post" action="<?= e(url('/job-orders/' . $jobOrder['id'] . '/qc')) ?>" class="mt-2" data-once>
    <?= Csrf::field() ?><?= FormToken::field() ?>
    <div class="grid grid-cols-3">
      <div class="field"><label class="field-label" for="qc-item">Checklist Item *</label><input id="qc-item" type="text" name="item_label" required maxlength="255" placeholder="e.g. Colors match proof"></div>
      <div class="field"><label class="field-label" for="qc-outcome">Outcome *</label>
        <select id="qc-outcome" name="outcome"><option value="PASS">Pass</option><option value="FAIL">Fail</option></select>
      </div>
      <div class="field"><label class="field-label" for="qc-notes">Notes</label><input id="qc-notes" type="text" name="notes" maxlength="1000"></div>
    </div>
    <button type="submit" class="btn btn-outline">Add QC Result</button>
  </form>
  <?php elseif ($st !== 'QC'): ?>
    <p class="help-text mt-2">QC results are recorded when the job order reaches the Quality Control stage.</p>
  <?php endif; ?>
</div></div>

<div class="card"><div class="card-header">Stage History</div><div class="card-body table-wrap">
  <table class="data-table">
    <thead><tr><th>When</th><th>Change</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($history as $h): ?>
      <tr><td><?= View::e(date('M j, Y g:i A', strtotime($h['created_at']))) ?></td>
        <td><?= status_badge($h['from_status']) ?> → <?= status_badge($h['to_status']) ?></td>
        <td><?= View::e($h['actor_name'] ?? '—') ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$history): ?><tr><td colspan="3" class="empty-state">No stage changes yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
