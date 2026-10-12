<?php use App\Controllers\UserController; use App\Helpers\Csrf; use App\Helpers\FormToken; use App\Helpers\View; ?>
<h1 class="page-title">Users</h1>
<p class="page-subtitle">Staff, production and administrator accounts.</p>

<?php if ($temp): ?>
  <div class="alert alert-warning" role="status">
    Temporary password for <strong><?= e($temp['email']) ?></strong>: <code class="temp-password"><?= e($temp['password']) ?></code><br>
    Give it to the user privately. It is shown only once; they must choose their own password when they first sign in.
  </div>
<?php endif; ?>
<?php require __DIR__ . '/../partials/errors.php'; ?>

<div class="card"><div class="card-body table-wrap">
  <table class="data-table">
    <thead><tr><th>Name</th><th>Email</th><th>Role / Status</th><th>Last active</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $self = $u['id'] === $me['id']; ?>
      <tr>
        <td><?= View::e($u['name']) ?><?= $self ? ' <span class="badge badge-slate">you</span>' : '' ?></td>
        <td><?= View::e($u['email']) ?></td>
        <td>
          <?php if ($self): ?>
            <?= View::e(UserController::ROLES[$u['role']] ?? $u['role']) ?> · <?= status_badge('ACTIVE') ?>
          <?php else: ?>
          <form method="post" action="<?= e(url('/admin/users/' . $u['id'])) ?>" class="inline-form">
            <?= Csrf::field() ?>
            <select name="role" aria-label="Role for <?= e($u['email']) ?>">
              <?php foreach (UserController::ROLES as $k => $label): ?><option value="<?= e($k) ?>" <?= $u['role'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
            <select name="active" aria-label="Status for <?= e($u['email']) ?>">
              <option value="1" <?= $u['active'] ? 'selected' : '' ?>>Active</option>
              <option value="0" <?= !$u['active'] ? 'selected' : '' ?>>Deactivated</option>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Save</button>
          </form>
          <?php endif; ?>
          <?php if ($u['must_change_password']): ?><span class="help-text">Must set password at next sign-in</span><?php endif; ?>
        </td>
        <td><?= $u['last_active_at'] ? View::e(date('M j, Y g:i A', strtotime($u['last_active_at']))) : '—' ?></td>
        <td>
          <?php if (!$self): ?>
          <form method="post" action="<?= e(url('/admin/users/' . $u['id'] . '/reset-password')) ?>" data-confirm="Generate a new temporary password for <?= e($u['email']) ?>? Their current password stops working.">
            <?= Csrf::field() ?><button type="submit" class="btn btn-ghost btn-sm">Reset password</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>

<div class="card" style="max-width:560px"><div class="card-header">Add User</div><div class="card-body">
  <form method="post" action="<?= e(url('/admin/users')) ?>" data-once>
    <?= Csrf::field() ?><?= FormToken::field() ?>
    <div class="field"><label class="field-label" for="new-name">Name *</label><input id="new-name" type="text" name="name" required maxlength="255" value="<?= e(old($old, 'name')) ?>"></div>
    <div class="field"><label class="field-label" for="new-email">Email *</label><input id="new-email" type="email" name="email" required maxlength="255" value="<?= e(old($old, 'email')) ?>"></div>
    <div class="field"><label class="field-label" for="new-role">Role *</label>
      <select id="new-role" name="role" required>
        <?php foreach (UserController::ROLES as $k => $label): ?><option value="<?= e($k) ?>" <?= old($old, 'role', 'STAFF') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
      <p class="help-text">Staff: sales, payments, production. Production: job orders and QC only, no prices or payments. Administrator: everything, including users.</p>
    </div>
    <button type="submit" class="btn btn-primary">Create Account</button>
  </form>
</div></div>
