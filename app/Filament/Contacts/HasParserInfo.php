<?php

namespace App\Filament\Contacts;

interface HasParserInfo
{
    /**
     * @return array<string, bool>
     */
    public function getParsedStatuses(): array;

    public function setParseStatusForStage(string $stageName, bool $status): void;

    public function getParseStatusForStage(string $stageName): bool;

    public function hasParsedStage(): bool;
}
