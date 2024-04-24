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
            'startconfiguration' => 'Start configuration process',
        ];
        $commands['step'] = 'Show current configuration step';
        $commands['retry'] = 'Retry/Show last operation prompt';
        $commands['back'] = 'Go back to previous step';
        $commands['cancel'] = 'Cancel configuration process';
        $commands['restart'] = 'Restarts configuration process';
        $commands['help'] = 'Show help message';

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
