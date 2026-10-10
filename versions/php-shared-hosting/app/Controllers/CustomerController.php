<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Helpers\Database;
use App\Helpers\Id;
use App\Helpers\View;
use App\Services\AuthService;
use App\Services\AuditService;

final class CustomerController
{
    public function index(array $params): void
    {
        $user = AuthService::requireLogin();
        $pdo = Database::connection();
        $search = trim((string) ($_GET['q'] ?? ''));

        if ($search !== '') {
            $stmt = $pdo->prepare(
                "SELECT * FROM customers WHERE name LIKE :q OR display_id LIKE :q OR email LIKE :q OR contact_number LIKE :q ORDER BY created_at DESC LIMIT 100"
            );
            $stmt->execute(['q' => '%' . $search . '%']);
        } else {
            $stmt = $pdo->query('SELECT * FROM customers ORDER BY created_at DESC LIMIT 100');
        }
        $customers = $stmt->fetchAll();

        View::render('customers/index', ['customers' => $customers, 'search' => $search]);
    }

    public function create(array $params): void
    {
        AuthService::requireRole(['ADMIN', 'STAFF']);
        View::render('customers/form', []);
    }

    public function store(array $params): void
    {
        $user = AuthService::requireRole(['ADMIN', 'STAFF']);
        Csrf::requireValid();

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            View::flash('error', 'Customer name is required.');
            View::redirect('/customers/new');
        }

        $pdo = Database::connection();
        $id = Id::generate('cus');
        $displayId = 'CUS-' . substr($id, -6);

        $pdo->prepare(
            'INSERT INTO customers (id, display_id, name, company_name, email, contact_number, address, facebook_url)
             VALUES (:id, :display_id, :name, :company_name, :email, :contact_number, :address, :facebook_url)'
        )->execute([
            'id' => $id,
            'display_id' => $displayId,
            'name' => $name,
            'company_name' => $_POST['company_name'] ?? null,
            'email' => $_POST['email'] ?? null,
            'contact_number' => $_POST['contact_number'] ?? null,
            'address' => $_POST['address'] ?? null,
            'facebook_url' => $_POST['facebook_url'] ?? null,
        ]);

        AuditService::log($user['id'], 'CUSTOMER_CREATED', 'Customer', $id);
        View::flash('success', 'Customer created.');
        View::redirect('/customers/' . $id);
    }

    public function show(array $params): void
    {
        AuthService::requireLogin();
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id');
        $stmt->execute(['id' => $params['id']]);
        $customer = $stmt->fetch();
        if (!$customer) {
            http_response_code(404);
            echo 'Customer not found.';
            return;
        }

        $stmt2 = $pdo->prepare('SELECT * FROM orders WHERE customer_id = :id ORDER BY created_at DESC');
        $stmt2->execute(['id' => $params['id']]);

        View::render('customers/show', ['customer' => $customer, 'orders' => $stmt2->fetchAll()]);
    }
}
