<h2 style="margin-top:0">Site Settings</h2>
<?php if (!empty($error)): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<form method="post" action="/install/">
  <input type="hidden" name="_step" value="6">
  <div class="field"><label class="field-label">Business Name</label><input type="text" name="business_name" value="LP System" required></div>
  <div class="field"><label class="field-label">Site URL</label>
    <input type="text" name="app_url" placeholder="https://yourdomain.com" value="<?= htmlspecialchars((!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '')) ?>">
    <p class="help-text">Leave as detected unless this install will be reached at a different address.</p>
  </div>
  <div class="field"><label class="field-label">Timezone</label>
    <select name="timezone">
      <option value="Asia/Manila" selected>Asia/Manila</option>
      <option value="UTC">UTC</option>
      <?php foreach (['America/New_York','America/Los_Angeles','Europe/London','Asia/Singapore','Asia/Tokyo'] as $tz): ?>
        <option value="<?= $tz ?>"><?= $tz ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn btn-primary">Finish Installation</button>
</form>
