<?php use App\Helpers\Csrf; ?>
<h1 class="page-title">New Customer</h1>
<div class="card"><div class="card-body">
<form method="post" action="/customers">
  <?= Csrf::field() ?>
  <div class="field"><label class="field-label">Name *</label><input type="text" name="name" required></div>
  <div class="field"><label class="field-label">Company</label><input type="text" name="company_name"></div>
  <div class="field"><label class="field-label">Email</label><input type="email" name="email"></div>
  <div class="field"><label class="field-label">Contact Number</label><input type="text" name="contact_number"></div>
  <div class="field"><label class="field-label">Address</label><textarea name="address" rows="2"></textarea></div>
  <div class="field"><label class="field-label">Facebook URL</label><input type="text" name="facebook_url"></div>
  <div class="flex gap-2">
    <a href="/customers" class="btn btn-outline">Cancel</a>
    <button type="submit" class="btn btn-primary">Create Customer</button>
  </div>
</form>
</div></div>
