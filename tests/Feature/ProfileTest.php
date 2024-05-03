<?php

use App\Filament\Dashboard\Pages\EditProfile;
use App\Models\User;
use App\Settings\GeneralSettings;
use DefStudio\Telegraph\Models\TelegraphBot;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\Str;
use Mockery\MockInterface;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->gitlabUserData = [
        // we set only required fields
        'id' => 1,
        'username' => 'john_smith',
        'name' => 'John Smith',
        'email' => 'john-smith@hexide-digital.com',
        'avatar_url' => 'http://localhost:3000/uploads/user/avatar/1/cd8.jpeg',
        'locked' => false,
    ];

    $this->user = User::factory([
        'gitlab_id' => $this->gitlabUserData['id'],
        'email' => $this->gitlabUserData['email'],
    ])->create();

    $this->actingAs($this->user);
});

it('renders form fields', function () {
    livewire(EditProfile::class)
        ->assertSuccessful()
        ->assertFormSet([
            'gitlab_token' => $this->user->gitlab_token,
            'name' => $this->user->name,
            'email' => $this->user->email,
        ])
        ->assertFormFieldIsVisible('gitlab_token')
        ->assertFormFieldIsVisible('name')
        ->assertFormFieldIsVisible('email')
        ->assertFormFieldIsVisible('password')
        ->assertFormFieldIsHidden('passwordConfirmation');
});

it('renders unchecked toggle when user not connected to telegram', function () {
    $this->user->update(['telegram_id' => null]);

    livewire(EditProfile::class)
        ->assertSuccessful()
        ->assertFormSet([
            'is_telegram_enabled' => false,
        ])
        ->assertViewHas('canConnectTelegram', true)
        ->assertFormFieldIsVisible('is_telegram_enabled')
        ->assertDontSee('Scan to join Telegram');
});

it('renders unchecked toggle when user has disabled telegram', function () {
    $this->user->update(['telegram_id' => 1, 'is_telegram_enabled' => false]);

    livewire(EditProfile::class)
        ->assertSuccessful()
        ->assertFormSet([
            'is_telegram_enabled' => false,
        ])
        ->assertViewHas('canConnectTelegram', true)
        ->assertFormFieldIsVisible('is_telegram_enabled')
        ->assertDontSee('Scan to join Telegram');
});

it('renders checked toggle when user connected to telegram', function () {
    $this->user->update(['is_telegram_enabled' => true, 'telegram_id' => 1]);

    livewire(EditProfile::class)
        ->assertSuccessful()
        ->assertFormSet([
            'is_telegram_enabled' => true,
        ])
        ->assertViewHas('canConnectTelegram', false)
        ->assertFormFieldIsVisible('is_telegram_enabled')
        ->assertDontSee('Scan to join Telegram');
});

it('shows validation if token is empty', function () {
    livewire(EditProfile::class)
        ->assertSuccessful()
        ->fillForm(['gitlab_token' => ''])
        ->call('save');
});

it('shows notification when user from new token is different', function () {
    $this->user->update([
        'gitlab_id' => 1,
        'gitlab_token' => 'secret_secret_token',
    ]);

    $this->mockGitlabManagerUsing(function (MockInterface $mock) {
        $this->gitlabUserData['id'] = 2;

        $users = $this->mock(Gitlab\Api\Users::class, function (MockInterface $mock) {
            $mock->shouldReceive('me')->andReturn($this->gitlabUserData);
        });

        $mock->shouldReceive('users')->once()->andReturn($users);
    }, authenticatesWithGitLabToken: 'new_secret_token');

    livewire(EditProfile::class)
        ->assertSuccessful()
        ->fillForm(['gitlab_token' => 'new_secret_token'])
        ->assertNotified('Detected token manipulation')
        ->assertFormSet(['gitlab_token' => 'secret_secret_token']);
});

it('shows toast when token is invalid', function () {
    $this->mockGitlabManagerUsing(function (MockInterface $mock) {
        $users = $this->mock(Gitlab\Api\Users::class, function (MockInterface $mock) {
            $mock->shouldReceive('me')->andThrow(new Gitlab\Exception\RuntimeException());
        });

        $mock->shouldReceive('users')->once()->andReturn($users);
    }, authenticatesWithGitLabToken: 'invalid_secret_token');

    livewire(EditProfile::class)
        ->assertSuccessful()
        ->fillForm(['gitlab_token' => 'invalid_secret_token'])
        ->assertNotified('Authentication error');
});

it('renders qr code component link was generated', function () {
    livewire(EditProfile::class)
        ->assertSuccessful()
        ->set('qr_link', fake()->url)
        ->assertFormFieldIsVisible('is_telegram_enabled')
        ->assertSee('Scan to join Telegram');
});

it('generates token and qr code to connect telegram', function () {
    \DefStudio\Telegraph\Facades\Telegraph::fake([
        \DefStudio\Telegraph\Telegraph::ENDPOINT_GET_BOT_INFO => ['ok' => true, 'result' => ['username' => 'deploy_bot']],
    ]);

    $this->user->update(['is_telegram_enabled' => false]);
    GeneralSettings::fake(['mainTelegramBot' => 'deploy_bot']);
    TelegraphBot::factory(['name' => 'deploy_bot'])->create();
    Str::createRandomStringsUsing(fn () => 'random_token');

    livewire(EditProfile::class)
        ->assertSuccessful()
        ->fillForm(['is_telegram_enabled' => true])
        ->assertFormSet(['is_telegram_enabled' => false])
        ->assertViewHas('canConnectTelegram', true)
        ->assertNotified('Telegram integration')
        ->assertFormFieldIsVisible('is_telegram_enabled')
        ->assertSee('Scan to join Telegram')
        ->assertViewHas('qr_link', fn (string $link) => str($link)->contains('random_token'));

    expect($this->user->refresh())->telegram_token->toEqual('random_token');
});
