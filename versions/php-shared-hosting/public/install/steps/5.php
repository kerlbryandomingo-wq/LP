<h2 style="margin-top:0">Create Administrator Account</h2>
<?php if (!empty($error)): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<form method="post" action="/install/">
  <input type="hidden" name="_step" value="5">
  <div class="field"><label class="field-label">Full Name</label><input type="text" name="admin_name" required></div>
  <div class="field"><label class="field-label">Email (this is your login username)</label><input type="email" name="admin_email" required></div>
  <div class="field"><label class="field-label">Password (min. 8 characters)</label><input type="password" name="admin_password" minlength="8" required></div>
  <div class="field"><label class="field-label">Confirm Password</label><input type="password" name="admin_password_confirm" minlength="8" required></div>
  <button type="submit" class="btn btn-primary">Continue</button>
</form>
