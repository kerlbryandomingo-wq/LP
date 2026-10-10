<?php use App\Helpers\Csrf; use App\Helpers\View; ?>
<div class="flex items-center justify-between">
  <div>
    <h1 class="page-title mb-0"><?= View::e($jobOrder['jo_number']) ?></h1>
    <p class="page-subtitle"><?= View::e($jobOrder['customer_name']) ?> · Order <a href="/orders/<?= View::e($jobOrder['order_id']) ?>"><?= View::e($jobOrder['order_number']) ?></a></p>
  </div>
  <div class="flex gap-2">
    <form method="post" action="/job-orders/<?= View::e($jobOrder['id']) ?>/undo"><?= Csrf::field() ?><button class="btn btn-outline">Undo Last Stage Change</button></form>
    <?php if ($jobOrder['status'] !== 'RELEASED' && $jobOrder['status'] !== 'COMPLETED'): ?>
      <form method="post" action="/job-orders/<?= View::e($jobOrder['id']) ?>/advance"><?= Csrf::field() ?><button class="btn btn-primary"><?= $jobOrder['status'] === 'READY' ? 'Release' : 'Advance to Next Stage' ?></button></form>
    <?php endif; ?>
  </div>
</div>

<div class="card"><div class="card-body">
  <p><span class="text-muted">Status:</span> <span class="badge badge-slate"><?= View::e($jobOrder['status']) ?></span></p>
  <p><span class="text-muted">Description:</span> <?= View::e($jobOrder['description']) ?></p>
  <p><span class="text-muted">Quantity:</span> <?= (int) $jobOrder['quantity'] ?></p>
  <p><span class="text-muted">Priority:</span> <?= View::e($jobOrder['priority']) ?></p>
</div></div>

<div class="card"><div class="card-header">Workflow Stages</div><div class="card-body">
  <div class="flex gap-2" style="flex-wrap:wrap">
    <?php foreach ($stages as $s): ?>
      <span class="badge <?= (int)$s['stage_order'] === (int)$jobOrder['current_stage_order'] ? 'badge-blue' : 'badge-slate' ?>">
        <?= (int) $s['stage_order'] ?>. <?= View::e($s['name']) ?><?= $s['is_qc_stage'] ? ' (QC)' : '' ?>
      </span>
    <?php endforeach; ?>
  </div>
</div></div>

<div class="card"><div class="card-header">QC Checklist</div><div class="card-body">
  <table class="data-table">
    <thead><tr><th>Item</th><th>Outcome</th><th>Notes</th><th>Checked</th></tr></thead>
    <tbody>
    <?php foreach ($qcResults as $qc): ?>
      <tr>
        <td><?= View::e($qc['item_label']) ?></td>
        <td><span class="badge <?= $qc['outcome'] === 'PASS' ? 'badge-green' : 'badge-red' ?>"><?= View::e($qc['outcome']) ?></span></td>
        <td><?= View::e($qc['notes'] ?? '—') ?></td>
        <td><?= View::e(date('M j, Y g:i A', strtotime($qc['checked_at']))) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$qcResults): ?><tr><td colspan="4" class="text-muted">No QC checks recorded.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <form method="post" action="/job-orders/<?= View::e($jobOrder['id']) ?>/qc" class="mt-2">
    <?= Csrf::field() ?>
    <div class="grid grid-cols-3">
      <div class="field"><label class="field-label">Checklist Item</label><input type="text" name="item_label" required></div>
      <div class="field"><label class="field-label">Outcome</label>
        <select name="outcome"><option value="PASS">Pass</option><option value="FAIL">Fail</option></select>
      </div>
      <div class="field"><label class="field-label">Notes</label><input type="text" name="notes"></div>
    </div>
    <button type="submit" class="btn btn-outline">Add QC Result</button>
  </form>
</div></div>
