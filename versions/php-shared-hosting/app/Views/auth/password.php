<?php use App\Helpers\Csrf; use App\Helpers\View; ?>
<h1 class="page-title">Change Password</h1>
<?php if ($forced): ?>
  <div class="alert alert-warning">An administrator set a temporary password for your account. Choose your own password to continue.</div>
<?php endif; ?>
<?php require __DIR__ . '/../partials/errors.php'; ?>
<div class="card" style="max-width:480px"><div class="card-body">
<form method="post" action="<?= e(url('/account/password')) ?>" data-once>
  <?= Csrf::field() ?>
  <div class="field"><label class="field-label" for="current_password">Current password</label><input type="password" id="current_password" name="current_password" required autocomplete="current-password"></div>
  <div class="field"><label class="field-label" for="new_password">New password</label><input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
    <p class="help-text">At least 8 characters, including a letter and a number.</p></div>
  <div class="field"><label class="field-label" for="new_password_confirm">Confirm new password</label><input type="password" id="new_password_confirm" name="new_password_confirm" required minlength="8" autocomplete="new-password"></div>
  <button type="submit" class="btn btn-primary">Change password</button>
</form>
</div></div>
