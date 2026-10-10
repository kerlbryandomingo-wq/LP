<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install LP System</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
  .install-wrap { max-width: 640px; margin: 40px auto; padding: 0 16px; }
  .steps { display: flex; gap: 4px; margin-bottom: 24px; }
  .steps span { flex: 1; height: 4px; background: var(--slate-200); border-radius: 2px; }
  .steps span.done { background: var(--color-brand-600); }
</style>
</head>
<body>
<div class="install-wrap">
  <div style="text-align:center;margin-bottom:16px">
    <span class="login-card" style="display:inline-block;padding:0"></span>
    <h1 class="page-title">LP System Installer</h1>
    <p class="page-subtitle">PHP / MySQL Shared-Hosting Edition</p>
  </div>
  <div class="steps">
    <?php for ($i = 1; $i <= 6; $i++): ?>
      <span class="<?= ($currentStep ?? 1) >= $i ? 'done' : '' ?>"></span>
    <?php endfor; ?>
  </div>
  <div class="card"><div class="card-body">
