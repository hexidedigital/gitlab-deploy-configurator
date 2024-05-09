<?php

namespace App\Domains\DeployConfigurator;

use App\Domains\DeployConfigurator\Data\Stage\SshOptions;
use App\Domains\DeployConfigurator\DeploymentOptions\Options\Server;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

class ServerDetailParser
{
    private SSH2 $ssh;
    private bool $isBackend = true;
    private array $parseResult = [];

    public function __construct(
        protected Server $server,
        protected SshOptions $sshOptions,
    ) {
    }

    public function setIsBackendServer(bool $state = true): self
    {
        $this->isBackend = $state;

        return $this;
    }

    public function isBackend(): bool
    {
        return $this->isBackend;
    }

    public function establishSSHConnection(): SSH2
    {
        $this->ssh = new SSH2($this->server->host, $this->server->sshPort);

        $key = $this->resolveKey();

        if (empty($key)) {
            throw new DomainException('Please, provide a private key or password');
        }

        if (!$this->ssh->login($this->server->login, $key)) {
            throw new DomainException('SSH connection failed');
        }

        $this->ssh->enableQuietMode();

        return $this->ssh;
    }

    public function parse(): void
    {
        $this->parseHomeFolder();

        $this->parseForBackendProject();
    }

    /** @return array<string, mixed> */
    public function getParseResult(): array
    {
        return $this->parseResult;
    }

    protected function parseHomeFolder(): void
    {
        $homeFolder = str($this->connection()->exec('echo $HOME'))
            ->trim()
            ->rtrim('/')
            ->value();

        $this->saveParseResult('homeFolderPath', $homeFolder);
    }

    protected function parseForBackendProject(): void
    {
        if (!$this->isBackend) {
            return;
        }

        $paths = $this->searchPaths();

        $this->saveParseResult('phpOutput', '');
        $this->saveParseResult('phpVersion', '-');

        $this->saveParseResult('composerOutput', '-');
        $this->saveParseResult('composerVersion', '-');

        $phpInfo = $this->getPhpInfoFromPaths($paths);
        if (empty($phpInfo['bin'])) {
            $this->saveParseResult('phpOutput', '(PHP in paths not found)');

            return;
        }

        $this->saveParseResult('phpInfo', $phpInfo);

        $phpVOutput = $this->connection()->exec($phpInfo['bin'] . ' -v');
        $this->saveParseResult('phpOutput', $phpVOutput);

        $phpVersion = preg_match('/PHP (\d+\.\d+\.\d+)/', $phpVOutput, $matches) ? $matches[1] : '-';
        $this->saveParseResult('phpVersion', $phpVersion);

        if (empty($paths->get('composer')['bin'])) {
            $this->saveParseResult('composerOutput', '(Composer in paths not found)');

            return;
        }

        $composerVOutput = $this->connection()->exec("{$phpInfo['bin']} {$paths->get('composer')['bin']} -V");
        $this->saveParseResult('composerOutput', $composerVOutput);

        $composerVersion = preg_match('/Composer (?:version )?(\d+\.\d+\.\d+)/', $composerVOutput, $matches) ? $matches[1] : '-';
        $this->saveParseResult('composerVersion', $composerVersion);
    }

    protected function resolveKey(): mixed
    {
        if ($this->sshOptions->useCustomSshKey) {
            if (empty($this->sshOptions->privateKey)) {
                throw new DomainException('Private key is empty');
            }

            return PublicKeyLoader::load(
                key: $this->sshOptions->privateKey,
                password: $this->sshOptions->privateKeyPassword ?: false
            );
        }

        return $this->server->password;
    }

    protected function connection(): SSH2
    {
        if ($this->ssh->isConnected()) {
            return $this->ssh;
        }

        return $this->establishSSHConnection();
    }

    protected function saveParseResult(string $key, mixed $value): void
    {
        $this->parseResult[$key] = $value;
    }

    protected function searchPaths(): Collection
    {
        $php = implode(' ', $this->getPhpLookup());

        return str($this->connection()->exec("whereis {$php} composer"))
            ->explode(PHP_EOL)
            ->mapWithKeys(function ($pathInfo, $line) {
                $binType = Str::of($pathInfo)->before(':')->trim()->value();
                $all = Str::of($pathInfo)->after("{$binType}:")->ltrim()->explode(' ');
                $binPath = $all->first();

                if (!$binType || !$binPath) {
                    return [$line => null];
                }

                if (Str::startsWith($binType, 'php')) {
                    $all = $all->reject(fn ($path) => Str::contains($path, ['-', '.gz', 'man']));
                }

                return [
                    $binType => [
                        'bin' => $binPath,
                        'all' => $all->map(fn ($path) => "{$path}")->implode('; '),
                    ],
                ];
            })
            ->filter()
            ->tap(fn (Collection $paths) => $this->saveParseResult('paths', $paths));
    }

    protected function getPhpInfoFromPaths(Collection $paths): ?array
    {
        $phpLookup = $this->getPhpLookup();

        foreach ($phpLookup as $item) {
            if ($phpInfo = $paths->get($item)) {
                return $phpInfo;
            }
        }

        return null;
    }

    protected function getPhpLookup(): array
    {
        return [
            'php8.2',
            'php8',
            'php',
        ];
    }
}
