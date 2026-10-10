<?php $db = $db ?? ['host' => '127.0.0.1', 'port' => 3306, 'database' => '', 'username' => '']; ?>
<h2 style="margin-top:0">Database Connection</h2>
<p class="text-muted">Enter the MySQL/MariaDB credentials your host gave you. Most cPanel hosts create these under "MySQL Databases."</p>
<?php if (!empty($error)): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<form method="post" action="/install/">
  <input type="hidden" name="_step" value="3">
  <div class="grid grid-cols-2">
    <div class="field"><label class="field-label">Database Host</label><input type="text" name="db_host" value="<?= htmlspecialchars($db['host']) ?>" required></div>
    <div class="field"><label class="field-label">Port</label><input type="number" name="db_port" value="<?= (int) $db['port'] ?>" required></div>
  </div>
  <div class="field"><label class="field-label">Database Name</label><input type="text" name="db_name" value="<?= htmlspecialchars($db['database']) ?>" required></div>
  <div class="field"><label class="field-label">Database Username</label><input type="text" name="db_user" value="<?= htmlspecialchars($db['username']) ?>" required></div>
  <div class="field"><label class="field-label">Database Password</label><input type="password" name="db_pass"></div>
  <button type="submit" class="btn btn-primary">Test Connection &amp; Continue</button>
</form>
