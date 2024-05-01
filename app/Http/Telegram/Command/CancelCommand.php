<?php

namespace App\Http\Telegram\Command;

trait CancelCommand
{
    public function cancel(): void
    {
        $this->makePreparationsForWork();

        if (is_null($this->chatContext->current_command)) {
            $this->chat->markdown("Hey 😑! What do you want? You have no active commands to cancel. I'm going back to sleep 😴  Zzzz...")->send();

            return;
        }

        $this->resetChatContext();

        $this->chat->message('Project configuration has been cancelled.')->send();
    }
}
