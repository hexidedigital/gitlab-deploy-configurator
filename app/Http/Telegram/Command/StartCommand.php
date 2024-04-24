<?php

namespace App\Http\Telegram\Command;

use App\Exceptions\Telegram\Halt;
use App\Models\User;
use App\Settings\GeneralSettings;

trait StartCommand
{
    public function start(): void
    {
        $fromUser = $this->message->from();

        // Check if the user is already connected
        $chatUser = User::where('telegram_id', $fromUser->id())->first();
        if (!$chatUser) {
            if (!app(GeneralSettings::class)->released) {
                $this->reply('Registration is currently disabled. Wait for the release');

                return;
            }

            $token = $this->extractToken();

            $this->connectTelegramAccount($token);

            return;
        }

        // refresh user data
        $chatUser->update([
            'telegram_user' => $fromUser->toArray(),
        ]);

        // if the user is already connected, has a token and is not enabled telegram
        // then enable the telegram account
        if (!$chatUser->is_telegram_enabled && $chatUser->telegram_token === $this->extractToken()) {
            $chatUser->update([
                'is_telegram_enabled' => true,
                'telegram_token' => null,
            ]);

            $this->reply('You have successfully connected your Telegram account');

            return;
        }

        $this->reply('You have already connected your Telegram account');
    }

    private function extractToken(): string
    {
        $token = str($this->message?->text())->explode(' ')[1] ?? null;
        if (!$token) {
            $this->welcomeMessage();

            throw new Halt();
        }

        return $token;
    }

    private function findUserByToken(string $token): User
    {
        $user = User::where('telegram_token', $token)->first();
        if (!$user) {
            $this->reply('Sorry, I can\'t find the user with this token');

            throw new Halt();
        }

        return $user;
    }

    private function connectTelegramAccount(string $token): void
    {
        $fromUser = $this->message->from();

        $user = $this->findUserByToken($token);

        $user->update([
            'is_telegram_enabled' => true,
            'telegram_id' => $fromUser->id(),
            'telegram_user' => $fromUser->toArray(),
            'telegram_token' => null,
        ]);

        $this->chat->html('Welcome to Deploy Configurator, ' . $user->name)->send();
        $this->reply('You have successfully connected your Telegram account');
    }
}
