<?php
/** @var array|null $user */
use App\Helpers\Csrf;
use App\Helpers\View;
use App\Services\Policy;

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = base_path();
$appPath = ($base !== '' && str_starts_with($requestPath, $base)) ? substr($requestPath, strlen($base)) : $requestPath;
$appPath = '/' . trim($appPath, '/');

$navSections = [
    'MAIN' => [
        ['/dashboard', 'Dashboard', 'dashboard.view'],
        ['/inquiries', 'Inquiries', 'inquiries.view'],
        ['/quotations', 'Quotations', 'quotations.view'],
        ['/orders', 'Orders', 'orders.view'],
    ],
    'OPERATIONS' => [
        ['/job-orders', 'Production', 'production.view'],
        ['/inventory', 'Inventory', 'inventory.view'],
    ],
    'FINANCE' => [
        ['/payments', 'Payments', 'payments.view'],
    ],
    'CUSTOMERS' => [
        ['/customers', 'Customers', 'customers.view'],
    ],
    'ADMIN' => [
        ['/admin/users', 'Users', 'users.manage'],
        ['/admin/updates', 'Database Updates', 'system.updates'],
    ],
    'ACCOUNT' => [
        ['/account/password', 'Change Password', null],
    ],
];

$renderNav = function () use ($navSections, $user, $appPath): string {
    $html = '';
    foreach ($navSections as $label => $items) {
        $visible = array_filter($items, fn ($i) => $i[2] === null ? (bool) $user : Policy::allows($user, $i[2]));
        if (!$visible) {
            continue;
        }
        $html .= '<div class="section-label">' . e($label) . '</div>';
        foreach ($visible as [$href, $text]) {
            $active = $appPath === $href || str_starts_with($appPath, $href . '/');
            $html .= '<a class="nav-item' . ($active ? ' active' : '') . '" href="' . e(url($href)) . '"' . ($active ? ' aria-current="page"' : '') . '>' . e($text) . '</a>';
        }
    }
    return $html;
};
$pendingUpdates = Policy::allows($user, 'system.updates')
    ? (new \App\Services\Migrator(\App\Helpers\Database::connection()))->pending()
    : [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= isset($pageTitle) ? View::e($pageTitle) . ' — ' : '' ?>LP System</title>
<link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand">
      <span class="logo">L</span>
      <span class="name"><?= View::e(app_config()['app']['name'] ?? 'LP System') ?></span>
    </div>
    <nav aria-label="Main"><?= $renderNav() ?></nav>
  </aside>

  <div class="mobile-nav-drawer">
    <div class="backdrop"></div>
    <div class="panel"><nav aria-label="Main (mobile)"><?= $renderNav() ?></nav></div>
  </div>

  <div class="main">
    <header class="topbar">
      <button type="button" class="mobile-nav-toggle" aria-label="Open menu">☰</button>
      <div style="flex:1"></div>
      <?php if ($user): ?>
        <div class="user"><strong><?= View::e($user['name']) ?></strong><?= View::e($user['role']) ?></div>
        <form method="post" action="<?= e(url('/logout')) ?>" style="margin:0">
          <?= Csrf::field() ?>
          <button type="submit" class="btn btn-outline btn-sm">Sign out</button>
        </form>
      <?php endif; ?>
    </header>
    <main class="content">
      <?php if ($pendingUpdates): ?>
        <div class="alert alert-warning">This installation has <?= count($pendingUpdates) ?> pending database update(s). <a href="<?= e(url('/admin/updates')) ?>"><strong>Apply them now</strong></a> — some features may fail until you do.</div>
      <?php endif; ?>
      <?php foreach (View::pullFlashes() as $flash): ?>
        <div class="alert alert-<?= View::e($flash['type']) ?>" role="status"><?= View::e($flash['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?? '' ?>
    </main>
  </div>
</div>
<script src="<?= e(url('/assets/js/app.js')) ?>"></script>
</body>
</html>
