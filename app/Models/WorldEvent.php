<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorldEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'price_modifier' => 'float',
        'demand_modifier' => 'float',
        'fuel_modifier' => 'float',
        'risk_modifier' => 'float',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function commodity(): BelongsTo
    {
        return $this->belongsTo(Commodity::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('starts_at', '<=', now())
            ->where('ends_at', '>', now());
    }
}
