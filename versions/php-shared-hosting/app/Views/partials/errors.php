<?php /** @var string[] $errors */ if (!empty($errors)): ?>
<div class="alert alert-error" role="alert">
  <strong>Please fix the following:</strong>
  <ul style="margin:6px 0 0 18px;padding:0">
    <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
