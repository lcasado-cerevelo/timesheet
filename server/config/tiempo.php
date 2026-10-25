<?php

return [
    // Clave para disparar las tareas programadas desde fuera (cron del hosting, AWS EventBridge, Hangfire de Accountisi, etc.).
    // Sin clave, el acceso queda desactivado. Mínimo 32 caracteres.
    'cron_key' => env('CRON_KEY'),

    // Los recordatorios a los aprobadores salen una vez al día, pasada esta hora en esta zona horaria.
    'timezone' => env('TIEMPO_TIMEZONE', 'America/Puerto_Rico'),
    'reminder_hour' => (int) env('TIEMPO_REMINDER_HOUR', 8),
];
