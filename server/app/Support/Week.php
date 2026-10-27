<?php

namespace App\Support;

use Carbon\CarbonImmutable;

class Week
{
    /**
     * Devuelve Y-m-d del último día de la semana de $date. $startDow es el día en que empieza la semana de la compañía
     * (0 = domingo, 1 = lunes); la semana siempre dura 7 días.
     */
    public static function ending(string|\DateTimeInterface $date, int $startDow = 0): string
    {
        $d = CarbonImmutable::parse($date);
        return $d->endOfWeek(($startDow + 6) % 7)->toDateString();
    }

    public static function starting(string $weekEnding): string
    {
        return CarbonImmutable::parse($weekEnding)->subDays(6)->toDateString();
    }
}
