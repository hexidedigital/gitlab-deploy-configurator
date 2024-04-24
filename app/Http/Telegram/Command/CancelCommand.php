<?php

namespace App\Http\Telegram\Command;

trait CancelCommand
{
    public function cancel(): void
    {
        $this->makePreparationsForWork();

        if (is_null($this->chatContext->current_command)) {
            // What do you want?
            $this->chat->markdown('Hey 😑! Don\'t touch me, you have no active command to cancel. I\'m go back to sleep 😴  _Zzzz_...')->send();

            return;
        }

        $this->resetChatContext();

        $this->chat->message('Project configuration has been cancelled.')->send();
    }
}
