<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Driver extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'skill' => 'integer',
        'morale' => 'integer',
        'fatigue' => 'integer',
        'loyalty' => 'integer',
        'hazmat_licence' => 'boolean',
        'salary' => 'integer',
        'shipments_done' => 'integer',
    ];

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_DRIVING = 'driving';
    public const STATUS_RESTING = 'resting';

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE && $this->fatigue < 90;
    }

    /** Speed multiplier a driver contributes: skilled, rested drivers go faster. */
    public function speedFactor(): float
    {
        $skill = 0.85 + ($this->skill / 100) * 0.30;      // 0.85..1.15
        $fatiguePenalty = ($this->fatigue / 100) * 0.20;   // up to -0.20
        $moraleBonus = (($this->morale - 50) / 100) * 0.10; // -0.05..+0.05

        return max(0.6, $skill - $fatiguePenalty + $moraleBonus);
    }
}
