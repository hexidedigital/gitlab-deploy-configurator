<?php

namespace Database\Factories;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => Role::Developer,
            'gitlab_id' => fake()->unique()->randomNumber(),
            'gitlab_token' => Str::random(10),
            'avatar_url' => fake()->imageUrl(),
            'is_telegram_enabled' => false,
            'telegram_id' => null,
            'telegram_token' => null,
            'telegram_user' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function telegramId(int $id): static
    {
        return $this->state(fn (array $attributes) => [
            'is_telegram_enabled' => true,
            'telegram_id' => $id,
        ]);
    }
}
