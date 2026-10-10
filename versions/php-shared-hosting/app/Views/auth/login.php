<?php
use App\Helpers\Csrf;
use App\Helpers\View;
/** @var string|null $error */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — LP System</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <div class="logo">L</div>
    <h1 class="page-title" style="text-align:center">LP System</h1>
    <p class="page-subtitle" style="text-align:center">Sign in to your account</p>
    <?php if ($error): ?>
      <div class="alert alert-error"><?= View::e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/login">
      <?= Csrf::field() ?>
      <div class="field">
        <label class="field-label" for="email">Email</label>
        <input type="email" id="email" name="email" required autofocus>
      </div>
      <div class="field">
        <label class="field-label" for="password">Password</label>
        <input type="password" id="password" name="password" required>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center">Sign in</button>
    </form>
  </div>
</div>
</body>
</html>
