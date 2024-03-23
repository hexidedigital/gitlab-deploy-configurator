<?php

namespace App\Filament\Traits;

trait ResourceHelper
{
    public static function getModelLabel(): string
    {
        $slug = static::getSlug();

        return __("filament/resources/{$slug}.label");
    }

    public static function getPluralModelLabel(): string
    {
        $slug = static::getSlug();

        return __("filament/resources/{$slug}.plural_label");
    }

    public static function getNavigationLabel(): string
    {
        $slug = static::getSlug();

        return __("filament/resources/{$slug}.navigation_label");
    }
}
