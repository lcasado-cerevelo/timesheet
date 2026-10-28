<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\ClientTimesheetController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\EntryController;
use App\Http\Controllers\Api\ContractorInvoiceController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\InternalSheetController;
use App\Http\Controllers\Api\ApproverProgressController;
use App\Http\Controllers\Api\ApproverSheetController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SheetController;
use App\Http\Controllers\Api\SimpleCrudController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TableExportController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// Disparador externo del programador de tareas (protegido por CRON_KEY; sin sesión ni CSRF)
Route::post('/cron/run', [\App\Http\Controllers\Api\CronController::class, 'run'])->middleware('throttle:20,1');

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');                 // app móvil
Route::post('/login/web', [AuthController::class, 'loginWeb'])->middleware('throttle:10,1');           // web (con Turnstile si está configurado)
Route::post('/login/code', [AuthController::class, 'loginCode'])->middleware('throttle:10,1');
Route::post('/login/code/resend', [AuthController::class, 'loginCodeResend'])->middleware('throttle:10,1');
Route::get('/password/setup', [PasswordController::class, 'showSetup'])->middleware('throttle:30,1');
Route::post('/password/setup', [PasswordController::class, 'setup'])->middleware('throttle:10,1');
Route::get('/domain-check', [PasswordController::class, 'domainCheck'])->middleware('throttle:300,1');   // Caddy: on_demand_tls ask
Route::get('/public-config', [AuthController::class, 'publicConfig'])->middleware('throttle:60,1');

Route::middleware(['auth:sanctum', 'approver.scope'])->group(function () {
    Route::post('/password/change', [PasswordController::class, 'change'])->middleware('throttle:10,1');

    // Cuentas de aprobador: solo sus hojas
    Route::middleware('approver')->prefix('approver')->group(function () {
        Route::get('/review', [ApproverSheetController::class, 'review']);
        Route::get('/sheets', [ApproverSheetController::class, 'index']);
        Route::get('/sheets/{sheet}', [ApproverSheetController::class, 'show']);
        Route::post('/sheets/{sheet}/code', [ApproverSheetController::class, 'code'])->middleware('throttle:5,1');
        Route::post('/sheets/{sheet}/approve', [ApproverSheetController::class, 'approve']);
        Route::post('/sheets/{sheet}/return', [ApproverSheetController::class, 'return']);
        // Gerente de proyecto: Avance de los proyectos de sus clientes (solo lectura)
        Route::get('/progress', [ApproverProgressController::class, 'index']);
        Route::get('/progress/{project}', [ApproverProgressController::class, 'show']);
        Route::get('/progress/{project}/export', [ApproverProgressController::class, 'export']);
    });

    // Aprobadores internos (supervisores): solo las líneas de los proyectos que supervisan
    Route::middleware('internal')->prefix('internal')->group(function () {
        Route::get('/review', [InternalSheetController::class, 'review']);
        Route::get('/sheets/{sheet}', [InternalSheetController::class, 'show']);
        Route::post('/sheets/{sheet}/approve', [InternalSheetController::class, 'approve']);
        Route::post('/sheets/{sheet}/return', [InternalSheetController::class, 'return']);
    });

    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Tareas: las completan el dueño y los aprobadores internos del proyecto (la regla está en el controlador; firman con contraseña)
    Route::get('/tasks', [TaskController::class, 'index']);
    Route::get('/tasks/{task}/completion', [TaskController::class, 'completion']);
    Route::post('/tasks/{task}/complete', [TaskController::class, 'complete'])->middleware('throttle:20,1');

    // Lo que ve cada persona de sus comisiones (solo lo suyo)
    Route::get('/me/commissions', [CommissionController::class, 'mine']);

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
    Route::post('/sheets/submit-days', [SheetController::class, 'submitDays']);
    Route::get('/sheets/{sheet}', [SheetController::class, 'show']);
    Route::post('/sheets/{sheet}/submit', [SheetController::class, 'submit']);

    Route::get('/reports/summary', [ReportController::class, 'summary']);
    // Exportar cualquier tabla a CSV, Excel o PDF con la marca de la compañía
    Route::match(['get', 'post'], '/tables/{dataset}/export', [TableExportController::class, 'export']);
    Route::get('/brand', [BrandController::class, 'show']);

    Route::put('/me/locale', [AuthController::class, 'setLocale']);
    Route::get('/export-templates', [ExportController::class, 'templates']);
    Route::get('/export/patients', [ExportController::class, 'patients']);
    Route::match(['get', 'post'], '/export', [ExportController::class, 'export']);

    Route::get('/contractor-invoices', [ContractorInvoiceController::class, 'index']);
    Route::get('/contractor-invoices/pending-commissions', [ContractorInvoiceController::class, 'pendingCommissions']);
    Route::post('/contractor-invoices', [ContractorInvoiceController::class, 'store']);
    Route::get('/contractor-invoices/{invoice}/pdf', [ContractorInvoiceController::class, 'pdf']);

    // Solo el dueño de la compañía
    Route::middleware('owner')->group(function () {
        Route::post('/projects', [ProjectController::class, 'store']);
        Route::put('/projects/{project}', [ProjectController::class, 'update']);
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);
        Route::get('/projects/{project}/approver-options', [ProjectController::class, 'approverOptions']);

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::post('/users/{user}/resend-invitation', [UserController::class, 'resendInvitation']);
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

        Route::match(['get', 'post'], '/client-timesheet/preview', [ClientTimesheetController::class, 'preview']);
        Route::match(['get', 'post'], '/client-timesheet/download', [ClientTimesheetController::class, 'download']);
        Route::post('/client-timesheet/email', [ClientTimesheetController::class, 'email']);

        Route::get('/company', [CompanyController::class, 'show']);
        Route::put('/company', [CompanyController::class, 'update']);

        Route::post('/brand', [BrandController::class, 'update']);
        Route::get('/brand/history', [BrandController::class, 'history']);
        Route::post('/brand/history/{history}/revert', [BrandController::class, 'revert']);

        Route::post('/projects/{project}/services', [ServiceController::class, 'store']);
        Route::put('/services/{service}', [ServiceController::class, 'update']);
        Route::post('/projects/{project}/tasks', [TaskController::class, 'store']);
        Route::put('/tasks/{task}', [TaskController::class, 'update']);
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);
        Route::get('/commissions', [CommissionController::class, 'index']);
        Route::post('/commissions', [CommissionController::class, 'store']);
        Route::put('/commissions/{commission}', [CommissionController::class, 'update']);
        Route::delete('/commissions/{commission}', [CommissionController::class, 'destroy']);
        Route::get('/commission-results', [CommissionController::class, 'results']);
        Route::post('/commission-results/{result}/release', [CommissionController::class, 'release'])->middleware('throttle:20,1');
        Route::put('/commission-results/{result}', [CommissionController::class, 'adjust'])->middleware('throttle:20,1');
        Route::post('/commission-results/{result}/paid', [CommissionController::class, 'paid'])->middleware('throttle:20,1');
        Route::post('/tasks/{task}/reopen', [TaskController::class, 'reopen'])->middleware('throttle:20,1');

        Route::get('/sheets-pending', [SheetController::class, 'pending']);
        Route::get('/review/sheets', [SheetController::class, 'review']);

        Route::get('/accounts', [AccountController::class, 'index']);
        Route::post('/invitations', [AccountController::class, 'invite']);
        Route::post('/sheets/{sheet}/approve', [SheetController::class, 'approve']);
        Route::post('/sheets/{sheet}/return', [SheetController::class, 'return']);
    });
});
