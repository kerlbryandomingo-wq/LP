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

final class InquiryController
{
    public const STATUSES = ['NEW', 'QUOTED', 'CLOSED', 'CANCELLED'];

    public function index(array $params): void
    {
        AuthService::authorize('inquiries.view');
        $pdo = Database::connection();
        $search = Input::query('q');
        $status = Input::query('status');
        $pager = new Paginator();

        $conds = [];
        $bind = [];
        if (in_array($status, self::STATUSES, true)) {
            $conds[] = 'i.status = :status';
            $bind['status'] = $status;
        } else {
            $status = '';
        }
        if ($search !== '') {
            $conds[] = '(c.name LIKE :q1 OR i.desired_product LIKE :q2 OR i.description LIKE :q3)';
            $bind += ['q1' => Input::like($search), 'q2' => Input::like($search), 'q3' => Input::like($search)];
        }
        $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
        $from = "FROM inquiries i JOIN customers c ON c.id = i.customer_id {$where}";

        $count = $pdo->prepare("SELECT COUNT(*) {$from}");
        $count->execute($bind);
        $pager->total = (int) $count->fetchColumn();
        $stmt = $pdo->prepare("SELECT i.*, c.name AS customer_name {$from} ORDER BY i.created_at DESC, i.id DESC LIMIT {$pager->perPage} OFFSET {$pager->offset()}");
        $stmt->execute($bind);

        View::render('inquiries/index', [
            'inquiries' => $stmt->fetchAll(), 'search' => $search, 'status' => $status, 'pager' => $pager, 'pageTitle' => 'Inquiries',
        ]);
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
        AuthService::authorize('inquiries.manage');
        View::render('inquiries/form', $this->formData() + [
            'old' => ['customer_id' => Input::query('customer_id', 40)], 'errors' => [], 'pageTitle' => 'New Inquiry',
        ]);
    }

    public function store(array $params): void
    {
        $user = AuthService::authorize('inquiries.manage');
        Csrf::requireValid();
        $pdo = Database::connection();

        $errors = [];
        $customerId = Input::required('customer_id', 'Customer', $errors, 40);
        $desiredProduct = Input::required('desired_product', 'Desired product', $errors);
        $description = Input::required('description', 'Description', $errors, 5000);
        $serviceId = Input::strOrNull('service_id', 40);
        $roughQty = Input::int('rough_qty', 'Rough quantity', $errors, 1, 10000000);
        $roughUnit = Input::strOrNull('rough_qty_unit', 50);

        if ($customerId !== '' && !$this->exists('customers', $customerId)) {
            $errors[] = 'The selected customer does not exist.';
        }
        if ($serviceId !== null && !$this->exists('services', $serviceId)) {
            $errors[] = 'The selected service does not exist.';
        }
        if ($errors) {
            View::render('inquiries/form', $this->formData() + ['old' => $_POST, 'errors' => $errors, 'pageTitle' => 'New Inquiry'], 422);
            return;
        }

        $pdo->beginTransaction();
        try {
            if (!FormToken::consume($pdo, $user['id'])) {
                $pdo->rollBack();
                View::flash('info', 'This form was already submitted — the inquiry was not recorded twice.');
                View::redirect('/inquiries');
            }
            $id = Id::generate('inq');
            $pdo->prepare(
                'INSERT INTO inquiries (id, customer_id, description, desired_product, service_id, rough_qty, rough_qty_unit)
                 VALUES (:id, :customer_id, :description, :desired_product, :service_id, :rough_qty, :rough_qty_unit)'
            )->execute([
                'id' => $id, 'customer_id' => $customerId, 'description' => $description, 'desired_product' => $desiredProduct,
                'service_id' => $serviceId, 'rough_qty' => $roughQty, 'rough_qty_unit' => $roughUnit,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        AuditService::log($user['id'], 'INQUIRY_CREATED', 'Inquiry', $id);
        View::flash('success', 'Inquiry recorded.');
        View::redirect('/inquiries/' . $id);
    }

    private function exists(string $table, string $id): bool
    {
        // $table is always one of our own literal table names, never user input.
        $stmt = Database::connection()->prepare("SELECT 1 FROM {$table} WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return (bool) $stmt->fetchColumn();
    }

    public function show(array $params): void
    {
        AuthService::authorize('inquiries.view');
        $inquiry = View::findOr404(
            'SELECT i.*, c.name AS customer_name, s.name AS service_name FROM inquiries i
             JOIN customers c ON c.id = i.customer_id LEFT JOIN services s ON s.id = i.service_id WHERE i.id = :id',
            ['id' => $params['id']],
            'Inquiry'
        );
        $stmt = Database::connection()->prepare('SELECT id, quote_number, status, total FROM quotations WHERE inquiry_id = :id ORDER BY created_at DESC');
        $stmt->execute(['id' => $inquiry['id']]);
        View::render('inquiries/show', ['inquiry' => $inquiry, 'quotations' => $stmt->fetchAll(), 'pageTitle' => 'Inquiry']);
    }

    /**
     * "Convert to Quotation" opens a prefilled quotation form. The inquiry
     * is marked QUOTED only when that quotation is actually saved
     * (QuotationController::store), so abandoning the form leaves it NEW.
     */
    public function convertToQuotation(array $params): void
    {
        AuthService::authorize('quotations.manage');
        Csrf::requireValid();
        $inquiry = View::findOr404('SELECT id, status FROM inquiries WHERE id = :id', ['id' => $params['id']], 'Inquiry');
        if ($inquiry['status'] !== 'NEW') {
            View::flash('error', 'Only a new inquiry can be converted to a quotation.');
            View::redirect('/inquiries/' . $inquiry['id']);
        }
        View::redirect('/quotations/new?inquiry_id=' . urlencode($inquiry['id']));
    }

    public function cancel(array $params): void
    {
        $user = AuthService::authorize('inquiries.manage');
        Csrf::requireValid();
        $errors = [];
        $reason = Input::required('reason', 'Reason', $errors, 1000);
        if ($errors) {
            View::flash('error', 'Enter a reason for cancelling the inquiry.');
            View::redirect('/inquiries/' . $params['id']);
        }
        $stmt = Database::connection()->prepare(
            "UPDATE inquiries SET status_before_cancel = status, status = 'CANCELLED', cancelled_at = NOW(), cancelled_by_id = :uid, cancel_reason = :reason
             WHERE id = :id AND status = 'NEW'"
        );
        $stmt->execute(['id' => $params['id'], 'uid' => $user['id'], 'reason' => $reason]);
        if ($stmt->rowCount() === 1) {
            AuditService::log($user['id'], 'INQUIRY_CANCELLED', 'Inquiry', $params['id'], ['reason' => $reason]);
            View::flash('success', 'Inquiry cancelled.');
        } else {
            View::flash('error', 'Only a new inquiry can be cancelled.');
        }
        View::redirect('/inquiries/' . $params['id']);
    }
}
