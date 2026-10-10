<?php

declare(strict_types=1);

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

Session::start();

$router = new Router();

$router->get('/', [DashboardController::class, 'index']);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/dashboard', [DashboardController::class, 'index']);

$router->get('/customers', [CustomerController::class, 'index']);
$router->get('/customers/new', [CustomerController::class, 'create']);
$router->post('/customers', [CustomerController::class, 'store']);
$router->get('/customers/{id}', [CustomerController::class, 'show']);

$router->get('/inquiries', [InquiryController::class, 'index']);
$router->get('/inquiries/new', [InquiryController::class, 'create']);
$router->post('/inquiries', [InquiryController::class, 'store']);
$router->get('/inquiries/{id}', [InquiryController::class, 'show']);
$router->post('/inquiries/{id}/convert', [InquiryController::class, 'convertToQuotation']);

$router->get('/quotations', [QuotationController::class, 'index']);
$router->get('/quotations/new', [QuotationController::class, 'create']);
$router->post('/quotations', [QuotationController::class, 'store']);
$router->get('/quotations/{id}', [QuotationController::class, 'show']);
$router->post('/quotations/{id}/send', [QuotationController::class, 'send']);
$router->post('/quotations/{id}/approve', [QuotationController::class, 'approve']);
$router->post('/quotations/{id}/reject', [QuotationController::class, 'reject']);
$router->post('/quotations/{id}/convert', [QuotationController::class, 'convertToOrder']);

$router->get('/orders', [OrderController::class, 'index']);
$router->get('/orders/{id}', [OrderController::class, 'show']);
$router->post('/orders/{id}/cancel', [OrderController::class, 'cancel']);

$router->post('/orders/{id}/payments', [PaymentController::class, 'store']);
$router->post('/payments/{id}/confirm', [PaymentController::class, 'confirm']);
$router->post('/payments/{id}/reject', [PaymentController::class, 'reject']);
$router->get('/payments', [PaymentController::class, 'index']);

$router->get('/job-orders', [JobOrderController::class, 'index']);
$router->post('/orders/{id}/job-orders', [JobOrderController::class, 'store']);
$router->get('/job-orders/{id}', [JobOrderController::class, 'show']);
$router->post('/job-orders/{id}/advance', [JobOrderController::class, 'advanceStage']);
$router->post('/job-orders/{id}/undo', [JobOrderController::class, 'undoStage']);
$router->post('/job-orders/{id}/qc', [JobOrderController::class, 'recordQc']);

$router->get('/inventory', [InventoryController::class, 'index']);

$router->get('/files/{id}', [UploadController::class, 'download']);

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
