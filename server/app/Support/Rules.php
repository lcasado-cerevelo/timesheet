<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Reglas configurables por compañía (columna companies.rules).
 */
class Rules
{
    public const DEFAULTS = [
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
        'second_approval_hours' => null,// hojas sobre estas horas: individual y con código
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

    /** Fecha límite (en la zona de la compañía) de una semana que termina el domingo $weekEnding. */
    public function deadlineFor(string $weekEnding, string $timezone): CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $this->values['deadline_time']));
        $sunday = CarbonImmutable::parse($weekEnding, $timezone);
        $days = ((int) $this->values['deadline_dow'] - 7 + 7) % 7; // desde domingo
        $days = $days === 0 ? 7 : $days;
        return $sunday->addDays($days)->setTime($h, $m);
    }
}
