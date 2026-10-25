<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class MailTest extends Command
{
    protected $signature = 'tiempo:probar-correo {destino : Correo al que se envía la prueba}';

    protected $description = 'Envía un correo de prueba con la configuración actual (por ejemplo Brevo) y dice si funcionó';

    public function handle(): int
    {
        $cfg = config('mail.mailers.'.config('mail.default'));
        $this->line(__('Servicio: :service · servidor: :host:port · remitente: :from', ['service' => config('mail.default'), 'host' => $cfg['host'] ?? '-', 'port' => ':'.($cfg['port'] ?? '-'), 'from' => config('mail.from.address')]));
        try {
            Mail::raw(__('Si lees esto, el correo de Tiempo funciona. Enviado el :date.', ['date' => now()->toDateTimeString()]), function ($m) {
                $m->to($this->argument('destino'))->subject(__('Prueba de correo de Tiempo'));
            });
        } catch (\Throwable $e) {
            $this->error(__('No se pudo enviar: :error', ['error' => $e->getMessage()]));
            $this->line(__('Revisa usuario y clave SMTP, que el remitente (MAIL_FROM_ADDRESS) esté verificado en el proveedor y el puerto (587 con STARTTLS).'));
            return self::FAILURE;
        }
        $this->info(__('Enviado a :to. Revisa la bandeja (y el correo no deseado).', ['to' => $this->argument('destino')]));
        return self::SUCCESS;
    }
}
