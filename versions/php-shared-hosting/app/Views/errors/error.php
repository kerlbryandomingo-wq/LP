<?php use App\Helpers\View; ?>
<h1 class="page-title"><?= View::e($title) ?></h1>
<p class="page-subtitle"><?= View::e($message) ?></p>
<a href="<?= e(url('/dashboard')) ?>" class="btn btn-outline">Back to dashboard</a>
