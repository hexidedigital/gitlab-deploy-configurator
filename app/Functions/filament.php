<?php

namespace Filament\Support;

if (!function_exists('Filament\Support\get_attribute_translation')) {
    function get_attribute_translation(string $resourceSlug, string $name): ?string
    {
        if (str($name)->before('.')->contains(config('translatable.locales'))) {
            $name = str($name)->after('.')->value();
        }

        if (str($name)->before('.')->contains('translation')) {
            $name = str($name)->after('.')->value();
        }

        return match (true) {
            trans()->has($key = "filament/resources/{$resourceSlug}.attributes.{$name}") => __($key),
            trans()->has($key = "admin_labels.attributes.{$name}") => __($key),
            default => str($name)
                ->afterLast('.')
                ->kebab()
                ->replace(['-', '_'], ' ')
                ->ucfirst()
                ->value(),
        };
    }
}
