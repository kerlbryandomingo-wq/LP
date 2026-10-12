<?php use App\Helpers\Csrf; ?>
<h1 class="page-title">Database Updates</h1>
<p class="page-subtitle">After uploading a newer LP System release, apply its database updates here. Back up your database first (see BACKUP.md).</p>

<div class="card"><div class="card-body">
  <p><span class="text-muted">PHP:</span> <?= e($phpVersion) ?> · <span class="text-muted">Database server:</span> <?= e($dbVersion) ?></p>
  <?php if ($pending): ?>
    <div class="alert alert-warning"><?= count($pending) ?> pending update(s):</div>
    <ul><?php foreach ($pending as $p): ?><li><code><?= e($p) ?></code></li><?php endforeach; ?></ul>
    <form method="post" action="<?= e(url('/admin/updates')) ?>" data-once data-confirm="Apply database updates now? Make sure you have a fresh backup.">
      <?= Csrf::field() ?>
      <button type="submit" class="btn btn-primary">Apply Updates</button>
    </form>
  <?php else: ?>
    <div class="alert alert-success" data-updates="none">The database is up to date.</div>
  <?php endif; ?>
  <?php if ($missing): ?>
    <div class="alert alert-error">Missing tables: <?= e(implode(', ', $missing)) ?>. Applying updates normally creates them; if this persists, restore from backup.</div>
  <?php endif; ?>
</div></div>

<div class="card"><div class="card-header">Applied (<?= count($applied) ?>)</div><div class="card-body">
  <ul><?php foreach ($applied as $a): ?><li><code><?= e($a) ?></code></li><?php endforeach; ?></ul>
</div></div>
