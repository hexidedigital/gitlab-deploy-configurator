<?php

namespace App\Domains\DeployConfigurator;

use App\Domains\DeployConfigurator\Data\CiCdOptions;
use App\Domains\DeployConfigurator\Data\ProjectDetails;
use App\Models\DeployProject;
use App\Models\User;

final class DeployProjectBuilder
{
    private DeployProject $deployProject;
    private array $payload;

    private function __construct(ProjectDetails $projectDetails)
    {
        $this->payload = [
            'projectDetails' => $projectDetails->toArray(),
        ];

        $this->deployProject = DeployProject::make([
            'name' => $projectDetails->name,
            'project_gid' => $projectDetails->project_id,
        ]);
    }

    public static function make(ProjectDetails $projectDetails): DeployProjectBuilder
    {
        return new DeployProjectBuilder($projectDetails);
    }

    public function user(User $user): self
    {
        $this->deployProject->fill([
            'user_id' => $user->getAuthIdentifier(),
        ]);

        return $this;
    }

    public function ciCdOptions(CiCdOptions $ciCdOptions): self
    {
        $this->deployProject->fill([
            'type' => $ciCdOptions->template_group,
        ]);

        $this->payload['ciCdOptions'] = $ciCdOptions->toArray();

        return $this;
    }

    public function openedAt(string $openedAt): self
    {
        $this->payload['openedAt'] = $openedAt;

        return $this;
    }

    public function stages(array $stages): self
    {
        $this->deployProject->fill([
            'stage' => collect($stages)->pluck('name')->implode(', '),
        ]);

        $this->payload['stages'] = $stages;

        return $this;
    }

    public function create(string $createFrom): DeployProject
    {
        $this->deployProject->fill([
            'created_from' => $createFrom,
            'status' => 'created',
            'current_step' => 'created',
            'deploy_payload' => $this->payload,
        ]);

        $this->deployProject->save();

        return $this->deployProject;
    }
}
