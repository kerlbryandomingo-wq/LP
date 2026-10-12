<?php $allOk = requirements_ok($checks); ?>
<h2 class="step-title">Requirements Check</h2>
<div class="table-wrap">
<table class="data-table">
  <tbody>
  <?php foreach ($checks as $c): ?>
    <tr data-check-ok="<?= $c['ok'] === true ? '1' : ($c['ok'] === false ? '0' : '?') ?>">
      <td><?= e($c['label']) ?></td>
      <td><?php if ($c['ok'] === true): ?><span class="badge badge-green">OK</span>
        <?php elseif (!empty($c['optional'])): ?><span class="badge badge-yellow"><?= $c['ok'] === null ? 'Unverified' : 'Recommended' ?></span>
        <?php else: ?><span class="badge badge-red">FAIL</span><?php endif; ?></td>
      <td class="text-muted"><?= e($c['detail']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (!$allOk): ?>
  <div class="alert alert-error">Some requirements are not met. Fix the items marked FAIL, then check again.</div>
  <a href="<?= e(install_url(2)) ?>" class="btn btn-outline">Check Again</a>
<?php else: ?>
  <div class="alert alert-success">All required checks passed.</div>
  <form method="post" action="<?= e(url('/install/')) ?>">
    <input type="hidden" name="_token" value="<?= e($token) ?>">
    <input type="hidden" name="_step" value="2">
    <button type="submit" class="btn btn-primary">Continue</button>
  </form>
<?php endif; ?>
