<?php

namespace App\Parser;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class AccessParser
{
    private string $input = '';
    private array $result;

    public function setInput(string $input): void
    {
        $this->input = $input;
    }

    public function getResult(): array
    {
        return $this->result;
    }

    public function parse(): void
    {
        $this->result = collect(explode("\n", $this->input))
            ->chunkWhile(fn ($line) => !empty(trim($line)))
            ->mapWithKeys(function (Collection $lines) {
                $lines = $lines->filter(fn ($line) => str($line)->squish()->isNotEmpty())->values();

                $type = str($lines->first())->lower();

                if ($type->startsWith('mysql')) {
                    return [
                        'database' => $this->getDatabase($lines),
                    ];
                }
                if ($type->startsWith('mail')) {
                    return [
                        'mail' => $this->getMail($lines),
                    ];
                }
                if ($type->isMatch('/\w+.\w+/')) {
                    return [
                        'server' => $this->getServer($lines),
                    ];
                }

                return [
                    Str::random() => $lines->toArray(),
                ];
            })
            ->toArray();
    }

    public function storeAsFile(string $type): string
    {
        $contents = 'unsupported type';

        if ($type == 'json') {
            $contents = $this->makeJson();
        } elseif ($type == 'php') {
            $contents = $this->makePhp();
        } elseif ($type == 'yaml') {
            $contents = $this->makeYaml();
        }

        $name = Str::random();
        $file = "{$name}.{$type}";

        $filesystem = Storage::disk('local');

        $filesystem->put($file, $contents);

        return $filesystem->path($file);

//        return response()
//            ->download($filesystem->path($file))
//            ->deleteFileAfterSend();
    }

    private function getServer(Collection $lines): array
    {
        return $lines->filter()->skip(1)
            ->values()
            ->mapWithKeys(function ($line) {
                preg_match("/(?<key>\\w+):\\s*(?<value>.*)/", $line, $match);

                $key = str($match['key'])->trim()->lower()->value();

                return [
                    $key => trim($match['value']),
                ];
            })
            ->toArray();
    }

    private function getDatabase(Collection $lines): array
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

    private function getMail(Collection $lines): array
    {
        return $lines->filter()->skip(1)
            ->values()
            ->mapWithKeys(function ($line) {
                preg_match("/(?<key>\\w+):\\s*(?<value>.*)/", $line, $match);

                $key = str($match['key'])->trim()->lower()->value();

                return [
                    $key => trim($match['value']),
                ];
            })
            ->toArray();
    }

    public function makeYaml(): string
    {
        return Yaml::dump($this->result);
    }

    public function makePhp(): ?string
    {
        return var_export($this->result, true);
    }

    public function makeJson(): string|false
    {
        return json_encode($this->result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
