<?php

namespace App\Models;

use App\Enums\Role;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser, HasAvatar
{
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'gitlab_token',
        'gitlab_id',
        'avatar_url',
        'role',
        'is_telegram_enabled',
        'telegram_id',
        'telegram_user',
        'telegram_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'gitlab_token' => 'encrypted',
            'role' => Role::class,
            'telegram_user' => 'array',
        ];
    }

    public function isRoot(): bool
    {
        return $this->hasMinAccess(Role::Root);
    }

    public function hasMinAccess(Role $role): bool
    {
        return !is_null($this->role) && $this->role->hasAccess($role);
    }

    public function canRecieveTelegramMessage(): bool
    {
        return $this->is_telegram_enabled && $this->telegram_id;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar_url;
    }
}
