<?php

namespace App\Http\Telegram;

use App\Domains\GitLab\GitLabService;
use App\Exceptions\Halt;
use App\Filament\Dashboard\Pages\DeployConfigurator\SampleFormData;
use App\Models\ChatContext;
use App\Models\User;

trait WithChatContext
{
    protected ChatContext $chatContext;
    protected User $user;
    protected GitLabService $gitLabService;

    protected function makePreparationsForWork(): void
    {
        $this->fetchCurrentUserAccount();
        $this->setupChatContext();
        $this->initGitLabService();
    }

    protected function fetchCurrentUserAccount(): void
    {
        $this->user = User::query()
            ->where('is_telegram_enabled', true)
            ->where('telegram_id', $this->chat->chat_id)->firstOr(function () {
                $this->welcomeMessage();

                throw new Halt();
            });
    }

    protected function initGitLabService(): void
    {
        if (!isset($this->user)) {
            throw new Halt();
        }

        $this->gitLabService = resolve(GitLabService::class)->authenticateUsing($this->user->gitlab_token);
    }

    protected function setupChatContext(): void
    {
        if (!isset($this->user)) {
            throw new Halt();
        }

        $this->chatContext = ChatContext::firstOrCreate([
            'chat_id' => $this->chat->id,
            'user_id' => $this->user->id,
        ], [
            'current_command' => null,
            'context_data' => [],
            'state' => [],
        ]);
    }

    protected function resetChatContext(): void
    {
        $sampleFormData = new SampleFormData();

        $this->chatContext->update([
            'current_command' => null,
            'state' => [],
            'context_data' => [
                'projectInfo' => $sampleFormData->getProjectInfoData($this->user->gitlab_token),
                // reset selected ci cd options
                'ci_cd_options' => $sampleFormData->getCiCdOptions(),
                // reset stages
                'stages' => $sampleFormData->getSampleStages(),
            ],
        ]);

        $this->chatContext->callbackButtons()->delete();
    }
}
