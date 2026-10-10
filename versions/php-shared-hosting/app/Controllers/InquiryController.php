<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Helpers\Database;
use App\Helpers\Id;
use App\Helpers\View;
use App\Services\AuthService;
use App\Services\AuditService;

final class InquiryController
{
    public function index(array $params): void
    {
        AuthService::requireLogin();
        $pdo = Database::connection();
        $rows = $pdo->query(
            'SELECT i.*, c.name AS customer_name FROM inquiries i JOIN customers c ON c.id = i.customer_id ORDER BY i.created_at DESC LIMIT 100'
        )->fetchAll();
        View::render('inquiries/index', ['inquiries' => $rows]);
    }

    public function create(array $params): void
    {
        AuthService::requireRole(['ADMIN', 'STAFF']);
        $pdo = Database::connection();
        $customers = $pdo->query('SELECT id, name, display_id FROM customers ORDER BY name LIMIT 500')->fetchAll();
        $services = $pdo->query('SELECT id, name FROM services WHERE active = 1 ORDER BY name')->fetchAll();
        View::render('inquiries/form', ['customers' => $customers, 'services' => $services]);
    }

    public function store(array $params): void
    {
        $user = AuthService::requireRole(['ADMIN', 'STAFF']);
        Csrf::requireValid();

        $customerId = (string) ($_POST['customer_id'] ?? '');
        $desiredProduct = trim((string) ($_POST['desired_product'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if ($customerId === '' || $desiredProduct === '' || $description === '') {
            View::flash('error', 'Customer, desired product, and description are required.');
            View::redirect('/inquiries/new');
        }

        $pdo = Database::connection();
        $id = Id::generate('inq');
        $pdo->prepare(
            'INSERT INTO inquiries (id, customer_id, description, desired_product, service_id, rough_qty, rough_qty_unit)
             VALUES (:id, :customer_id, :description, :desired_product, :service_id, :rough_qty, :rough_qty_unit)'
        )->execute([
            'id' => $id,
            'customer_id' => $customerId,
            'description' => $description,
            'desired_product' => $desiredProduct,
            'service_id' => $_POST['service_id'] ?: null,
            'rough_qty' => $_POST['rough_qty'] ?: null,
            'rough_qty_unit' => $_POST['rough_qty_unit'] ?: null,
        ]);

        AuditService::log($user['id'], 'INQUIRY_CREATED', 'Inquiry', $id);
        View::flash('success', 'Inquiry recorded.');
        View::redirect('/inquiries/' . $id);
    }

    public function show(array $params): void
    {
        AuthService::requireLogin();
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT i.*, c.name AS customer_name, c.id AS cid FROM inquiries i JOIN customers c ON c.id = i.customer_id WHERE i.id = :id'
        );
        $stmt->execute(['id' => $params['id']]);
        $inquiry = $stmt->fetch();
        if (!$inquiry) {
            http_response_code(404);
            echo 'Inquiry not found.';
            return;
        }
        View::render('inquiries/show', ['inquiry' => $inquiry]);
    }

    /** Preserves the original's Inquiry -> Quotation carry-over (desiredProduct/serviceId/customer). */
    public function convertToQuotation(array $params): void
    {
        $user = AuthService::requireRole(['ADMIN', 'STAFF']);
        Csrf::requireValid();

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM inquiries WHERE id = :id');
        $stmt->execute(['id' => $params['id']]);
        $inquiry = $stmt->fetch();
        if (!$inquiry) {
            http_response_code(404);
            echo 'Inquiry not found.';
            return;
        }

        $pdo->prepare("UPDATE inquiries SET status = 'QUOTED' WHERE id = :id")->execute(['id' => $inquiry['id']]);
        AuditService::log($user['id'], 'INQUIRY_CONVERTED', 'Inquiry', $inquiry['id']);

        View::redirect('/quotations/new?inquiry_id=' . urlencode($inquiry['id']));
    }
}
