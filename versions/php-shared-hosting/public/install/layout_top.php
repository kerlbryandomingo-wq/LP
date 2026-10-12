<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Install LP System — Step <?= (int) min($currentStep, 6) ?></title>
<link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
</head>
<body class="installer">
<div class="install-wrap">
  <div class="install-head">
    <div class="logo">L</div>
    <h1 class="page-title">LP System Installer</h1>
    <p class="page-subtitle">PHP / MySQL Shared-Hosting Edition</p>
  </div>
  <ol class="install-steps" aria-label="Installation progress">
    <?php foreach ([1 => 'Welcome', 2 => 'Requirements', 3 => 'Database', 4 => 'Tables', 5 => 'Administrator', 6 => 'Finish'] as $i => $label): ?>
      <li class="<?= $currentStep > $i ? 'done' : ($currentStep === $i ? 'current' : '') ?>" <?= $currentStep === $i ? 'aria-current="step"' : '' ?>><span><?= e($label) ?></span></li>
    <?php endforeach; ?>
  </ol>
  <div class="card"><div class="card-body">
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="alert alert-error" role="alert"><ul class="error-list"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
