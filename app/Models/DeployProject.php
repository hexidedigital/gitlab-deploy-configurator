<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeployProject extends Model
{
    protected $attributes = [
        'deploy_payload' => '[]',
        'logs' => '[]',
    ];

    protected $fillable = [
        'user_id',
        'name',
        'stage',
        'project_gid',
        'created_from',
        'type',
        'status',
        'current_step',
        'deploy_payload',
        'logs',
        'started_at',
        'finished_at',
        'failed_at',
        'canceled_at',
        'fail_counts',
        'next_try_at',
        'exception',
    ];

    protected function casts(): array
    {
        return [
            'deploy_payload' => 'array',
            'logs' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'failed_at' => 'datetime',
            'canceled_at' => 'datetime',
            'next_try_at' => 'datetime',
            'exception' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
