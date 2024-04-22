<?php

namespace App\Console\Commands;

use App\Settings\GeneralSettings;
use DefStudio\Telegraph\Models\TelegraphBot;
use Illuminate\Console\Command;

class RegisterBotCommandsCommand extends Command
{
    protected $signature = 'telegram:bot-commands';

    protected $description = 'Register bot commands.';

    public function handle(GeneralSettings $settings): void
    {
        $mainTelegramBot = $settings->mainTelegramBot;

        $this->info('Registering commands for ' . $mainTelegramBot . '...');

        $commands = [
            'help' => 'Show available commands',
            'startconfiguration' => 'Start configuration process',
            'cancel' => 'Cancel current operation',
        ];

        if (config('app.debug')) {
            $commands['retry'] = 'Retry last operation';
            $commands['restart'] = 'Restart configuration process';
            $commands['status'] = 'Show current status';
        }

        $telegraphResponse = TelegraphBot::where('name', $mainTelegramBot)->first()?->registerCommands($commands)->send();

        if (is_null($telegraphResponse)) {
            $this->error('Bot not found.');

            return;
        }

        if ($telegraphResponse->telegraphOk()) {
            $this->info('Commands registered successfully.');
        } else {
            $this->error('Failed to register commands.');
        }
    }
}
