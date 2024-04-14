<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;

class UserTelegramNotification extends Notification
{
    public function __construct(
        public TelegramMessage $telegramMessage,
    ) {
    }

    public function via(User $notifiable): array
    {
        return array_filter([
            ($notifiable->canReceiveTelegramMessage()) ? 'telegram' : null,
        ]);
    }

    public function toTelegram(User $notifiable): TelegramMessage
    {
        return $this->telegramMessage
            ->to($notifiable->telegram_id);
    }
}
