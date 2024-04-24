<?php

namespace App\Console\Commands;

use App\Settings\GeneralSettings;
use DefStudio\Telegraph\Models\TelegraphBot;
use Illuminate\Console\Command;

class ConfigureTelegramCommand extends Command
{
    protected $signature = 'app:configure-telegram';

    protected $description = 'Execute the necessary steps to configure the Telegram bot.';

    public function handle(): void
    {
        // Register telegram commands
        $this->call('telegram:bot-commands');

        // Update webhook for bot
        $mainBotId = $this->getMainBotId();
        if ($mainBotId) {
            $this->call('telegraph:set-webhook', [
                'bot' => $mainBotId,
            ]);
        } else {
            $this->warn('Main bot not found.');
        }
    }

    protected function getMainBotId(): ?int
    {
        return TelegraphBot::firstWhere('name', app(GeneralSettings::class)->mainTelegramBot)?->id;
    }
}
