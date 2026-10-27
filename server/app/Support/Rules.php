<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Reglas configurables por compañía (columna companies.rules).
 */
class Rules
{
    public const DEFAULTS = [
        'week_starts_on' => 0,          // día en que empieza la semana (0 = domingo, 1 = lunes)
        'weeks_back' => 8,              // semanas hacia atrás que se pueden llenar
        'deadline_dow' => 1,            // día límite tras terminar la semana (1 = lunes)
        'deadline_time' => '12:00',
        'flag_late' => true,
        'increment' => 0.25,            // incremento de hora
        'max_day_hours' => 16,
        'approval_remind_days' => 7,    // días sin decisión antes del recordatorio
        'remind_every_days' => 3,
        'token_days' => 7,              // vigencia del enlace de aprobación
        'change_notice_minutes' => 10,  // espera antes de avisar un cambio
        'code_minutes' => 15,           // vigencia del código de 6 dígitos
        'remember_days' => 30,          // días que se recuerda un equipo de aprobador (0 = nunca)
        'second_approval_hours' => null,// hojas sobre estas horas: individual y con código
        'partial_submission' => false,  // permite enviar la semana por partes (días corridos)
        'session_max_days_office' => 7, // vida máxima de la sesión del dueño y la oficina, aunque la usen (0 = sin tope)
        'session_max_days' => 14,       // lo mismo para el resto de las personas
        'two_step_office' => true,      // el dueño y la oficina también piden el código por correo al entrar
    ];

    public function __construct(private array $values)
    {
        $this->values = array_replace(self::DEFAULTS, $values);
    }

    public static function make(?array $stored): self
    {
        return new self($stored ?? []);
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function all(): array
    {
        return $this->values;
    }

    /** Día en que empieza la semana: 0 (domingo) o 1 (lunes). */
    public function weekStart(): int
    {
        return (int) $this->get('week_starts_on') === 1 ? 1 : 0;
    }

    /** Incremento de hora (horas); 0.25 si el valor guardado no sirve. */
    public function increment(): float
    {
        return (float) $this->get('increment') ?: 0.25;
    }

    /** Incremento en minutos, solo si es un número entero de minutos >= 1 (0.25 -> 15, 0.1 -> 6); si no (0.01), null: no se exige alineación. */
    public function stepMinutes(): ?int
    {
        $m = $this->increment() * 60;
        return $m >= 1 - 1e-6 && abs($m - round($m)) < 1e-6 ? (int) round($m) : null;
    }

    /** Duración mínima de una entrada: un incremento, y nunca menos de 1 minuto. */
    public function minimumMinutes(): int
    {
        return max(1, (int) round($this->increment() * 60));
    }

    /** El incremento escrito para mostrarlo: 0.25, 0.5, 1, 0.1, 0.05, 0.01. */
    public function incrementLabel(): string
    {
        return rtrim(rtrim(number_format($this->increment(), 2, '.', ''), '0'), '.');
    }

    /** Fecha límite (en la zona de la compañía) de una semana que termina el día $weekEnding: el primer día límite después de terminar. */
    public function deadlineFor(string $weekEnding, string $timezone): CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $this->values['deadline_time']));
        $end = CarbonImmutable::parse($weekEnding, $timezone);
        $days = ((int) $this->values['deadline_dow'] - $end->dayOfWeek + 7) % 7;
        $days = $days === 0 ? 7 : $days;
        return $end->addDays($days)->setTime($h, $m);
    }
}
