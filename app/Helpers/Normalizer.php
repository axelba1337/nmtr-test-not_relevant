<?php

namespace App\Helpers;

final class Normalizer
{
    public static function text(string $text): string
    {
        $normalized = mb_strtolower(trim($text), 'UTF-8');
        $normalized = preg_replace(
            '/[\s\p{Z}]+/u',
            ' ',
            $normalized,
        ) ?? $normalized;

        return trim($normalized);
    }
}
