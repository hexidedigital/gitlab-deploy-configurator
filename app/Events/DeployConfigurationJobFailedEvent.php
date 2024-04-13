<?php

namespace App\Events;

use App\GitLab\Data\ProjectData;
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
