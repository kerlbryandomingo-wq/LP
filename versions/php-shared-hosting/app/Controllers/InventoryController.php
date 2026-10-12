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

final class InventoryController
{
    public function index(array $params): void
    {
        AuthService::authorize('inventory.view');
        $pdo = Database::connection();
        $search = Input::query('q');
        $lowOnly = Input::query('low') === '1';
        $pager = new Paginator(50);
        $conds = [];
        $bind = [];
        if ($search !== '') {
            $conds[] = '(sku LIKE :q1 OR name LIKE :q2)';
            $bind += ['q1' => Input::like($search), 'q2' => Input::like($search)];
        }
        if ($lowOnly) {
            $conds[] = 'current_qty <= reorder_threshold';
        }
        $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
        $count = $pdo->prepare("SELECT COUNT(*) FROM inventory_items {$where}");
        $count->execute($bind);
        $pager->total = (int) $count->fetchColumn();
        $stmt = $pdo->prepare("SELECT * FROM inventory_items {$where} ORDER BY name ASC LIMIT {$pager->perPage} OFFSET {$pager->offset()}");
        $stmt->execute($bind);
        View::render('inventory/index', ['items' => $stmt->fetchAll(), 'search' => $search, 'lowOnly' => $lowOnly, 'pager' => $pager, 'pageTitle' => 'Inventory']);
    }

    public function create(array $params): void
    {
        AuthService::authorize('inventory.manage');
        View::render('inventory/form', ['item' => null, 'old' => ['reorder_threshold' => '0', 'current_qty' => '0'], 'errors' => [], 'pageTitle' => 'New Inventory Item']);
    }

    private function validate(?string $exceptId): array
    {
        $errors = [];
        $data = [
            'sku' => strtoupper(Input::required('sku', 'SKU', $errors, 100)),
            'name' => Input::required('name', 'Name', $errors),
            'unit' => Input::required('unit', 'Unit', $errors, 50),
            'reorder_threshold' => Input::int('reorder_threshold', 'Reorder threshold', $errors, 0, 100000000, 0),
            'current_qty' => Input::int('current_qty', 'Current quantity', $errors, 0, 100000000, 0),
        ];
        if ($data['sku'] !== '' && !preg_match('/^[A-Z0-9][A-Z0-9._\-]*$/', $data['sku'])) {
            $errors[] = 'SKU may only contain letters, numbers, dots, dashes and underscores.';
        }
        if ($data['sku'] !== '') {
            $s = Database::connection()->prepare('SELECT id FROM inventory_items WHERE sku = :sku AND id <> :id');
            $s->execute(['sku' => $data['sku'], 'id' => $exceptId ?? '']);
            if ($s->fetchColumn()) {
                $errors[] = "SKU {$data['sku']} is already used by another item.";
            }
        }
        return [$data, $errors];
    }

    public function store(array $params): void
    {
        $user = AuthService::authorize('inventory.manage');
        Csrf::requireValid();
        [$data, $errors] = $this->validate(null);
        if ($errors) {
            View::render('inventory/form', ['item' => null, 'old' => $_POST, 'errors' => $errors, 'pageTitle' => 'New Inventory Item'], 422);
            return;
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        if (!FormToken::consume($pdo, $user['id'])) {
            $pdo->rollBack();
            View::flash('info', 'This form was already submitted — the item was not created twice.');
            View::redirect('/inventory');
        }
        $data['id'] = Id::generate('inv');
        $pdo->prepare(
            'INSERT INTO inventory_items (id, sku, name, unit, reorder_threshold, current_qty, updated_at) VALUES (:id, :sku, :name, :unit, :reorder_threshold, :current_qty, NOW())'
        )->execute($data);
        AuditService::log($user['id'], 'INVENTORY_ITEM_CREATED', 'InventoryItem', $data['id'], ['sku' => $data['sku'], 'qty' => $data['current_qty']]);
        $pdo->commit();
        View::flash('success', "Inventory item {$data['sku']} created.");
        View::redirect('/inventory');
    }

    public function edit(array $params): void
    {
        AuthService::authorize('inventory.manage');
        $item = View::findOr404('SELECT * FROM inventory_items WHERE id = :id', ['id' => $params['id']], 'Inventory item');
        View::render('inventory/form', ['item' => $item, 'old' => $item, 'errors' => [], 'pageTitle' => 'Edit ' . $item['sku']]);
    }

    public function update(array $params): void
    {
        $user = AuthService::authorize('inventory.manage');
        Csrf::requireValid();
        $item = View::findOr404('SELECT * FROM inventory_items WHERE id = :id', ['id' => $params['id']], 'Inventory item');
        [$data, $errors] = $this->validate($item['id']);
        if ($errors) {
            View::render('inventory/form', ['item' => $item, 'old' => $_POST, 'errors' => $errors, 'pageTitle' => 'Edit ' . $item['sku']], 422);
            return;
        }
        $data['id'] = $item['id'];
        Database::connection()->prepare(
            'UPDATE inventory_items SET sku = :sku, name = :name, unit = :unit, reorder_threshold = :reorder_threshold, current_qty = :current_qty, updated_at = NOW() WHERE id = :id'
        )->execute($data);
        AuditService::log($user['id'], 'INVENTORY_ITEM_UPDATED', 'InventoryItem', $item['id'], [
            'qty_from' => (int) $item['current_qty'], 'qty_to' => $data['current_qty'], 'sku' => $data['sku'],
        ]);
        View::flash('success', "Inventory item {$data['sku']} saved.");
        View::redirect('/inventory');
    }
}
