<?php

namespace App\Support;

use Carbon\CarbonImmutable;

class Week
{
    /** La semana termina el domingo. Devuelve Y-m-d del domingo de la semana de $date. */
    public static function ending(string|\DateTimeInterface $date): string
    {
        $d = CarbonImmutable::parse($date);
        return $d->endOfWeek(CarbonImmutable::SUNDAY)->toDateString();
    }

    public static function starting(string $weekEnding): string
    {
        return CarbonImmutable::parse($weekEnding)->subDays(6)->toDateString();
    }
}
