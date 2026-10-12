<h2 class="step-title">Welcome</h2>
<p>This wizard installs LP System on this server. You will need:</p>
<ul>
  <li>An <strong>empty MySQL or MariaDB database</strong>, and a database user with ALL PRIVILEGES on it (cPanel → MySQL Databases).</li>
  <li>The database name, username and password.</li>
  <li>About 5 minutes.</li>
</ul>
<p>You will not need to edit any files. Nothing is written to the database until step 4.</p>
<form method="post" action="<?= e(url('/install/')) ?>">
  <input type="hidden" name="_token" value="<?= e($token) ?>">
  <input type="hidden" name="_step" value="1">
  <button type="submit" class="btn btn-primary">Start Installation</button>
</form>
