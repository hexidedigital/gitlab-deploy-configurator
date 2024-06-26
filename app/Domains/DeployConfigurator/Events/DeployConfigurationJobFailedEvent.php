<?php

namespace App\Domains\DeployConfigurator\Events;

use App\Domains\DeployConfigurator\Data\ProjectDetails;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

class DeployConfigurationJobFailedEvent
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public ProjectDetails $projectData,
        public ?Throwable $exception,
    ) {
    }
}
