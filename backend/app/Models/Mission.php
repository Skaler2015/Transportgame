<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mission extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'target' => 'integer',
        'progress' => 'integer',
        'reward_cash' => 'integer',
        'reward_xp' => 'integer',
        'expires_at' => 'datetime',
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_EXPIRED = 'expired';

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function percent(): float
    {
        return $this->target <= 0 ? 100 : min(100, round($this->progress / $this->target * 100, 1));
    }
}
