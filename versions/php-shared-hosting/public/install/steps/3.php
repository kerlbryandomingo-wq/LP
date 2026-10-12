<?php $db = ($db ?? []) + ['host' => 'localhost', 'port' => 3306, 'database' => '', 'username' => '']; ?>
<h2 class="step-title">Database Connection</h2>
<p class="text-muted">Enter the database details from your hosting panel (cPanel → MySQL Databases). Most shared hosts use <code>localhost</code> and port <code>3306</code>.</p>
<form method="post" action="<?= e(url('/install/')) ?>" data-once>
  <input type="hidden" name="_token" value="<?= e($token) ?>">
  <input type="hidden" name="_step" value="3">
  <div class="grid grid-cols-2">
    <div class="field"><label class="field-label" for="db_host">Database Host</label><input type="text" id="db_host" name="db_host" value="<?= e((string) $db['host']) ?>" required maxlength="255"></div>
    <div class="field"><label class="field-label" for="db_port">Port</label><input type="number" id="db_port" name="db_port" value="<?= (int) $db['port'] ?>" min="1" max="65535" required></div>
  </div>
  <div class="field"><label class="field-label" for="db_name">Database Name</label><input type="text" id="db_name" name="db_name" value="<?= e((string) $db['database']) ?>" required maxlength="64" autocomplete="off"></div>
  <div class="field"><label class="field-label" for="db_user">Database Username</label><input type="text" id="db_user" name="db_user" value="<?= e((string) $db['username']) ?>" required maxlength="80" autocomplete="off"></div>
  <div class="field"><label class="field-label" for="db_pass">Database Password</label><input type="password" id="db_pass" name="db_pass" maxlength="256" autocomplete="new-password"></div>
  <?php if (!empty($existing)): ?>
    <label class="checkbox confirm-box"><input type="checkbox" name="confirm_existing" value="1" required>
      I understand this database already holds LP System data. Keep it (nothing is deleted) and bring it up to date.</label>
  <?php endif; ?>
  <button type="submit" class="btn btn-primary">Test Connection &amp; Continue</button>
</form>
