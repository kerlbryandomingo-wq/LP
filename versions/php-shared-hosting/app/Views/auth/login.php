<?php
use App\Helpers\Csrf;
use App\Helpers\View;
/** @var string|null $error */
/** @var string $email */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — LP System</title>
<link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <div class="logo">L</div>
    <h1 class="page-title" style="text-align:center">LP System</h1>
    <p class="page-subtitle" style="text-align:center">Sign in to your account</p>
    <?php if ($error): ?>
      <div class="alert alert-error" role="alert"><?= View::e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/login')) ?>" data-once>
      <?= Csrf::field() ?>
      <div class="field">
        <label class="field-label" for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= View::e($email) ?>" required autofocus autocomplete="username">
      </div>
      <div class="field">
        <label class="field-label" for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center">Sign in</button>
    </form>
  </div>
</div>
<script src="<?= e(url('/assets/js/app.js')) ?>"></script>
</body>
</html>
