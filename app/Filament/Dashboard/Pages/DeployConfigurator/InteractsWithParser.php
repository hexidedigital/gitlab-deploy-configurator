<?php

namespace App\Filament\Dashboard\Pages\DeployConfigurator;

use Illuminate\Support\Collection;

trait InteractsWithParser
{
    /**
     * @var array<string, bool>
     */
    public array $stageParsed = [];

    /**
     * @var array<string, bool>
     */
    public array $stageConnections = [];

    public function resetStatusForStage(string $stageName): void
    {
        $this->setParseStatusForStage($stageName, false);
        $this->setConnectionStatusForStage($stageName, false);
    }

    public function isAllAccessParsed(): bool
    {
        return $this->checkStatus(collect($this->stageParsed));
    }

    public function setParseStatusForStage(string $stageName, bool $status): void
    {
        $this->fill([
            'stageParsed.' . $stageName => $status,
        ]);
    }

    public function getParseStatusForStage(?string $stageName): bool
    {
        return $this->stageParsed[$stageName] ?? false;
    }

    public function hasOneParsedStage(): bool
    {
        return collect($this->stageParsed)->contains(true);
    }

    public function isAllConnectionCorrect(): bool
    {
        return $this->checkStatus(collect($this->stageConnections));
    }

    public function setConnectionStatusForStage(string $stageName, bool $status): void
    {
        $this->fill([
            'stageConnections.' . $stageName => $status,
        ]);
    }

    public function getConnectionStatusForStage(?string $stageName): bool
    {
        return $this->stageConnections[$stageName] ?? false;
    }

    protected function resetParserState(): void
    {
        $this->reset([
            'stageParsed',
            'stageConnections',
        ]);
    }

    private function checkStatus(Collection $items): bool
    {
        return $items->isEmpty() || $items->reject()->isNotEmpty();
    }
}
