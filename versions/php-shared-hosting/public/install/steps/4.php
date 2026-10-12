<?php global $S; $state = $S['db_state']['state'] ?? 'empty'; ?>
<h2 class="step-title">Create Database Tables</h2>
<p>Connected to <strong><?= e($S['db']['database'] ?? '') ?></strong> (<?= e($S['db_version'] ?? '') ?>).</p>
<?php if ($state === 'lp'): ?>
  <div class="alert alert-warning">This database already contains LP System data. Only missing tables and pending updates will be applied; existing records are kept.</div>
<?php elseif ($state === 'other'): ?>
  <div class="alert alert-info">This database also contains tables from another application. They will not be touched.</div>
<?php endif; ?>
<p>This creates the application tables and loads the starter data (a standard production workflow). It is safe to run again if it is interrupted.</p>
<form method="post" action="<?= e(url('/install/')) ?>" data-once>
  <input type="hidden" name="_token" value="<?= e($token) ?>">
  <input type="hidden" name="_step" value="4">
  <button type="submit" class="btn btn-primary">Create Tables</button>
</form>
