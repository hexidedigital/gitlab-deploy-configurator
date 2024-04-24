<?php

namespace App\Http\Telegram\Command;

trait HelpCommand
{
    public function help(): void
    {
        $this->makePreparationsForWork();

        $message = <<<'TEXT'
            I can help you to configure project repository and server for deployment.

            To start the configuration process, send me the command /startconfiguration

            Within configuration process you can use:
            /cancel - to cancel the configuration process. This will *reset all* the data you've entered.
            /retry - to retry the last operation prompt.
            /back - to go back to the previous step.
            /restart - to restart the configuration process (alias for `cancel` and `startconfiguration`)
            /step - to show the current configuration step.
            TEXT;

        $this->chat->markdown($message)->send();
    }
}
