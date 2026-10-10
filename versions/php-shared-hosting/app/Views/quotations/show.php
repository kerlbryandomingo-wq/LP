<?php use App\Helpers\Csrf; use App\Helpers\View; ?>
<div class="flex items-center justify-between">
  <div>
    <h1 class="page-title mb-0"><?= View::e($quotation['quote_number']) ?></h1>
    <p class="page-subtitle"><?= View::e($quotation['customer_name']) ?> · <span class="badge badge-blue"><?= View::e($quotation['status']) ?></span></p>
  </div>
  <div class="flex gap-2">
    <?php if ($quotation['status'] === 'DRAFT'): ?>
      <form method="post" action="/quotations/<?= View::e($quotation['id']) ?>/send"><?= Csrf::field() ?><button class="btn btn-outline">Send for Approval</button></form>
    <?php endif; ?>
    <?php if (in_array($quotation['status'], ['DRAFT', 'SENT'], true)): ?>
      <form method="post" action="/quotations/<?= View::e($quotation['id']) ?>/approve"><?= Csrf::field() ?><button class="btn btn-success">Approve</button></form>
      <form method="post" action="/quotations/<?= View::e($quotation['id']) ?>/reject"><?= Csrf::field() ?><button class="btn btn-danger">Reject</button></form>
    <?php endif; ?>
    <?php if ($quotation['status'] === 'APPROVED'): ?>
      <form method="post" action="/quotations/<?= View::e($quotation['id']) ?>/convert"><?= Csrf::field() ?><button class="btn btn-primary">Convert to Order</button></form>
    <?php endif; ?>
  </div>
</div>

<div class="card"><div class="card-body table-wrap">
  <table class="data-table">
    <thead><tr><th>Description</th><th class="num">Qty</th><th>Unit</th><th class="num">Unit Price</th><th class="num">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($quotation['line_items'] as $li): ?>
      <tr>
        <td><?= View::e($li['description']) ?></td>
        <td class="num"><?= (int) $li['qty'] ?></td>
        <td><?= View::e($li['unit'] ?? '—') ?></td>
        <td class="num"><?= View::money($li['unit_price']) ?></td>
        <td class="num"><?= View::money($li['qty'] * $li['unit_price']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>

<div class="card"><div class="card-body" style="max-width:360px;margin-left:auto">
  <div class="flex justify-between"><span>Subtotal</span><span><?= View::money($quotation['subtotal']) ?></span></div>
  <?php if ($quotation['discount_amount'] > 0): ?>
    <div class="flex justify-between"><span><?= View::e($quotation['discount_label'] ?? 'Discount') ?></span><span>-<?= View::money($quotation['discount_amount']) ?></span></div>
  <?php endif; ?>
  <?php if ($quotation['tax_amount'] > 0): ?>
    <div class="flex justify-between"><span>Tax (<?= View::e((string) $quotation['tax_pct']) ?>%)</span><span><?= View::money($quotation['tax_amount']) ?></span></div>
  <?php endif; ?>
  <div class="flex justify-between" style="font-size:18px;font-weight:700;margin-top:8px"><span>Grand Total</span><span><?= View::money($quotation['total']) ?></span></div>
</div></div>

<?php if ($quotation['notes']): ?>
<div class="card"><div class="card-header">Notes</div><div class="card-body"><?= View::e($quotation['notes']) ?></div></div>
<?php endif; ?>
