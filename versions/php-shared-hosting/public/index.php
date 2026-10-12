<?php

declare(strict_types=1);

// Answered before anything else (works before installation): the installer
// requests this URL to prove that .htaccess URL rewriting reaches this file.
if (str_ends_with(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/lp-rewrite-check')) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'LP-REWRITE-OK';
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\Router;
use App\Helpers\Session;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\CustomerController;
use App\Controllers\InquiryController;
use App\Controllers\QuotationController;
use App\Controllers\OrderController;
use App\Controllers\PaymentController;
use App\Controllers\JobOrderController;
use App\Controllers\InventoryController;
use App\Controllers\UploadController;
use App\Controllers\UserController;
use App\Controllers\SystemController;

Session::start();

// After new release files are uploaded, the database may need updates
// before the rest of the app can run. Until an administrator applies them
// (Admin → Database Updates), only sign-in and that page are available.
$pendingPath = substr(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', strlen(base_path())) ?: '/';
if (!in_array(rtrim($pendingPath, '/') ?: '/', ['/login', '/logout', '/admin/updates', '/account/password'], true)
    && (new App\Services\Migrator(App\Helpers\Database::connection()))->pending()) {
    $current = App\Services\AuthService::currentUser();
    if (!$current) {
        App\Helpers\View::redirect('/login');
    }
    if (App\Services\Policy::allows($current, 'system.updates')) {
        App\Helpers\View::redirect('/admin/updates');
    }
    render_fatal_page(503, 'Update in progress', 'LP System is being updated. Please try again in a few minutes, or ask your administrator to finish the update under Admin → Database Updates.');
    exit;
}

$router = new Router();

$router->get('/', [DashboardController::class, 'index']);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/account/password', [AuthController::class, 'showPassword']);
$router->post('/account/password', [AuthController::class, 'changePassword']);
$router->get('/dashboard', [DashboardController::class, 'index']);

$router->get('/customers', [CustomerController::class, 'index']);
$router->get('/customers/new', [CustomerController::class, 'create']);
$router->post('/customers', [CustomerController::class, 'store']);
$router->get('/customers/{id}', [CustomerController::class, 'show']);
$router->get('/customers/{id}/edit', [CustomerController::class, 'edit']);
$router->post('/customers/{id}', [CustomerController::class, 'update']);

$router->get('/inquiries', [InquiryController::class, 'index']);
$router->get('/inquiries/new', [InquiryController::class, 'create']);
$router->post('/inquiries', [InquiryController::class, 'store']);
$router->get('/inquiries/{id}', [InquiryController::class, 'show']);
$router->post('/inquiries/{id}/convert', [InquiryController::class, 'convertToQuotation']);
$router->post('/inquiries/{id}/cancel', [InquiryController::class, 'cancel']);

$router->get('/quotations', [QuotationController::class, 'index']);
$router->get('/quotations/new', [QuotationController::class, 'create']);
$router->post('/quotations', [QuotationController::class, 'store']);
$router->get('/quotations/{id}', [QuotationController::class, 'show']);
$router->get('/quotations/{id}/edit', [QuotationController::class, 'edit']);
$router->post('/quotations/{id}', [QuotationController::class, 'update']);
$router->post('/quotations/{id}/send', [QuotationController::class, 'send']);
$router->post('/quotations/{id}/approve', [QuotationController::class, 'approve']);
$router->post('/quotations/{id}/reject', [QuotationController::class, 'reject']);
$router->post('/quotations/{id}/revision', [QuotationController::class, 'requestRevision']);
$router->post('/quotations/{id}/cancel', [QuotationController::class, 'cancel']);
$router->post('/quotations/{id}/restore', [QuotationController::class, 'restore']);
$router->post('/quotations/{id}/convert', [QuotationController::class, 'convertToOrder']);

$router->get('/orders', [OrderController::class, 'index']);
$router->get('/orders/export', [OrderController::class, 'export']);
$router->get('/orders/{id}', [OrderController::class, 'show']);
$router->post('/orders/{id}/cancel', [OrderController::class, 'cancel']);
$router->post('/orders/{id}/complete', [OrderController::class, 'complete']);
$router->post('/orders/{id}/release-exception', [OrderController::class, 'releaseException']);
$router->post('/orders/{id}/payment-terms', [OrderController::class, 'paymentTerms']);

$router->get('/payments', [PaymentController::class, 'index']);
$router->get('/payments/export', [PaymentController::class, 'export']);
$router->post('/orders/{id}/payments', [PaymentController::class, 'store']);
$router->post('/payments/{id}/confirm', [PaymentController::class, 'confirm']);
$router->post('/payments/{id}/reject', [PaymentController::class, 'reject']);

$router->get('/job-orders', [JobOrderController::class, 'index']);
$router->post('/orders/{id}/job-orders', [JobOrderController::class, 'store']);
$router->get('/job-orders/{id}', [JobOrderController::class, 'show']);
$router->post('/job-orders/{id}/advance', [JobOrderController::class, 'advanceStage']);
$router->post('/job-orders/{id}/undo', [JobOrderController::class, 'undoStage']);
$router->post('/job-orders/{id}/qc', [JobOrderController::class, 'recordQc']);

$router->get('/inventory', [InventoryController::class, 'index']);
$router->get('/inventory/new', [InventoryController::class, 'create']);
$router->post('/inventory', [InventoryController::class, 'store']);
$router->get('/inventory/{id}/edit', [InventoryController::class, 'edit']);
$router->post('/inventory/{id}', [InventoryController::class, 'update']);

$router->get('/files/{id}', [UploadController::class, 'download']);

$router->get('/admin/users', [UserController::class, 'index']);
$router->post('/admin/users', [UserController::class, 'store']);
$router->post('/admin/users/{id}', [UserController::class, 'update']);
$router->post('/admin/users/{id}/reset-password', [UserController::class, 'resetPassword']);
$router->get('/admin/updates', [SystemController::class, 'updates']);
$router->post('/admin/updates', [SystemController::class, 'applyUpdates']);

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
