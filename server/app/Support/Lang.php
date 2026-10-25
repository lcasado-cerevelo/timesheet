<?php

namespace App\Support;

use Illuminate\Support\Facades\App;

/** Ejecuta un bloque en otro idioma y deja el idioma de la petición como estaba (aunque el bloque lance una excepción). */
class Lang
{
    public static function with(string $locale, callable $fn): mixed
    {
        $previous = App::getLocale();
        App::setLocale($locale);
        try {
            return $fn();
        } finally {
            App::setLocale($previous);
        }
    }
}
