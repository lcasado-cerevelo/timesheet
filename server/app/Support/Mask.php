<?php

namespace App\Support;

class Mask
{
    /**
     * Un correo medio tapado para mostrarlo en pantallas sin sesión: las dos primeras letras, estrellas fijas (no revelan el largo)
     * y la última letra antes de la arroba; el dominio se deja completo. "kfigueroa@amsipr.com" queda "kf*****a@amsipr.com".
     */
    public static function email(?string $email): string
    {
        $email = trim((string) $email);
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '';
        }
        $local = mb_substr($email, 0, $at);
        $domain = substr($email, $at);
        $n = mb_strlen($local);
        $shown = match (true) {
            $n === 1 => '*',
            $n === 2 => mb_substr($local, 0, 1).'*',
            $n === 3 => mb_substr($local, 0, 1).'*'.mb_substr($local, -1),
            default => mb_substr($local, 0, 2).'*****'.mb_substr($local, -1),
        };
        return $shown.$domain;
    }
}
