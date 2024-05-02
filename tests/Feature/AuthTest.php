<?php

use App\Filament\Dashboard\Pages\Register;
use App\Settings\GeneralSettings;
use Filament\Pages\Auth\Login;

use function Pest\Livewire\livewire;

it('redirects to login page', function () {
    $this->get('/')->assertRedirect('/login');
});

it('shows login page', function () {
    $this->get('/login')->assertStatus(200);
});

it('render link for registration', function () {
    livewire(Login::class)->assertActionExists('register')->assertOk();
});

it('shows register page when release setting enabled', function () {
    GeneralSettings::fake([
        'released' => true,
    ]);

    livewire(Register::class)->assertOk()->assertNotNotified('Registration disabled');
});

it('redirects to home page when release setting disabled', function () {
    GeneralSettings::fake([
        'released' => false,
    ]);

    livewire(Register::class)->assertRedirect('/')->assertNotified('Registration disabled');
});
