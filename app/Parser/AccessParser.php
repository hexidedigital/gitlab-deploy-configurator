<?php

namespace App\Parser;

use Illuminate\Support\Collection;

class AccessParser
{
    protected array $accessInfo = [];
    protected array $notResolved = [];
    protected array $detected = [];
    protected string $stageName;

    public function __construct()
    {
        $this->resetDetected();
    }

    public function getAccessInfo(?string $stageName = null): ?array
    {
        return $stageName
            ? data_get($this->accessInfo, $stageName)
            : $this->accessInfo;
    }

    public function getNotResolved(?string $stageName = null): array
    {
        return $stageName
            ? data_get($this->notResolved, $stageName, [])
            : $this->notResolved;
    }

    public function parseInputForAccessPayload(string $stageName, ?string $accessInput): self
    {
        $this->stageName = $stageName;

        $this->notResolved[$stageName] = [];

        $this->accessInfo[$stageName] = $this->parseAccessInput($accessInput, $stageName);

        return $this;
    }

    protected function parseAccessInput(?string $accessInput, string $stageName)
    {
        $this->resetDetected();

        return collect(explode("\n", $accessInput))
            // split by empty lines
            ->chunkWhile(fn ($line) => !empty(trim($line)))
            ->mapWithKeys(function (Collection $chunkLines, int $chunkIndex) {
                /** @var Collection $chunkLines */
                $chunkLines = $chunkLines->filter(fn ($line) => str($line)->squish()->isNotEmpty())->values();

                $parsedData = $this->processChunk($chunkLines);
                if (!is_null($parsedData)) {
                    return $parsedData;
                }

                if ($chunkLines->filter()->isNotEmpty()) {
                    $this->saveUnresolved([
                        'chunk' => $chunkIndex + 1,
                        'lines' => $chunkLines,
                    ]);
                }

                return [
                    'skip' => null,
                ];
            })
            ->filter()
            ->toArray();
    }

    protected function parseServerLines(Collection $lines): array
    {
        return $lines->filter()->skip(1)
            ->values()
            ->mapWithKeys(fn ($line) => $this->processAsKeyValueLine($line))
            ->toArray();
    }

    protected function parseDatabaseLines(Collection $lines): array
    {
        $lines = $lines
            ->skip(1)
            ->values();

        return [
            'database' => $lines[0] ?? null,
            'username' => $lines[1] ?? null,
            'password' => $lines[2] ?? null,
        ];
    }

    protected function parseMailLines(Collection $lines): array
    {
        return $lines->filter()->skip(1)
            ->values()
            ->mapWithKeys(fn ($line) => $this->processAsKeyValueLine($line))
            ->toArray();
    }

    protected function resetDetected(): void
    {
        $this->detected = [
            'database' => false,
            'mail' => false,
            'server' => false,
        ];
    }

    protected function saveUnresolved(array $arr): void
    {
        $this->notResolved[$this->stageName][] = $arr;
    }

    protected function processChunk(Collection $lines): ?array
    {
        $firstLine = str($lines->first())->squish()->lower();

        // if first line contains a keyword for database, process as database access
        if (
            !$this->detected['database'] && $firstLine->startsWith(['mysql', 'db', 'database'])
        ) {
            $this->detected['database'] = true;

            return ['database' => $this->parseDatabaseLines($lines)];
        }

        // if first line contains a keyword for mail, process as mail access
        if (
            !$this->detected['mail'] && $firstLine->startsWith(['mail', 'smtp'])
        ) {
            $this->detected['mail'] = true;

            return ['mail' => $this->parseMailLines($lines)];
        }

        // if first like looks like a site url, process as server access
        if (
            !$this->detected['server'] && ($firstLine->isMatch('/\w+\.\w+/') || $firstLine->startsWith(['server', 'remote']))
        ) {
            $this->detected['server'] = true;

            return ['server' => $this->parseServerLines($lines)];
        }

        return null;
    }

    protected function processAsKeyValueLine(string $line): array
    {
        preg_match("/(?<key>\\w+):\\s*(?<value>.*)/", $line, $match);

        $key = str($match['key'])->trim()->lower()->value();

        return [
            $key => trim($match['value']),
        ];
    }
}
