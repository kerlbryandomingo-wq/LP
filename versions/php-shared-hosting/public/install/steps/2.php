<?php
$checks = [];
$checks[] = ['label' => 'PHP version >= 8.0', 'ok' => version_compare(PHP_VERSION, '8.0.0', '>='), 'detail' => PHP_VERSION];
foreach (['pdo_mysql', 'mbstring', 'json', 'fileinfo', 'session'] as $ext) {
    $checks[] = ['label' => "PHP extension: {$ext}", 'ok' => extension_loaded($ext), 'detail' => extension_loaded($ext) ? 'loaded' : 'MISSING'];
}
$checks[] = ['label' => 'gd extension (optional, for image handling)', 'ok' => extension_loaded('gd'), 'detail' => extension_loaded('gd') ? 'loaded' : 'not available — image resizing will be skipped', 'optional' => true];

// URL rewriting is required (the root .htaccess routes everything into
// public/). On Apache+mod_php it can be detected directly; on LiteSpeed /
// PHP-FPM it can't, but reaching this page through /install/ already proves
// rewriting works there.
if (function_exists('apache_get_modules')) {
    $rewriteOn = in_array('mod_rewrite', apache_get_modules(), true);
    $checks[] = ['label' => 'Apache mod_rewrite', 'ok' => $rewriteOn, 'detail' => $rewriteOn ? 'enabled' : 'NOT enabled — ask your host to enable mod_rewrite / .htaccess overrides'];
}

$root = dirname(__DIR__, 3);
foreach (['config', 'storage', 'storage/logs', 'storage/private-uploads', 'public/uploads'] as $dir) {
    $path = $root . '/' . $dir;
    $writable = is_dir($path) && is_writable($path);
    $checks[] = ['label' => "Writable: {$dir}/", 'ok' => $writable, 'detail' => $writable ? 'writable' : 'NOT writable — set permissions to 755 (or 775 on some hosts)'];
}

$allOk = true;
foreach ($checks as $c) {
    if (!$c['ok'] && empty($c['optional'])) {
        $allOk = false;
    }
}
?>
<h2 style="margin-top:0">Requirements Check</h2>
<table class="data-table">
  <tbody>
  <?php foreach ($checks as $c): ?>
    <tr>
      <td><?= htmlspecialchars($c['label']) ?></td>
      <td><?= $c['ok'] ? '<span class="badge badge-green">OK</span>' : ($c['optional'] ?? false ? '<span class="badge badge-yellow">Optional</span>' : '<span class="badge badge-red">FAIL</span>') ?></td>
      <td class="text-muted"><?= htmlspecialchars($c['detail']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php if (!$allOk): ?>
  <div class="alert alert-error">Some requirements are not met. Fix the items marked FAIL (usually a folder permission — set it to 755 via your host's File Manager) and reload this page.</div>
  <a href="/install/?step=2" class="btn btn-outline">Re-check</a>
<?php else: ?>
  <div class="alert alert-success">All requirements are met.</div>
  <form method="post" action="/install/">
    <input type="hidden" name="_step" value="2">
    <button type="submit" class="btn btn-primary">Continue</button>
  </form>
<?php endif; ?>
