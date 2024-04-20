<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('general', function (SettingsBlueprint $blueprint) {
            $blueprint->add('gitlabDomain', config('services.gitlab.url'));
            $blueprint->add('released', false);
            $blueprint->add('mainTelegramBot', config('app.main_telegram_bot'));
            $blueprint->add('loggerTelegramBot', 'HexideDigitalAppNotifyBot');
        });
    }
};
