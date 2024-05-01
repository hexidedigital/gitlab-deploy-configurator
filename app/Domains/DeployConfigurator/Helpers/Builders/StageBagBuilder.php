<?php

declare(strict_types=1);

namespace App\Domains\DeployConfigurator\Helpers\Builders;

use App\Domains\DeployConfigurator\DeploymentOptions\Options\Database;
use App\Domains\DeployConfigurator\DeploymentOptions\Options\Mail;
use App\Domains\DeployConfigurator\DeploymentOptions\Options\Options;
use App\Domains\DeployConfigurator\DeploymentOptions\Options\Server;
use App\Domains\DeployConfigurator\DeploymentOptions\Stage;
use App\Domains\DeployConfigurator\DeploymentOptions\StageBag;
use App\Domains\DeployConfigurator\Helpers\OptionValidator;
use HexideDigital\GitlabDeploy\Exceptions\GitlabDeployException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

final class StageBagBuilder
{
    /**
     * @param array $stages
     * @return StageBag
     * @throws GitlabDeployException
     */
    public function build(array $stages): StageBag
    {
        if (empty($stages)) {
            throw new GitlabDeployException('No one stages are defined');
        }

        $stageBag = new StageBag();

        foreach ($stages as $stageOptions) {
            $name = $stageOptions['name'];
            $options = new Options(Arr::get($stageOptions, 'options', []));
            $server = new Server(Arr::get($stageOptions, 'server', []));

            $this->validate($options, $server, $name);

            $database = $this->makeDatabase(Arr::get($stageOptions, 'database', []));
            $mail = $this->makeMail(Arr::get($stageOptions, 'mail', []));

            $stage = new Stage($name, $options, $server, $database, $mail);

            $stageBag->add($stage);
        }

        return $stageBag;
    }

    /**
     * @param Options $options
     * @param Server $server
     * @param Database $database
     * @param string $name
     * @return void
     * @throws GitlabDeployException
     */
    private function validate(Options $options, Server $server, string $name): void
    {
        /** @var Collection<string, bool> $listOfEmptyOptions */
        $listOfEmptyOptions = collect([
            'options' => OptionValidator::onyOfKeyIsEmpty($options),
            'server' => OptionValidator::onyOfKeyIsEmpty($server),
        ])
            ->filter()
            ->keys();

        if ($listOfEmptyOptions->isNotEmpty()) {
            throw GitlabDeployException::hasEmptyStageOptions($name, $listOfEmptyOptions->all());
        }
    }

    private function makeMail(?array $source): ?Mail
    {
        if (empty($source)) {
            return null;
        }

        return new Mail($source);
    }

    public function makeDatabase(?array $source): ?Database
    {
        if (empty($source)) {
            return null;
        }

        return new Database($source);
    }
}
