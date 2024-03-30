<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator;

trait InteractsWithParser
{
    /**
     * @var array<string, bool>
     */
    public array $parsed = [];

    public function getParsedStatuses(): array
    {
        return $this->parsed;
    }

    public function setParseStatusForStage(string $stageName, bool $status): void
    {
        $this->fill([
            'parsed.' . $stageName => $status,
        ]);
    }

    public function getParseStatusForStage(?string $stageName): bool
    {
        return $this->parsed[$stageName] ?? false;
    }

    public function hasParsedStage(): bool
    {
        return collect($this->parsed)->contains(true);
    }

    protected function resetParsedState(): void
    {
        $this->reset([
            'parsed',
        ]);
    }
}
