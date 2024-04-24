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
            TEXT;

        $this->reply($message);
    }
}
