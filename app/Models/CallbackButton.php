<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CallbackButton extends Model
{
    protected $fillable = [
        'chat_context_id',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'collection',
        ];
    }
}
