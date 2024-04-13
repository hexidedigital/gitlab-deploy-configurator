<?php

namespace App\Http\Telegram;

use App\Models\User;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use Illuminate\Support\Stringable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class DeployWebhookHandler extends WebhookHandler
{
    public function start(): void
    {
        $token = str($this->message?->text())->explode(' ')[1] ?? null;
        if (!$token) {
            $this->reply("Please, scan the QR code from the Profile page to connect your Telegram account. \n\nIf you don't have account for Deploy Configurator, create at " . url('/'));

            return;
        }

        $fromUser = $this->message->from();

        $chatUser = User::where('telegram_id', $fromUser->id())->first();
        if ($chatUser) {
            if (!$chatUser->is_telegram_enabled && $chatUser->telegram_token === $token) {
                $chatUser->update([
                    'is_telegram_enabled' => true,
                    'telegram_user' => $fromUser->toArray(),
                    'telegram_token' => null,
                ]);

                $this->reply('You have successfully connected your Telegram account');
            } else {
                $this->reply('You have already connected your Telegram account');
            }

            $chatUser->update([
                'telegram_user' => $fromUser->toArray(),
                'telegram_token' => null,
            ]);

            return;
        }

        $user = User::where('telegram_token', $token)->first();
        if (!$user) {
            $this->reply('Sorry, I can\'t find the user with this token');

            return;
        }

        $user->update([
            'is_telegram_enabled' => true,
            'telegram_id' => $fromUser->id(),
            'telegram_user' => $fromUser->toArray(),
            'telegram_token' => null,
        ]);

        $this->chat->html('Welcome to Deploy Configurator, ' . $user->name)->send();
        $this->reply('You have successfully connected your Telegram account');
    }

    protected function handleChatMessage(Stringable $text): void
    {
        // ... do nothing
    }

    /**
     * @throws NotFoundHttpException|Throwable
     */
    protected function onFailure(Throwable $throwable): void
    {
        if ($throwable instanceof NotFoundHttpException) {
            throw $throwable;
        }

        report($throwable);

        $this->reply('sorry man, I failed');
    }
}
