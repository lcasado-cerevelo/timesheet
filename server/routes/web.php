<?php

use App\Http\Controllers\Web\ApprovalPageController as A;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app/');

// Aprobación sin cuenta: enlaces secretos. Abrirlos no aprueba; se decide con un botón (POST).
Route::middleware(['public.page', 'throttle:40,1'])->group(function () {
    Route::get('/a/{plain}', [A::class, 'show']);
    Route::post('/a/{plain}/code', [A::class, 'sendCode'])->middleware('throttle:5,1');
    Route::post('/a/{plain}/verify', [A::class, 'verifyCode'])->middleware('throttle:10,1');
    Route::post('/a/{plain}/approve', [A::class, 'approve']);
    Route::post('/a/{plain}/return', [A::class, 'returnSheet']);

    Route::get('/b/{plain}', [A::class, 'batch']);
    Route::post('/b/{plain}/code', [A::class, 'sendCode'])->middleware('throttle:5,1');
    Route::post('/b/{plain}/verify', [A::class, 'verifyCode'])->middleware('throttle:10,1');
    Route::post('/b/{plain}/approve-all', [A::class, 'batchApproveAll']);
    Route::post('/b/{plain}/sheet/{sheet}/approve', [A::class, 'batchApprove']);
    Route::post('/b/{plain}/sheet/{sheet}/return', [A::class, 'batchReturn']);
});
