<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public ?string $gitlabDomain = '';
    public bool $released = false;
    public ?string $mainTelegramBot = '';
    public ?string $loggerTelegramBot = '';

    public static function group(): string
    {
        return 'general';
    }
}
