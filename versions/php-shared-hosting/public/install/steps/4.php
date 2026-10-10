<h2 style="margin-top:0">Create Database Tables</h2>
<?php if (!empty($error)): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<p>This will create the application's tables in the database you just connected to
(if they don't already exist) and load default starter data (a standard production
workflow template). No existing data is ever deleted.</p>
<form method="post" action="/install/">
  <input type="hidden" name="_step" value="4">
  <button type="submit" class="btn btn-primary">Create Tables</button>
</form>
