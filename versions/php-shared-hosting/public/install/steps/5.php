<?php $old = $old ?? []; ?>
<h2 class="step-title">Create Administrator Account</h2>
<div class="alert alert-success">Database tables are ready.</div>
<form method="post" action="<?= e(url('/install/')) ?>" data-once>
  <input type="hidden" name="_token" value="<?= e($token) ?>">
  <input type="hidden" name="_step" value="5">
  <div class="field"><label class="field-label" for="admin_name">Full Name</label><input type="text" id="admin_name" name="admin_name" required maxlength="255" value="<?= e($old['admin_name'] ?? '') ?>"></div>
  <div class="field"><label class="field-label" for="admin_email">Email (used to sign in)</label><input type="email" id="admin_email" name="admin_email" required maxlength="255" value="<?= e($old['admin_email'] ?? '') ?>" autocomplete="username"></div>
  <div class="field"><label class="field-label" for="admin_password">Password</label><input type="password" id="admin_password" name="admin_password" minlength="8" maxlength="72" required autocomplete="new-password">
    <p class="help-text">At least 8 characters, including a letter and a number.</p></div>
  <div class="field"><label class="field-label" for="admin_password_confirm">Confirm Password</label><input type="password" id="admin_password_confirm" name="admin_password_confirm" minlength="8" maxlength="72" required autocomplete="new-password"></div>
  <button type="submit" class="btn btn-primary">Continue</button>
</form>
