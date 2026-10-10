<?php use App\Helpers\Csrf; use App\Helpers\View; ?>
<h1 class="page-title">Inquiry — <?= View::e($inquiry['customer_name']) ?></h1>
<p class="page-subtitle"><span class="badge badge-slate"><?= View::e($inquiry['status']) ?></span></p>

<div class="card"><div class="card-body">
  <p><span class="text-muted">Desired Product:</span> <?= View::e($inquiry['desired_product']) ?></p>
  <p><span class="text-muted">Description:</span> <?= View::e($inquiry['description']) ?></p>
  <?php if ($inquiry['rough_qty']): ?>
    <p><span class="text-muted">Rough Quantity:</span> <?= (int) $inquiry['rough_qty'] ?> <?= View::e($inquiry['rough_qty_unit'] ?? '') ?></p>
  <?php endif; ?>
</div></div>

<?php if ($inquiry['status'] === 'NEW'): ?>
<form method="post" action="/inquiries/<?= View::e($inquiry['id']) ?>/convert">
  <?= Csrf::field() ?>
  <button type="submit" class="btn btn-primary">Convert to Quotation</button>
</form>
<?php endif; ?>
