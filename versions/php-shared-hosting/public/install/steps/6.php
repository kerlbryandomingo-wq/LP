<?php
$old = $old ?? [];
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$host = preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $_SERVER['HTTP_HOST'] ?? '') ? $_SERVER['HTTP_HOST'] : '';
$detected = $host !== '' ? ($https ? 'https://' : 'http://') . $host . base_path() : '';
$tz = is_string($old['timezone'] ?? null) ? $old['timezone'] : 'Asia/Manila';
?>
<h2 class="step-title">Site Settings</h2>
<form method="post" action="<?= e(url('/install/')) ?>" data-once>
  <input type="hidden" name="_token" value="<?= e($token) ?>">
  <input type="hidden" name="_step" value="6">
  <div class="field"><label class="field-label" for="business_name">Business Name</label><input type="text" id="business_name" name="business_name" maxlength="100" required value="<?= e(is_string($old['business_name'] ?? null) ? $old['business_name'] : 'LP System') ?>"></div>
  <div class="field"><label class="field-label" for="app_url">Site Address</label>
    <input type="url" id="app_url" name="app_url" value="<?= e(is_string($old['app_url'] ?? null) ? $old['app_url'] : $detected) ?>">
    <p class="help-text">Detected automatically. Links inside the app work without it; it is kept for reference.</p></div>
  <div class="field"><label class="field-label" for="timezone">Timezone</label>
    <select id="timezone" name="timezone">
      <?php foreach (DateTimeZone::listIdentifiers() as $id): ?><option value="<?= e($id) ?>" <?= $id === $tz ? 'selected' : '' ?>><?= e($id) ?></option><?php endforeach; ?>
    </select></div>
  <button type="submit" class="btn btn-primary">Finish Installation</button>
</form>
