<?php

declare(strict_types=1);

use Illuminate\Support\Str;

require 'filament.php';

if (!function_exists('class_uses_contains')) {
    /**
     * @param object|string $class
     * @param string $trait
     * @param bool $recursive
     * @return bool
     */
    function class_uses_contains(object|string $class, string $trait, bool $recursive = true): bool
    {
        $uses = $recursive ? class_uses_recursive($class) : class_uses($class);

        return in_array($trait, array_keys($uses));
    }
}

if (!function_exists('price_format')) {
    /**
     * @param float|int|null $number
     * @return string
     */
    function price_format(float|int|null $number): string
    {
        return NumberFormatter::create(app()->getLocale(), NumberFormatter::DECIMAL)
            ->format($number ?: 0);
    }
}

if (!function_exists('normalize_phone')) {
    function normalize_phone(?string $phone): string
    {
        $phone = str($phone)
            ->replaceMatches('/\D/u', '')
            ->replace(' ', '')
            ->whenStartsWith(
                '0',
                fn (\Illuminate\Support\Stringable $phone) => $phone->start('38')
            );

        return (string)$phone;
    }
}

if (!function_exists('assetForMedia')) {
    function assetForMedia(?string $path): ?string
    {
        if (empty($path)) {
            return '';
        }
        if (Str::startsWith($path, 'http')) {
            return $path;
        }

        $path = str_replace('storage/', '', $path);

        return Storage::disk('public')->url($path);
    }
}
