<?php

use App\Domains\GitLab\GitLabService;
use App\Models\User;
use DefStudio\Telegraph\Models\TelegraphChat;
use DefStudio\Telegraph\Facades\Telegraph;

use function Pest\Laravel\postJson;

beforeEach(function () {
    Telegraph::fake();

    $this->chat = TelegraphChat::factory()->create();
    $this->messageData = [
        'message_id' => 20,
        'from' => [
            'id' => $this->chat->chat_id,
            'is_bot' => false,
        ],
        'chat' => [
            'id' => $this->chat->chat_id,
            'type' => 'private',
        ],
        'date' => now()->timestamp,
        'text' => '/help',
    ];
});

it('sends welcome message when user is not connected to telegram', function () {
    postJson(route('telegraph.webhook', $this->chat->bot), [
        'message' => $this->messageData,
    ])->assertNoContent();

    Telegraph::assertSent('Hello! To get started with me, you should connect your Telegram account to the Deploy Configurator.', exact: false);
});

it('sends help message when user', function () {
    User::factory()->telegramId($this->chat->chat_id)->create();
    $this->mock(GitLabService::class)->shouldReceive('authenticateUsing')->once()->andReturnSelf();

    postJson(route('telegraph.webhook', $this->chat->bot), [
        'message' => $this->messageData,
    ])->assertNoContent();

    Telegraph::assertSentData(DefStudio\Telegraph\Telegraph::ENDPOINT_MESSAGE, [
        'text' => [
            '/startconfiguration',
            '/cancel',
            '/retry',
            '/back',
            '/restart',
            '/step',
        ],
    ], exact: false);
});
