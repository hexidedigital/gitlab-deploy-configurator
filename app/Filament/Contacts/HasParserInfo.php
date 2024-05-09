<?php

namespace App\Filament\Contacts;

interface HasParserInfo
{
    public function resetStatusForStage(string $stageName): void;

    public function isAllAccessParsed(): bool;

    public function setParseStatusForStage(string $stageName, bool $status): void;

    public function getParseStatusForStage(string $stageName): bool;

    public function hasOneParsedStage(): bool;

    public function isAllConnectionCorrect(): bool;

    public function setConnectionStatusForStage(string $stageName, bool $status): void;

    public function getConnectionStatusForStage(?string $stageName): bool;
}
