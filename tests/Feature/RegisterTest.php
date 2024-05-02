<?php

use App\Filament\Dashboard\Pages\Register;
use App\Settings\GeneralSettings;

use Mockery\MockInterface;

use function Pest\Livewire\livewire;

beforeEach(function () {
    GeneralSettings::fake([
        'released' => true,
    ]);

    $this->gitlabUserData = [
        // we set only required fields
        'id' => 1,
        'username' => 'john_smith',
        'name' => 'John Smith',
        'email' => 'john-smith@hexide-digital.com',
        'avatar_url' => 'http://localhost:3000/uploads/user/avatar/1/cd8.jpeg',
        'locked' => false,
    ];
});

it('renders form only with token field', function () {
    livewire(Register::class)
        ->assertSuccessful()
        ->assertFormFieldIsVisible('gitlab_token')
        ->assertFormFieldIsHidden('name')
        ->assertFormFieldIsHidden('email')
        ->assertFormFieldIsHidden('password')
        ->assertFormFieldIsHidden('passwordConfirmation')
        ->assertActionHidden('register');
});

it('renders full form when token is valid', function () {
    livewire(Register::class)
        ->assertSuccessful()
        ->set('tokenValid', true)
        ->assertFormFieldIsHidden('gitlab_token')
        ->assertFormFieldIsVisible('name')
        ->assertFormFieldIsVisible('email')
        ->assertFormFieldIsVisible('password')
        ->assertFormFieldIsVisible('passwordConfirmation')
        ->assertActionVisible('register');
});

it('fills form fields when user enter valid token', function () {
    $this->mockGitlabManagerUsing(function (MockInterface $mock) {
        $users = $this->mock(Gitlab\Api\Users::class, function (MockInterface $mock) {
            $mock->shouldReceive('me')->andReturn($this->gitlabUserData);
        });

        $mock->shouldReceive('users')->once()->andReturn($users);
    });

    livewire(Register::class)
        ->assertSuccessful()
        ->assertViewHas('tokenValid', false)
        ->fillForm(['gitlab_token' => 'some_secret_token'])
        ->assertViewHas('tokenValid', true)
        ->assertFormSet([
            'name' => 'John Smith',
            'email' => 'john-smith@hexide-digital.com',
            'gitlab_id' => 1,
            'avatar_url' => 'http://localhost:3000/uploads/user/avatar/1/cd8.jpeg',
        ])
        ->assertNotNotified('Authentication error')
        ->assertNotNotified('Your GitLab account is locked');
});

it('shows validation if token is empty', function () {
    livewire(Register::class)
        ->assertSuccessful()
        ->assertViewHas('tokenValid', false)
        ->fillForm(['gitlab_token' => ''])
        ->call('register');
});

it('shows notification when user locked', function () {
    $this->mockGitlabManagerUsing(function (MockInterface $mock) {
        $this->gitlabUserData['locked'] = true;

        $users = $this->mock(Gitlab\Api\Users::class, function (MockInterface $mock) {
            $mock->shouldReceive('me')->andReturn($this->gitlabUserData);
        });

        $mock->shouldReceive('users')->once()->andReturn($users);
    });

    livewire(Register::class)
        ->assertSuccessful()
        ->assertViewHas('tokenValid', false)
        ->fillForm(['gitlab_token' => 'some_secret_token'])
        ->assertNotified('Your GitLab account is locked')
        ->assertFormSet(['name' => null, 'email' => null]);
});

it('shows toast when token is invalid', function () {
    $this->mockGitlabManagerUsing(function (MockInterface $mock) {
        $users = $this->mock(Gitlab\Api\Users::class, function (MockInterface $mock) {
            $mock->shouldReceive('me')->andThrow(new Gitlab\Exception\RuntimeException());
        });

        $mock->shouldReceive('users')->once()->andReturn($users);
    }, authenticatesWithGitLabToken: 'invalid_secret_token');

    livewire(Register::class)
        ->assertSuccessful()
        ->assertViewHas('tokenValid', false)
        ->fillForm(['gitlab_token' => 'invalid_secret_token'])
        ->assertNotified('Authentication error')
        ->assertFormSet(['name' => null, 'email' => null]);
});
