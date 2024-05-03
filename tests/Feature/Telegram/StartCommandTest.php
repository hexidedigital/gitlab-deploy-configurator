<?php

use App\Models\User;
use App\Settings\GeneralSettings;
use DefStudio\Telegraph\Models\TelegraphChat;

use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\postJson;


beforeEach(function () {
    GeneralSettings::fake(['released' => true]);

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
        // 'text' => '/start',
    ];
});

describe('when app is not released', function () {
    beforeEach(function () {
        GeneralSettings::fake(['released' => false]);
    });

    it('sends welcome message when user send first message without token', function () {
        postJson(route('telegraph.webhook', $this->chat->bot), [
            'message' => [
                ...$this->messageData,
                'text' => '/start',
            ],
        ])->assertNoContent();

        Telegraph::assertSent('Hello! To get started with me, you should connect your Telegram account to the Deploy Configurator.', exact: false);
    });

    it('says that registration is disabled when user send message with token', function () {
        postJson(route('telegraph.webhook', $this->chat->bot), [
            'message' => [
                ...$this->messageData,
                'text' => '/start 1234',
            ],
        ])->assertNoContent();

        Telegraph::assertSent('Registration is currently disabled', exact: false);
    });
});

it('sends welcome message when user send first message without token', function () {
    postJson(route('telegraph.webhook', $this->chat->bot), [
        'message' => [
            ...$this->messageData,
            'text' => '/start',
        ],
    ])->assertNoContent();

    Telegraph::assertSent('Hello! To get started with me, you should connect your Telegram account to the Deploy Configurator.', exact: false);
});

it('send fail message when can\'t find user by token', function () {
    assertDatabaseMissing(User::class, ['telegram_token' => '1234']);

    postJson(route('telegraph.webhook', $this->chat->bot), [
        'message' => [
            ...$this->messageData,
            'text' => '/start 1234',
        ],
    ])->assertNoContent();

    Telegraph::assertSent('Sorry, I can\'t find the user with this token', exact: false);
});

it('connects new user to telegram account', function () {
    $user = User::factory(['telegram_token' => '1234'])->create();

    postJson(route('telegraph.webhook', $this->chat->bot), [
        'message' => [
            ...$this->messageData,
            'text' => '/start 1234',
        ],
    ])->assertNoContent();

    expect($user->refresh())
        ->is_telegram_enabled->toBeTrue()
        ->telegram_id->toBe($this->chat->chat_id)
        ->telegram_user->toBeArray()
        ->telegram_token->toBeNull();

    Telegraph::assertSent('Welcome to Deploy Configurator', exact: false);
});

it('resets stored token when user has enabled and connected telegram account', function () {
    $user = User::factory(['telegram_token' => '1234'])->telegramId($this->chat->chat_id)->create();

    postJson(route('telegraph.webhook', $this->chat->bot), [
        'message' => [
            ...$this->messageData,
            'text' => '/start 1234',
        ],
    ])->assertNoContent();

    assertDatabaseMissing(User::class, ['telegram_token' => '1234']);

    expect($user->refresh())
        ->is_telegram_enabled->toBeTrue()
        ->telegram_id->toBe($this->chat->chat_id)
        ->telegram_user->toBeArray()
        ->telegram_token->toBeNull();

    Telegraph::assertSent('already connected', exact: false);
});

it('enable telegram account when user has token and is not enabled', function () {
    $user = User::factory(['telegram_token' => '1234'])
        ->telegramId($this->chat->chat_id)
        ->state(['is_telegram_enabled' => false]) // disable telegram
        ->create();

    postJson(route('telegraph.webhook', $this->chat->bot), [
        'message' => [
            ...$this->messageData,
            'text' => '/start 1234',
        ],
    ])->assertNoContent();

    expect($user->refresh())
        ->is_telegram_enabled->toBeTrue()
        ->telegram_id->toBe($this->chat->chat_id)
        ->telegram_user->toBeArray()
        ->telegram_token->toBeNull();

    Telegraph::assertSent('successfully connected', exact: false);
});
