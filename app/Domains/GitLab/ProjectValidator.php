<?php

namespace App\Domains\GitLab;

use App\Domains\GitLab\Data\ProjectData;
use Closure;

class ProjectValidator
{
    protected array $validators = [];
    protected array $failMessages = [];
    protected bool $bail = true;

    public function __construct(
        protected ?ProjectData $project,
    ) {
    }

    public static function makeForProject(?ProjectData $project): static
    {
        return new static($project);
    }

    /**
     * Let the validator continue after the first fail. If set to true, the validator will stop after the first fail.
     */
    public function bail(bool $state = true): static
    {
        $this->bail = $state;

        return $this;
    }

    public function defaults(): static
    {
        $this->validators = [
            'is_not_null' => function (?ProjectData $project, Closure $fail) {
                if ($project !== null) {
                    return;
                }

                $fail('Project data is not set!');
            },
            'access_to_settings' => function (ProjectData $project, Closure $fail) {
                if ($project->level()->hasAccessToSettings()) {
                    return;
                }

                $fail('You have no access to settings for this project!');
            },
            'has_empty_repository' => function (ProjectData $project, Closure $fail) {
                if (!$project->hasEmptyRepository()) {
                    return;
                }

                $fail('This project is empty!');
            },
        ];

        return $this;
    }

    public function rule(string $name, Closure $callback): static
    {
        $this->validators[$name] = $callback;

        return $this;
    }

    public function validate(): static
    {
        foreach ($this->validators as $name => $validator) {
            $validator($this->project, fn (string $message) => $this->fail($name, $message));

            if ($this->failed() && $this->bail) {
                break;
            }
        }

        return $this;
    }

    public function failed(): bool
    {
        return !empty($this->failMessages);
    }

    public function getMessages(): array
    {
        return $this->failMessages;
    }

    protected function fail(string $name, string $message): void
    {
        $this->failMessages[$name][] = $message;
    }
}
