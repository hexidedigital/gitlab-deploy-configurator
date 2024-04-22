<?php

namespace App\Models;

use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class ChatContext extends Model
{
    protected $attributes = [
        'state' => '[]',
        'context_data' => '[]',
    ];

    protected $fillable = [
        'chat_id',
        'user_id',
        'current_command',
        'state',
        'context_data',
    ];

    protected function casts(): array
    {
        return [
            'state' => 'collection',
            'context_data' => 'collection',
        ];
    }

    public function getProjectId(): ?int
    {
        return data_get($this->context_data, 'projectInfo.project_id');
    }

    public function pushToState(Collection|array $data): self
    {
        return tap($this, fn () => $this->fill([
            'state' => $this->state->merge($data),
        ]));
    }

    public function pushToData(Collection|array $data): self
    {
        return tap($this, fn () => $this->fill([
            'context_data' => $this->context_data->merge($data),
        ]));
    }

    public function telegramChat(): BelongsTo
    {
        return $this->belongsTo(TelegraphChat::class, 'chat_id');
    }

    public function callbackButtons(): HasMany
    {
        return $this->hasMany(CallbackButton::class, 'chat_context_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
