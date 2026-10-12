<h2 class="step-title">Installation Complete</h2>
<div class="alert alert-success" data-install-complete>LP System is installed<?= $done['reused'] ? ' and your existing data was kept' : '' ?>.</div>
<p>Sign in with <strong><?= e($done['email']) ?></strong> and the password you chose.</p>
<p>The installer is now locked: visiting it again shows an "already installed" page and cannot change anything.
For extra safety you may also delete the <code>public/install/</code> folder (see INSTALL.md).</p>
<p><strong>Next:</strong> back up your database regularly (see BACKUP.md), and add staff accounts under Admin → Users.</p>
<a href="<?= e(url('/login')) ?>" class="btn btn-primary">Go to Sign In</a>
