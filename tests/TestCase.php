<?php

namespace Tests;

use App\Domains\GitLab\GitLabService;
use Closure;
use GrahamCampbell\GitLab\GitLabManager;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mockery\MockInterface;

abstract class TestCase extends BaseTestCase
{
    public function mockGitlabManagerUsing(Closure $callback, string $authenticatesWithGitLabToken = 'some_secret_token'): self
    {
        $this->mock(GitLabService::class, function (MockInterface $mock) use ($authenticatesWithGitLabToken, $callback) {
            $gitLabManager = $this->mock(GitLabManager::class, $callback);

            $mock->shouldReceive('authenticateUsing')->with($authenticatesWithGitLabToken)->once()->andReturnSelf();
            $mock->shouldReceive('gitLabManager')->once()->andReturn($gitLabManager);
        });

        return $this;
    }
}
