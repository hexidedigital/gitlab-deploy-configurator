<?php

namespace App\Domains\DeployConfigurator\Events;

use App\Domains\GitLab\Data\ProjectData;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

class DeployConfigurationJobFailedEvent
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public ProjectData $projectData,
        public ?Throwable $exception,
    ) {
    }
}
