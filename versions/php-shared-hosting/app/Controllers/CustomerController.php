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

final class CustomerController
{
    private const FIELDS = ['name', 'company_name', 'email', 'contact_number', 'address', 'facebook_url', 'is_qualified_for_terms'];

    public function index(array $params): void
    {
        AuthService::authorize('customers.view');
        $pdo = Database::connection();
        $search = Input::query('q');
        $pager = new Paginator();

        $where = '';
        $bind = [];
        if ($search !== '') {
            $where = 'WHERE name LIKE :q1 OR company_name LIKE :q2 OR display_id LIKE :q3 OR email LIKE :q4 OR contact_number LIKE :q5';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $k) {
                $bind[$k] = Input::like($search);
            }
        }
        $count = $pdo->prepare("SELECT COUNT(*) FROM customers {$where}");
        $count->execute($bind);
        $pager->total = (int) $count->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM customers {$where} ORDER BY created_at DESC, id DESC LIMIT {$pager->perPage} OFFSET {$pager->offset()}");
        $stmt->execute($bind);

        View::render('customers/index', ['customers' => $stmt->fetchAll(), 'search' => $search, 'pager' => $pager, 'pageTitle' => 'Customers']);
    }

    public function create(array $params): void
    {
        AuthService::authorize('customers.manage');
        View::render('customers/form', ['customer' => null, 'old' => [], 'errors' => [], 'pageTitle' => 'New Customer']);
    }

    /** @return array{0:array<string,?string>,1:string[]} */
    private function validate(): array
    {
        $errors = [];
        $data = [
            'name' => Input::required('name', 'Customer name', $errors),
            'company_name' => Input::strOrNull('company_name'),
            'email' => Input::email('email', 'Email', $errors),
            'contact_number' => Input::strOrNull('contact_number', 50),
            'address' => Input::strOrNull('address', 1000),
            'facebook_url' => Input::strOrNull('facebook_url', 500),
        ];
        if ($data['contact_number'] !== null && !preg_match('/^[0-9+()\-\s]{5,50}$/', $data['contact_number'])) {
            $errors[] = 'Contact number may only contain digits, spaces, +, -, and parentheses.';
        }
        if ($data['facebook_url'] !== null && !preg_match('#^https?://#i', $data['facebook_url'])) {
            $errors[] = 'Facebook URL must start with http:// or https://.';
        }
        // Only administrators decide who gets approved payment terms.
        $data['is_qualified_for_terms'] = AuthService::can('orders.approve_terms') ? (Input::str('is_qualified_for_terms') === '1' ? 1 : 0) : null;
        return [$data, $errors];
    }

    public function store(array $params): void
    {
        $user = AuthService::authorize('customers.manage');
        Csrf::requireValid();
        [$data, $errors] = $this->validate();
        if ($errors) {
            View::render('customers/form', ['customer' => null, 'old' => $_POST, 'errors' => $errors, 'pageTitle' => 'New Customer'], 422);
            return;
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            if (!FormToken::consume($pdo, $user['id'])) {
                $pdo->rollBack();
                View::flash('info', 'This form was already submitted — the customer was not created twice.');
                View::redirect('/customers');
            }
            $id = Id::generate('cus');
            $data['is_qualified_for_terms'] ??= 0;
            $data['id'] = $id;
            $data['display_id'] = NumberSequenceService::next('CUS', 'CUS');
            $pdo->prepare(
                'INSERT INTO customers (id, display_id, name, company_name, email, contact_number, address, facebook_url, is_qualified_for_terms)
                 VALUES (:id, :display_id, :name, :company_name, :email, :contact_number, :address, :facebook_url, :is_qualified_for_terms)'
            )->execute($data);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        AuditService::log($user['id'], 'CUSTOMER_CREATED', 'Customer', $id);
        View::flash('success', "Customer {$data['display_id']} created.");
        View::redirect('/customers/' . $id);
    }

    public function show(array $params): void
    {
        AuthService::authorize('customers.view');
        $customer = View::findOr404('SELECT * FROM customers WHERE id = :id', ['id' => $params['id']], 'Customer');
        $pdo = Database::connection();
        $q = fn (string $sql) => (function () use ($pdo, $sql, $params) {
            $s = $pdo->prepare($sql);
            $s->execute(['id' => $params['id']]);
            return $s->fetchAll();
        })();

        View::render('customers/show', [
            'customer' => $customer,
            'orders' => $q('SELECT * FROM orders WHERE customer_id = :id ORDER BY created_at DESC LIMIT 50'),
            'quotations' => $q('SELECT * FROM quotations WHERE customer_id = :id ORDER BY created_at DESC LIMIT 50'),
            'inquiries' => $q('SELECT * FROM inquiries WHERE customer_id = :id ORDER BY created_at DESC LIMIT 50'),
            'pageTitle' => $customer['name'],
        ]);
    }

    public function edit(array $params): void
    {
        AuthService::authorize('customers.manage');
        $customer = View::findOr404('SELECT * FROM customers WHERE id = :id', ['id' => $params['id']], 'Customer');
        View::render('customers/form', ['customer' => $customer, 'old' => $customer, 'errors' => [], 'pageTitle' => 'Edit Customer']);
    }

    public function update(array $params): void
    {
        $user = AuthService::authorize('customers.manage');
        Csrf::requireValid();
        $customer = View::findOr404('SELECT * FROM customers WHERE id = :id', ['id' => $params['id']], 'Customer');
        [$data, $errors] = $this->validate();
        if ($errors) {
            View::render('customers/form', ['customer' => $customer, 'old' => $_POST, 'errors' => $errors, 'pageTitle' => 'Edit Customer'], 422);
            return;
        }
        $data['is_qualified_for_terms'] ??= (int) $customer['is_qualified_for_terms'];
        $data['id'] = $customer['id'];
        Database::connection()->prepare(
            'UPDATE customers SET name = :name, company_name = :company_name, email = :email, contact_number = :contact_number,
             address = :address, facebook_url = :facebook_url, is_qualified_for_terms = :is_qualified_for_terms WHERE id = :id'
        )->execute($data);

        $changed = array_values(array_filter(self::FIELDS, fn ($f) => (string) ($customer[$f] ?? '') !== (string) ($data[$f] ?? '')));
        AuditService::log($user['id'], 'CUSTOMER_UPDATED', 'Customer', $customer['id'], ['fields' => $changed]);
        View::flash('success', 'Customer details saved.');
        View::redirect('/customers/' . $customer['id']);
    }
}
