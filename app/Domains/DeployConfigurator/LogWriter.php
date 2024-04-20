<?php

namespace App\Domains\DeployConfigurator;

use BadMethodCallException;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * @mixin Logger
 */
class LogWriter
{
    private LoggerInterface|Logger $logger;

    protected array $logBag = [];

    public function __construct()
    {
        $this->logger = Log::channel('daily');
    }

    public function getLogBag(): array
    {
        return $this->logBag;
    }

    public function getLogger(): Logger|LoggerInterface
    {
        return $this->logger;
    }

    public function __call(string $name, array $arguments)
    {
        if (!method_exists($this->logger, $name)) {
            throw new BadMethodCallException("Method {$name} does not exist");
        }

        $this->logBag[] = [
            'date' => now()->format('Y-m-d H:i:s'),
            'type' => $name,
            'message' => $arguments[0],
            'context' => $arguments[1] ?? [],
        ];

        return call_user_func([$this->logger, $name], ...$arguments);
    }
}
