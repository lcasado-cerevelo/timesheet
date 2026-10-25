<?php

use App\Services\ApprovalService;
use App\Services\SheetService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('approvals:notify-changes', function (SheetService $sheets) {
    $this->info(__('Avisos de cambio enviados: :n', ['n' => $sheets->sendDueChangeNotices()]));
})->purpose('Avisa al aprobador de las hojas que cambiaron mientras esperaban su decisión');

Artisan::command('approvals:remind', function (ApprovalService $approvals) {
    $this->info(__('Recordatorios enviados: :n', ['n' => $approvals->sendReminders()]));
})->purpose('Recuerda a los aprobadores las hojas con más de N días sin decisión');

// En hosting compartido basta con una línea de cron: * * * * * php artisan schedule:run
Schedule::command('approvals:notify-changes')->everyMinute();
Schedule::command('approvals:remind')->dailyAt('08:00');
