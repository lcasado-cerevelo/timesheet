<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\EntryController;
use App\Http\Controllers\Api\ContractorInvoiceController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SheetController;
use App\Http\Controllers\Api\SimpleCrudController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Captura de horas (cualquier usuario)
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::get('/entries', [EntryController::class, 'index']);
    Route::post('/entries', [EntryController::class, 'store']);
    Route::put('/entries/{entry}', [EntryController::class, 'update']);
    Route::delete('/entries/{entry}', [EntryController::class, 'destroy']);
    Route::post('/clock/start', [EntryController::class, 'startClock']);
    Route::post('/clock/stop', [EntryController::class, 'stopClock']);
    Route::put('/day-notes', [EntryController::class, 'dayNote']);
    Route::post('/sync', [EntryController::class, 'sync']);

    Route::get('/sheets', [SheetController::class, 'index']);
    Route::get('/sheets/week', [SheetController::class, 'week']);
    Route::get('/sheets/{sheet}', [SheetController::class, 'show']);
    Route::post('/sheets/{sheet}/submit', [SheetController::class, 'submit']);

    Route::get('/reports/summary', [ReportController::class, 'summary']);
    Route::get('/brand', [BrandController::class, 'show']);

    Route::get('/export-templates', [ExportController::class, 'templates']);
    Route::match(['get', 'post'], '/export', [ExportController::class, 'export']);

    Route::get('/contractor-invoices', [ContractorInvoiceController::class, 'index']);
    Route::post('/contractor-invoices', [ContractorInvoiceController::class, 'store']);
    Route::get('/contractor-invoices/{invoice}/pdf', [ContractorInvoiceController::class, 'pdf']);

    // Solo el dueño de la compañía
    Route::middleware('owner')->group(function () {
        Route::post('/projects', [ProjectController::class, 'store']);
        Route::put('/projects/{project}', [ProjectController::class, 'update']);
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);

        Route::get('/approvers', [SimpleCrudController::class, 'approvers']);
        Route::post('/approvers', [SimpleCrudController::class, 'saveApprover']);
        Route::put('/approvers/{approver}', [SimpleCrudController::class, 'saveApprover']);
        Route::get('/customers', [SimpleCrudController::class, 'customers']);
        Route::post('/customers', [SimpleCrudController::class, 'saveCustomer']);
        Route::put('/customers/{customer}', [SimpleCrudController::class, 'saveCustomer']);

        Route::post('/export-templates', [ExportController::class, 'save']);
        Route::put('/export-templates/{template}', [ExportController::class, 'save']);
        Route::delete('/export-templates/{template}', [ExportController::class, 'destroy']);

        Route::post('/contractor-invoices/{invoice}/push-accountisi', [ContractorInvoiceController::class, 'pushAccountisi']);

        Route::get('/integrations/accountisi', [IntegrationController::class, 'show']);
        Route::put('/integrations/accountisi', [IntegrationController::class, 'save']);
        Route::post('/integrations/accountisi/test', [IntegrationController::class, 'test']);
        Route::post('/integrations/accountisi/sync-customers', [IntegrationController::class, 'syncCustomers']);
        Route::get('/integrations/accountisi/vendors', [IntegrationController::class, 'vendors']);
        Route::post('/integrations/accountisi/sales-preview', [IntegrationController::class, 'salesPreview']);
        Route::post('/integrations/accountisi/sales-invoice', [IntegrationController::class, 'salesCreate']);

        Route::get('/company', [CompanyController::class, 'show']);
        Route::put('/company', [CompanyController::class, 'update']);

        Route::post('/brand', [BrandController::class, 'update']);
        Route::get('/brand/history', [BrandController::class, 'history']);
        Route::post('/brand/history/{history}/revert', [BrandController::class, 'revert']);

        Route::get('/sheets-pending', [SheetController::class, 'pending']);
        Route::post('/sheets/{sheet}/approve', [SheetController::class, 'approve']);
        Route::post('/sheets/{sheet}/return', [SheetController::class, 'return']);
    });
});
