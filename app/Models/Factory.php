<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Factory extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'level' => 'integer',
        'lifetime_output' => 'integer',
        'last_produced_at' => 'datetime',
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** The config recipe this factory runs, or null if it was removed. */
    public function recipeConfig(): ?array
    {
        return config('transoria.manufacturing.recipes.'.$this->recipe);
    }

    /** Throughput/cost multiplier for the current level. */
    public function levelMultiplier(): float
    {
        $step = (float) config('transoria.manufacturing.level_step', 0.6);

        return 1 + $step * ($this->level - 1);
    }
}
