<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Helpers\Database;
use App\Helpers\FormToken;
use App\Helpers\Id;
use App\Helpers\Input;
use App\Helpers\Paginator;
use App\Helpers\View;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\NumberSequenceService;
use App\Services\PricingService;
use App\Services\QuotationService;

final class QuotationController
{
    public const STATUSES = ['DRAFT', 'SENT', 'APPROVED', 'REJECTED', 'REVISION_REQUESTED', 'CANCELLED'];
    private const MAX_TOTAL = 9999999999.99; // DECIMAL(12,2)

    public function index(array $params): void
    {
        AuthService::authorize('quotations.view');
        $pdo = Database::connection();
        $search = Input::query('q');
        $status = Input::query('status');
        $pager = new Paginator();

        $conds = [];
        $bind = [];
        if (in_array($status, self::STATUSES, true)) {
            $conds[] = 'q.status = :status';
            $bind['status'] = $status;
        } else {
            $status = '';
        }
        if ($search !== '') {
            $conds[] = '(q.quote_number LIKE :q1 OR c.name LIKE :q2)';
            $bind += ['q1' => Input::like($search), 'q2' => Input::like($search)];
        }
        $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
        $from = "FROM quotations q JOIN customers c ON c.id = q.customer_id {$where}";
        $count = $pdo->prepare("SELECT COUNT(*) {$from}");
        $count->execute($bind);
        $pager->total = (int) $count->fetchColumn();
        $stmt = $pdo->prepare("SELECT q.*, c.name AS customer_name {$from} ORDER BY q.created_at DESC, q.id DESC LIMIT {$pager->perPage} OFFSET {$pager->offset()}");
        $stmt->execute($bind);

        View::render('quotations/index', ['quotations' => $stmt->fetchAll(), 'search' => $search, 'status' => $status, 'pager' => $pager, 'pageTitle' => 'Quotations']);
    }

    private function formData(): array
    {
        $pdo = Database::connection();
        return [
            'customers' => $pdo->query('SELECT id, name, display_id FROM customers ORDER BY name LIMIT 1000')->fetchAll(),
            'services' => $pdo->query('SELECT id, name FROM services WHERE active = 1 ORDER BY name')->fetchAll(),
        ];
    }

    public function create(array $params): void
    {
        AuthService::authorize('quotations.manage');
        $pdo = Database::connection();
        $old = ['customer_id' => Input::query('customer_id', 40), 'discount_type' => 'PERCENTAGE', 'discount_value' => '0', 'tax_pct' => '0'];
        $lines = [['description' => '', 'qty' => '1', 'unit' => '', 'unit_price' => '', 'service_id' => '']];
        $inquiryId = Input::query('inquiry_id', 40);
        if ($inquiryId !== '') {
            $stmt = $pdo->prepare("SELECT * FROM inquiries WHERE id = :id AND status = 'NEW'");
            $stmt->execute(['id' => $inquiryId]);
            if ($inq = $stmt->fetch()) {
                $old['inquiry_id'] = $inq['id'];
                $old['customer_id'] = $inq['customer_id'];
                $lines = [[
                    'description' => $inq['desired_product'], 'qty' => (string) ($inq['rough_qty'] ?? 1), 'unit' => $inq['rough_qty_unit'] ?? '',
                    'unit_price' => '', 'service_id' => $inq['service_id'] ?? '',
                ]];
            }
        }
        View::render('quotations/form', $this->formData() + [
            'quotation' => null, 'old' => $old, 'lines' => $lines, 'errors' => [], 'pageTitle' => 'New Quotation',
        ]);
    }

    /**
     * Reads and validates the line-item rows. Blank rows are ignored.
     * @return array<int, array{description:string, service_id:?string, qty:int, unit:?string, unit_price:string}>
     */
    private function parseLineItems(array &$errors, array $serviceIds): array
    {
        $col = function (string $k): array {
            $v = $_POST[$k] ?? [];
            return is_array($v) ? array_values($v) : [];
        };
        $descriptions = $col('description');
        $services = $col('service_id');
        $qtys = $col('qty');
        $units = $col('unit');
        $prices = $col('unit_price');

        $items = [];
        foreach ($descriptions as $i => $desc) {
            $desc = is_string($desc) ? trim($desc) : '';
            $qtyRaw = is_string($qtys[$i] ?? null) ? trim($qtys[$i]) : '';
            $priceRaw = is_string($prices[$i] ?? null) ? trim(str_replace(',', '', $prices[$i])) : '';
            if ($desc === '' && $priceRaw === '') {
                continue;
            }
            $row = $i + 1;
            if ($desc === '') {
                $errors[] = "Line {$row}: description is required.";
            }
            if (!preg_match('/^\d{1,7}$/', $qtyRaw) || (int) $qtyRaw < 1) {
                $errors[] = "Line {$row}: quantity must be a whole number of at least 1.";
            }
            if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $priceRaw)) {
                $errors[] = "Line {$row}: unit price must be an amount like 500 or 499.50.";
            }
            $serviceId = is_string($services[$i] ?? null) && $services[$i] !== '' ? $services[$i] : null;
            if ($serviceId !== null && !in_array($serviceId, $serviceIds, true)) {
                $errors[] = "Line {$row}: the selected service does not exist.";
            }
            $items[] = [
                'description' => mb_substr($desc, 0, 2000),
                'service_id' => $serviceId,
                'qty' => (int) $qtyRaw,
                'unit' => (is_string($units[$i] ?? null) && trim($units[$i]) !== '') ? mb_substr(trim($units[$i]), 0, 50) : null,
                'unit_price' => preg_match('/^\d{1,10}(\.\d{1,2})?$/', $priceRaw) ? number_format((float) $priceRaw, 2, '.', '') : '0.00',
            ];
        }
        if (!$items) {
            $errors[] = 'Add at least one line item.';
        }
        if (count($items) > 100) {
            $errors[] = 'A quotation can have at most 100 line items.';
        }
        return $items;
    }

    /** @return array{0:array,1:array,2:array} [header fields, line items, totals] — validation problems appended to $errors */
    private function validate(array &$errors): array
    {
        $pdo = Database::connection();
        $serviceIds = $pdo->query('SELECT id FROM services')->fetchAll(\PDO::FETCH_COLUMN);
        $header = [
            'customer_id' => Input::required('customer_id', 'Customer', $errors, 40),
            'valid_until' => Input::date('valid_until', 'Valid until', $errors),
            'notes' => Input::strOrNull('notes', 5000),
            'discount_type' => Input::enum('discount_type', 'Discount type', ['PERCENTAGE', 'FIXED'], $errors, 'PERCENTAGE'),
            'discount_value' => Input::money('discount_value', 'Discount', $errors, 0, false) ?? '0.00',
            'tax_pct' => Input::money('tax_pct', 'Tax / VAT %', $errors, 0, false) ?? '0.00',
        ];
        if ($header['customer_id'] !== '') {
            $s = $pdo->prepare('SELECT 1 FROM customers WHERE id = :id');
            $s->execute(['id' => $header['customer_id']]);
            if (!$s->fetchColumn()) {
                $errors[] = 'The selected customer does not exist.';
            }
        }
        if ((float) $header['tax_pct'] > 100) {
            $errors[] = 'Tax / VAT % cannot be more than 100.';
        }
        if ($header['discount_type'] === 'PERCENTAGE' && (float) $header['discount_value'] > 100) {
            $errors[] = 'A percentage discount cannot be more than 100%.';
        }
        $items = $this->parseLineItems($errors, $serviceIds);

        // The server computes every total itself from the submitted line
        // items — client-side figures are only a preview.
        $subtotalCents = 0;
        foreach ($items as $item) {
            $subtotalCents += $item['qty'] * (int) round((float) $item['unit_price'] * 100);
        }
        $totals = PricingService::computeTotals([
            'subtotal' => $subtotalCents / 100,
            'discountType' => $header['discount_type'],
            'discountValue' => $header['discount_value'],
            'taxPct' => $header['tax_pct'],
        ]);
        if ($header['discount_type'] === 'FIXED' && (float) $header['discount_value'] > $totals['subtotal'] && $items) {
            $errors[] = 'The fixed discount cannot be larger than the subtotal (' . number_format($totals['subtotal'], 2) . ').';
        }
        if ($totals['subtotal'] > self::MAX_TOTAL || $totals['total'] > self::MAX_TOTAL) {
            $errors[] = 'The quotation total is too large.';
        }
        return [$header, $items, $totals];
    }

    private function linesFromPost(): array
    {
        $out = [];
        foreach ((array) ($_POST['description'] ?? []) as $i => $d) {
            $out[] = [
                'description' => is_string($d) ? $d : '',
                'qty' => is_string($_POST['qty'][$i] ?? null) ? $_POST['qty'][$i] : '1',
                'unit' => is_string($_POST['unit'][$i] ?? null) ? $_POST['unit'][$i] : '',
                'unit_price' => is_string($_POST['unit_price'][$i] ?? null) ? $_POST['unit_price'][$i] : '',
                'service_id' => is_string($_POST['service_id'][$i] ?? null) ? $_POST['service_id'][$i] : '',
            ];
        }
        return $out ?: [['description' => '', 'qty' => '1', 'unit' => '', 'unit_price' => '', 'service_id' => '']];
    }

    private function insertLines(\PDO $pdo, string $quotationId, array $items): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO quotation_line_items (id, quotation_id, product_type, service_id, description, qty, unit, unit_price, sort_order)
             VALUES (:id, :quotation_id, :product_type, :service_id, :description, :qty, :unit, :unit_price, :sort_order)'
        );
        foreach ($items as $i => $item) {
            $stmt->execute([
                'id' => Id::generate('qli'), 'quotation_id' => $quotationId, 'product_type' => mb_substr($item['description'], 0, 255),
                'service_id' => $item['service_id'], 'description' => $item['description'], 'qty' => $item['qty'],
                'unit' => $item['unit'], 'unit_price' => $item['unit_price'], 'sort_order' => $i,
            ]);
        }
    }

    public function store(array $params): void
    {
        $user = AuthService::authorize('quotations.manage');
        Csrf::requireValid();
        $pdo = Database::connection();
        $errors = [];
        [$header, $items, $totals] = $this->validate($errors);
        $inquiryId = Input::strOrNull('inquiry_id', 40);

        $inquiry = null;
        if ($inquiryId !== null) {
            $s = $pdo->prepare('SELECT * FROM inquiries WHERE id = :id');
            $s->execute(['id' => $inquiryId]);
            $inquiry = $s->fetch();
            if (!$inquiry) {
                $errors[] = 'The linked inquiry does not exist.';
            } elseif ($inquiry['customer_id'] !== $header['customer_id']) {
                $errors[] = 'The quotation customer must match the inquiry\'s customer.';
            }
        }
        if ($errors) {
            View::render('quotations/form', $this->formData() + [
                'quotation' => null, 'old' => $_POST, 'lines' => $this->linesFromPost(), 'errors' => $errors, 'pageTitle' => 'New Quotation',
            ], 422);
            return;
        }

        $pdo->beginTransaction();
        try {
            if (!FormToken::consume($pdo, $user['id'])) {
                $pdo->rollBack();
                View::flash('info', 'This form was already submitted — the quotation was not created twice.');
                View::redirect('/quotations');
            }
            if ($inquiry) {
                // Same rule as the original: one live quotation per inquiry.
                $lock = $pdo->prepare('SELECT status FROM inquiries WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $inquiry['id']]);
                $active = $pdo->prepare("SELECT quote_number FROM quotations WHERE inquiry_id = :id AND status IN ('DRAFT','SENT','REVISION_REQUESTED','APPROVED') LIMIT 1");
                $active->execute(['id' => $inquiry['id']]);
                if ($existing = $active->fetchColumn()) {
                    $pdo->rollBack();
                    View::flash('error', "This inquiry already has an active quotation ({$existing}).");
                    View::redirect('/inquiries/' . $inquiry['id']);
                }
            }
            $id = Id::generate('quo');
            $quoteNumber = NumberSequenceService::next('QUO', 'QUO');
            $pdo->prepare(
                'INSERT INTO quotations (id, inquiry_id, customer_id, quote_number, status, valid_until, subtotal, discount_type, discount_value, discount_amount,
                    discount_label, tax_pct, tax_amount, total, notes, created_by_id)
                 VALUES (:id, :inquiry_id, :customer_id, :quote_number, \'DRAFT\', :valid_until, :subtotal, :discount_type, :discount_value, :discount_amount,
                    :discount_label, :tax_pct, :tax_amount, :total, :notes, :created_by_id)'
            )->execute([
                'id' => $id, 'inquiry_id' => $inquiry['id'] ?? null, 'customer_id' => $header['customer_id'], 'quote_number' => $quoteNumber,
                'valid_until' => $header['valid_until'], 'subtotal' => $totals['subtotal'], 'discount_type' => $totals['discountType'],
                'discount_value' => $totals['discountValue'], 'discount_amount' => $totals['discountAmount'], 'discount_label' => $totals['discountLabel'],
                'tax_pct' => $totals['taxPct'], 'tax_amount' => $totals['taxAmount'], 'total' => $totals['total'], 'notes' => $header['notes'],
                'created_by_id' => $user['id'],
            ]);
            $this->insertLines($pdo, $id, $items);
            if ($inquiry) {
                $pdo->prepare("UPDATE inquiries SET status = 'QUOTED' WHERE id = :id AND status = 'NEW'")->execute(['id' => $inquiry['id']]);
            }
            AuditService::log($user['id'], 'QUOTATION_CREATED', 'Quotation', $id, ['quote_number' => $quoteNumber, 'total' => $totals['total']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        View::flash('success', "Quotation {$quoteNumber} created as a draft.");
        View::redirect('/quotations/' . $id);
    }

    private function load(string $id): array
    {
        $q = View::findOr404(
            'SELECT q.*, c.name AS customer_name, u.name AS approved_by_name FROM quotations q
             JOIN customers c ON c.id = q.customer_id LEFT JOIN users u ON u.id = q.approved_by_id WHERE q.id = :id',
            ['id' => $id],
            'Quotation'
        );
        $stmt = Database::connection()->prepare('SELECT * FROM quotation_line_items WHERE quotation_id = :id ORDER BY sort_order ASC, id ASC');
        $stmt->execute(['id' => $id]);
        $q['line_items'] = $stmt->fetchAll();
        return $q;
    }

    public function show(array $params): void
    {
        AuthService::authorize('quotations.view');
        $quotation = $this->load($params['id']);
        $stmt = Database::connection()->prepare('SELECT id, order_number FROM orders WHERE quotation_id = :id');
        $stmt->execute(['id' => $quotation['id']]);
        View::render('quotations/show', ['quotation' => $quotation, 'order' => $stmt->fetch() ?: null, 'pageTitle' => $quotation['quote_number']]);
    }

    public function edit(array $params): void
    {
        AuthService::authorize('quotations.manage');
        $q = $this->load($params['id']);
        if (!in_array($q['status'], QuotationService::EDITABLE, true)) {
            View::flash('error', 'This quotation can no longer be edited.');
            View::redirect('/quotations/' . $q['id']);
        }
        $lines = array_map(fn ($li) => [
            'description' => $li['description'], 'qty' => (string) $li['qty'], 'unit' => $li['unit'] ?? '',
            'unit_price' => $li['unit_price'], 'service_id' => $li['service_id'] ?? '',
        ], $q['line_items']);
        View::render('quotations/form', $this->formData() + [
            'quotation' => $q, 'old' => $q, 'lines' => $lines, 'errors' => [], 'pageTitle' => 'Edit ' . $q['quote_number'],
        ]);
    }

    public function update(array $params): void
    {
        $user = AuthService::authorize('quotations.manage');
        Csrf::requireValid();
        $q = $this->load($params['id']);
        $errors = [];
        [$header, $items, $totals] = $this->validate($errors);
        if ($q['inquiry_id'] && $header['customer_id'] !== $q['customer_id']) {
            $errors[] = 'The customer of a quotation created from an inquiry cannot be changed.';
        }
        if ($errors) {
            View::render('quotations/form', $this->formData() + [
                'quotation' => $q, 'old' => $_POST, 'lines' => $this->linesFromPost(), 'errors' => $errors, 'pageTitle' => 'Edit ' . $q['quote_number'],
            ], 422);
            return;
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $locked = QuotationService::lock($pdo, $q['id']);
            if (!in_array($locked['status'], QuotationService::EDITABLE, true)) {
                $pdo->rollBack();
                View::flash('error', 'This quotation was ' . strtolower($locked['status']) . ' in the meantime and can no longer be edited.');
                View::redirect('/quotations/' . $q['id']);
            }
            $pdo->prepare(
                'UPDATE quotations SET customer_id = :customer_id, valid_until = :valid_until, subtotal = :subtotal, discount_type = :discount_type,
                    discount_value = :discount_value, discount_amount = :discount_amount, discount_label = :discount_label, tax_pct = :tax_pct,
                    tax_amount = :tax_amount, total = :total, notes = :notes WHERE id = :id'
            )->execute([
                'id' => $q['id'], 'customer_id' => $header['customer_id'], 'valid_until' => $header['valid_until'], 'subtotal' => $totals['subtotal'],
                'discount_type' => $totals['discountType'], 'discount_value' => $totals['discountValue'], 'discount_amount' => $totals['discountAmount'],
                'discount_label' => $totals['discountLabel'], 'tax_pct' => $totals['taxPct'], 'tax_amount' => $totals['taxAmount'],
                'total' => $totals['total'], 'notes' => $header['notes'],
            ]);
            $pdo->prepare('DELETE FROM quotation_line_items WHERE quotation_id = :id')->execute(['id' => $q['id']]);
            $this->insertLines($pdo, $q['id'], $items);
            AuditService::log($user['id'], 'QUOTATION_EDITED', 'Quotation', $q['id'], ['old_total' => $q['total'], 'new_total' => $totals['total']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        View::flash('success', 'Quotation updated.');
        View::redirect('/quotations/' . $q['id']);
    }

    /** Guarded single-row status change. Flash reflects what really happened. */
    private function transition(string $id, array $from, string $to, string $audit, array $actor, string $okMessage, array $extraSet = [], array $meta = []): void
    {
        $set = 'status = :to';
        $bind = ['to' => $to, 'id' => $id];
        foreach ($extraSet as $col => $val) {
            $set .= ", {$col} = :x_{$col}";
            $bind["x_{$col}"] = $val;
        }
        $in = [];
        foreach (array_values($from) as $i => $f) {
            $in[] = ":f{$i}";
            $bind["f{$i}"] = $f;
        }
        $stmt = Database::connection()->prepare("UPDATE quotations SET {$set} WHERE id = :id AND status IN (" . implode(',', $in) . ')');
        $stmt->execute($bind);
        if ($stmt->rowCount() === 1) {
            AuditService::log($actor['id'], $audit, 'Quotation', $id, $meta);
            View::flash('success', $okMessage);
        } else {
            View::flash('error', 'That action is not available for this quotation in its current status.');
        }
        View::redirect('/quotations/' . $id);
    }

    public function send(array $params): void
    {
        $user = AuthService::authorize('quotations.manage');
        Csrf::requireValid();
        $this->load($params['id']);
        $this->transition($params['id'], QuotationService::SENDABLE, 'SENT', 'QUOTATION_SENT', $user, 'Quotation marked as sent to the customer.');
    }

    public function approve(array $params): void
    {
        $user = AuthService::authorize('quotations.approve');
        Csrf::requireValid();
        $q = $this->load($params['id']);
        $errors = [];
        $reason = Input::required('reason', 'Approval reason', $errors, 1000);
        if (mb_strlen($reason) < 3) {
            View::flash('error', 'Enter how the customer approved (e.g. "Approved by phone call with customer").');
            View::redirect('/quotations/' . $q['id']);
        }
        try {
            $r = QuotationService::approve($q['id'], $user, $reason);
        } catch (\RuntimeException $e) {
            View::flash('error', $e->getMessage());
            View::redirect('/quotations/' . $q['id']);
        }
        $msg = "Quotation approved. Order {$r['order_number']} was created.";
        if ($r['job_order']) {
            $msg .= " Job order {$r['job_order']['jo_number']} was created automatically.";
        }
        View::flash('success', $msg);
        View::redirect('/orders/' . $r['order_id']);
    }

    public function reject(array $params): void
    {
        $user = AuthService::authorize('quotations.approve');
        Csrf::requireValid();
        $this->load($params['id']);
        $errors = [];
        $reason = Input::required('reason', 'Reason', $errors, 1000);
        if ($errors) {
            View::flash('error', 'Enter the customer\'s reason for rejecting the quotation.');
            View::redirect('/quotations/' . $params['id']);
        }
        $this->transition($params['id'], ['SENT'], 'REJECTED', 'QUOTATION_REJECTED', $user, 'Customer rejection recorded.', ['reject_reason' => $reason], ['reason' => $reason]);
    }

    public function requestRevision(array $params): void
    {
        $user = AuthService::authorize('quotations.approve');
        Csrf::requireValid();
        $this->load($params['id']);
        $errors = [];
        $message = Input::required('message', 'Requested changes', $errors, 2000);
        if ($errors) {
            View::flash('error', 'Describe the changes the customer asked for.');
            View::redirect('/quotations/' . $params['id']);
        }
        $this->transition($params['id'], ['SENT'], 'REVISION_REQUESTED', 'QUOTATION_REVISION_REQUESTED', $user, 'Revision request recorded. Edit the quotation and send it again.', [], ['message' => $message]);
    }

    public function cancel(array $params): void
    {
        $user = AuthService::authorize('quotations.manage');
        Csrf::requireValid();
        $q = $this->load($params['id']);
        $errors = [];
        $reason = Input::required('reason', 'Reason', $errors, 1000);
        if ($errors) {
            View::flash('error', 'Enter a reason for cancelling the quotation.');
            View::redirect('/quotations/' . $q['id']);
        }
        $this->transition($q['id'], QuotationService::CANCELLABLE, 'CANCELLED', 'QUOTATION_CANCELLED', $user, 'Quotation cancelled.', [
            'status_before_cancel' => $q['status'], 'cancelled_by_id' => $user['id'], 'cancel_reason' => $reason, 'cancelled_at' => date('Y-m-d H:i:s'),
        ], ['reason' => $reason, 'previous_status' => $q['status']]);
    }

    public function restore(array $params): void
    {
        $user = AuthService::authorize('quotations.manage');
        Csrf::requireValid();
        $q = $this->load($params['id']);
        $restoreTo = in_array($q['status_before_cancel'], QuotationService::CANCELLABLE, true) ? $q['status_before_cancel'] : 'DRAFT';
        $this->transition($q['id'], ['CANCELLED'], $restoreTo, 'QUOTATION_RESTORED', $user, 'Quotation restored.', [
            'status_before_cancel' => null, 'cancelled_by_id' => null, 'cancel_reason' => null, 'cancelled_at' => null,
        ], ['restored_status' => $restoreTo]);
    }

    /** Only needed for quotations approved before automatic order creation existed; idempotent. */
    public function convertToOrder(array $params): void
    {
        $user = AuthService::authorize('orders.manage');
        Csrf::requireValid();
        $this->load($params['id']);
        try {
            $r = QuotationService::convert($params['id'], $user['id']);
        } catch (\RuntimeException $e) {
            View::flash('error', $e->getMessage());
            View::redirect('/quotations/' . $params['id']);
        }
        View::flash($r['created'] ? 'success' : 'info', $r['created'] ? "Order {$r['order_number']} created." : "This quotation already has order {$r['order_number']}.");
        View::redirect('/orders/' . $r['order_id']);
    }
}
