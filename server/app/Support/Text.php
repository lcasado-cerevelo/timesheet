<?php

namespace App\Support;

use Illuminate\Support\Str;

class Text
{
    /** Minúsculas y sin tildes, para buscar sin importar acentos. */
    public static function fold(?string $s): string
    {
        return mb_strtolower(Str::ascii((string) $s));
    }

    /** Todas las palabras de $q aparecen en alguno de los campos (sin tildes ni mayúsculas). */
    public static function matches(?string $q, array $fields): bool
    {
        $q = trim((string) $q);
        if ($q === '') {
            return true;
        }
        $hay = self::fold(implode(' ', array_map(fn ($f) => (string) $f, $fields)));
        foreach (preg_split('/\s+/', self::fold($q)) as $word) {
            if ($word !== '' && ! str_contains($hay, $word)) {
                return false;
            }
        }
        return true;
    }
}
