<?php

namespace App\Support;

class Color
{
    public static function isHex(string $c): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) === 1;
    }

    private static function lin(float $v): float
    {
        $v /= 255;
        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }

    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        return 0.2126 * self::lin($r) + 0.7152 * self::lin($g) + 0.0722 * self::lin($b);
    }

    /** Relación de contraste del texto blanco sobre $hex (WCAG: 4.5 o más para texto normal). */
    public static function contrastWithWhite(string $hex): float
    {
        return 1.05 / (self::luminance($hex) + 0.05);
    }
}
