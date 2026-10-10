<?php
/** @var array|null $user */
use App\Helpers\Csrf;
use App\Helpers\View;

$currentPath = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/', '/') ?: '/';
$navSections = [
    'MAIN' => [
        ['href' => '/dashboard', 'label' => 'Dashboard'],
        ['href' => '/inquiries', 'label' => 'Inquiries'],
        ['href' => '/quotations', 'label' => 'Quotations'],
        ['href' => '/orders', 'label' => 'Orders'],
    ],
    'OPERATIONS' => [
        ['href' => '/job-orders', 'label' => 'Production'],
        ['href' => '/inventory', 'label' => 'Inventory'],
    ],
    'FINANCE' => [
        ['href' => '/payments', 'label' => 'Payments'],
    ],
    'CUSTOMERS' => [
        ['href' => '/customers', 'label' => 'Customers'],
    ],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LP System</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand">
      <span class="logo">L</span>
      <span class="name">LP Printing</span>
    </div>
    <nav>
      <?php foreach ($navSections as $sectionLabel => $items): ?>
        <div class="section-label"><?= View::e($sectionLabel) ?></div>
        <?php foreach ($items as $item): ?>
          <a class="nav-item <?= $currentPath === $item['href'] ? 'active' : '' ?>" href="<?= View::e($item['href']) ?>"><?= View::e($item['label']) ?></a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
  </aside>

  <div class="mobile-nav-drawer">
    <div class="backdrop"></div>
    <div class="panel">
      <?php foreach ($navSections as $sectionLabel => $items): ?>
        <div class="section-label"><?= View::e($sectionLabel) ?></div>
        <?php foreach ($items as $item): ?>
          <a class="nav-item" href="<?= View::e($item['href']) ?>"><?= View::e($item['label']) ?></a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="main">
    <header class="topbar">
      <button type="button" class="mobile-nav-toggle" aria-label="Menu">☰</button>
      <div style="flex:1"></div>
      <?php if ($user): ?>
        <div class="user"><strong><?= View::e($user['name']) ?></strong><?= View::e($user['role']) ?></div>
        <form method="post" action="/logout" style="margin:0">
          <?= Csrf::field() ?>
          <button type="submit" class="btn btn-outline btn-sm">Sign out</button>
        </form>
      <?php endif; ?>
    </header>
    <main class="content">
      <?php foreach (View::pullFlashes() as $flash): ?>
        <div class="alert alert-<?= View::e($flash['type']) ?>"><?= View::e($flash['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?? '' ?>
    </main>
  </div>
</div>
<script src="/assets/js/app.js"></script>
</body>
</html>
