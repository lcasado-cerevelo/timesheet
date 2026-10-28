<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Demostración: nunca se envían correos reales.
        if (config('app.demo') && ! in_array(config('mail.default'), ['log', 'array'], true)) {
            config(['mail.default' => 'log']);
        }

        // Correo por la API HTTP de Brevo (443), para hosting que bloquea el SMTP saliente.
        \Illuminate\Support\Facades\Mail::extend('brevo', fn (array $config) => new \App\Mail\BrevoTransport((string) ($config['key'] ?? ''), (int) ($config['timeout'] ?? 20)));

        \Laravel\Sanctum\Sanctum::authenticateAccessTokensUsing(\App\Support\SessionExpiry::check(...));

        // Los enlaces de los emails y de las aprobaciones deben salir con https cuando la aplicación se publica con https.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
    }
}
