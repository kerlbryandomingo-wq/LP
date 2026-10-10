<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Database;
use App\Helpers\View;
use App\Services\AuthService;

final class InventoryController
{
    public function index(array $params): void
    {
        AuthService::requireLogin();
        $pdo = Database::connection();
        $items = $pdo->query('SELECT * FROM inventory_items ORDER BY name ASC')->fetchAll();
        View::render('inventory/index', ['items' => $items]);
    }
}
