<?php
use App\Helpers\Csrf;
use App\Helpers\FormToken;
/** @var array|null $customer */
$editing = $customer !== null;
$action = $editing ? url('/customers/' . $customer['id']) : url('/customers');
?>
<h1 class="page-title"><?= $editing ? 'Edit Customer' : 'New Customer' ?></h1>
<?php if ($editing): ?><p class="page-subtitle"><?= e($customer['display_id']) ?></p><?php endif; ?>
<?php require __DIR__ . '/../partials/errors.php'; ?>
<div class="card"><div class="card-body">
<form method="post" action="<?= e($action) ?>" data-once>
  <?= Csrf::field() ?>
  <?= $editing ? '' : FormToken::field() ?>
  <div class="field"><label class="field-label" for="name">Name *</label><input type="text" id="name" name="name" required maxlength="255" value="<?= e(old($old, 'name')) ?>"></div>
  <div class="field"><label class="field-label" for="company_name">Company</label><input type="text" id="company_name" name="company_name" maxlength="255" value="<?= e(old($old, 'company_name')) ?>"></div>
  <div class="grid grid-cols-2">
    <div class="field"><label class="field-label" for="email">Email</label><input type="email" id="email" name="email" maxlength="255" value="<?= e(old($old, 'email')) ?>"></div>
    <div class="field"><label class="field-label" for="contact_number">Contact Number</label><input type="text" id="contact_number" name="contact_number" maxlength="50" value="<?= e(old($old, 'contact_number')) ?>"></div>
  </div>
  <div class="field"><label class="field-label" for="address">Address</label><textarea id="address" name="address" rows="2" maxlength="1000"><?= e(old($old, 'address')) ?></textarea></div>
  <div class="field"><label class="field-label" for="facebook_url">Facebook URL</label><input type="url" id="facebook_url" name="facebook_url" maxlength="500" placeholder="https://facebook.com/…" value="<?= e(old($old, 'facebook_url')) ?>"></div>
  <?php if (\App\Services\AuthService::can('orders.approve_terms')): ?>
  <label class="checkbox confirm-box"><input type="checkbox" name="is_qualified_for_terms" value="1" <?= old($old, 'is_qualified_for_terms') === '1' ? 'checked' : '' ?>>
    Qualified for approved payment terms (government / corporate / credit account) — production may start before payment on new orders.</label>
  <?php endif; ?>
  <div class="flex gap-2">
    <a href="<?= e(url($editing ? '/customers/' . $customer['id'] : '/customers')) ?>" class="btn btn-outline">Cancel</a>
    <button type="submit" class="btn btn-primary"><?= $editing ? 'Save Changes' : 'Create Customer' ?></button>
  </div>
</form>
</div></div>
