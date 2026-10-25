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
        $this->line('Servicio: '.config('mail.default').' · servidor: '.($cfg['host'] ?? '-').':'.($cfg['port'] ?? '-').' · remitente: '.config('mail.from.address'));
        try {
            Mail::raw('Si lees esto, el correo de Tiempo funciona. Enviado el '.now()->toDateTimeString().'.', function ($m) {
                $m->to($this->argument('destino'))->subject('Prueba de correo de Tiempo');
            });
        } catch (\Throwable $e) {
            $this->error('No se pudo enviar: '.$e->getMessage());
            $this->line('Revisa usuario y clave SMTP, que el remitente (MAIL_FROM_ADDRESS) esté verificado en el proveedor y el puerto (587 con STARTTLS).');
            return self::FAILURE;
        }
        $this->info('Enviado a '.$this->argument('destino').'. Revisa la bandeja (y el correo no deseado).');
        return self::SUCCESS;
    }
}
