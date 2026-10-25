<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Dispara las tareas programadas desde fuera de la aplicación: avisos de "la hoja cambió" (cada llamada) y recordatorios a los
 * aprobadores (una vez al día, después de la hora configurada). Sirve para no depender del contenedor ni del cron del hosting:
 * cualquier cosa que pueda hacer un POST cada minuto lo activa (cron, AWS EventBridge, Hangfire de Accountisi...).
 *   POST /api/cron/run     encabezado  X-Cron-Key: <CRON_KEY>
 *
 * Las tareas se ejecutan dentro de la misma petición (no como procesos aparte), así funciona igual en un contenedor que en un hosting.
 */
class CronController extends Controller
{
    public function run(Request $request)
    {
        $key = (string) config('tiempo.cron_key');
        // Sin clave configurada (o demasiado corta) el acceso no existe.
        abort_if(strlen($key) < 32, 404);
        abort_unless(hash_equals($key, (string) $request->header('X-Cron-Key')), 403, __('Clave no válida.'));

        $ran = [];
        Artisan::call('approvals:notify-changes');
        $ran['notify_changes'] = trim(Artisan::output());

        $tz = (string) config('tiempo.timezone');
        $now = now($tz);
        // Una sola vez por día, pasada la hora configurada, aunque llamen cada minuto o desde varios sitios a la vez.
        if ($now->hour >= (int) config('tiempo.reminder_hour') && Cache::add('tiempo-reminders-'.$now->toDateString(), 1, now()->addDay())) {
            Artisan::call('approvals:remind');
            $ran['reminders'] = trim(Artisan::output());
        }
        return response()->json(['ok' => true, 'ran' => $ran]);
    }
}
