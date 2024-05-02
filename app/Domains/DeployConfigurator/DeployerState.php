<?php

declare(strict_types=1);

namespace App\Domains\DeployConfigurator;

use App\Domains\DeployConfigurator\DeploymentOptions\Configurations;
use App\Domains\DeployConfigurator\DeploymentOptions\Stage;
use App\Domains\DeployConfigurator\Helpers\Builders\ConfigurationBuilder;
use App\Domains\DeployConfigurator\Helpers\Builders\ReplacementsBuilder;
use App\Domains\DeployConfigurator\Helpers\Builders\VariableBagBuilder;
use HexideDigital\GitlabDeploy\Exceptions\GitlabDeployException;
use HexideDigital\GitlabDeploy\Gitlab\VariableBag;
use HexideDigital\GitlabDeploy\Helpers\ParseConfiguration;
use HexideDigital\GitlabDeploy\Helpers\Replacements;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\CircularDependencyException;

final class DeployerState
{
    private Replacements $replacements;
    private Configurations $configurations;
    private Stage $stage;
    private VariableBag $gitlabVariablesBag;
    private bool $isPrintOnly;

    /**
     * @param string $stageName
     * @throws BindingResolutionException
     * @throws CircularDependencyException
     * @throws GitlabDeployException
     */
    public function prepare(string $stageName, bool $isFrontend = false): void
    {
        $this->parseConfigurations($stageName);
        $this->setupReplacements();
        $this->setupGitlabVariables($isFrontend);
    }

    /**
     * @param string $stageName
     * @throws GitlabDeployException
     * @throws BindingResolutionException
     * @throws CircularDependencyException
     */
    public function parseConfigurations(string $stageName): void
    {
        $parser = app(ParseConfiguration::class);

        $filePath = str(config('gitlab-deploy.working-dir'))->finish('/')->append('deploy-prepare.yml');
        $fileData = $parser->parseFile((string)$filePath);

        $builder = app(ConfigurationBuilder::class);

        $configurations = $builder->build($fileData);

        $this->setConfigurations($configurations);
        $this->setStage($configurations->stageBag->get($stageName));
    }

    public function setupGitlabVariables(bool $isFrontend = false): void
    {
        $builder = new VariableBagBuilder($this->replacements, $this->stage->name);

        $this->gitlabVariablesBag = $builder->build($isFrontend);
    }

    public function setupReplacements(): void
    {
        $builder = new ReplacementsBuilder($this->getStage());

        $replacements = $builder->build()->getReplacements();

        $this->setReplacements($replacements);
    }

    public function getReplacements(): Replacements
    {
        return $this->replacements;
    }

    public function setReplacements(Replacements $replacements): void
    {
        $this->replacements = $replacements;
    }

    public function getConfigurations(): Configurations
    {
        return $this->configurations;
    }

    public function setConfigurations(Configurations $configurations): void
    {
        $this->configurations = $configurations;
    }

    public function getStage(): Stage
    {
        return $this->stage;
    }

    public function setStage(Stage $stage): void
    {
        $this->stage = $stage;
    }

    public function getGitlabVariablesBag(): VariableBag
    {
        return $this->gitlabVariablesBag;
    }

    public function setGitlabVariablesBag(VariableBag $gitlabVariablesBag): void
    {
        $this->gitlabVariablesBag = $gitlabVariablesBag;
    }

    public function isPrintOnly(): bool
    {
        return $this->isPrintOnly;
    }

    public function setIsPrintOnly(bool $isPrintOnly): void
    {
        $this->isPrintOnly = $isPrintOnly;
    }
}
