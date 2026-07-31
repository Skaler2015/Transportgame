<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'cash' => 'integer',
        'debt' => 'integer',
        'level' => 'integer',
        'xp' => 'integer',
        'reputation' => 'integer',
        'research_points' => 'integer',
        'shipments_completed' => 'integer',
        'shipments_failed' => 'integer',
        'lifetime_revenue' => 'integer',
        'lifetime_expenses' => 'integer',
        'last_tick_at' => 'datetime',
    ];

    // XP required to reach the NEXT level from the given level.
    public const XP_CURVE_BASE = 1000;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function headquarters(): BelongsTo
    {
        return $this->belongsTo(City::class, 'headquarters_city_id');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function research(): HasMany
    {
        return $this->hasMany(CompanyResearch::class);
    }

    /** XP threshold to advance from $level to $level + 1. */
    public static function xpForLevel(int $level): int
    {
        return (int) round(self::XP_CURVE_BASE * ($level ** 1.6));
    }

    /** Estimated net worth: cash + fleet value + warehouses - debt. */
    public function estimatedValue(): int
    {
        $fleet = $this->vehicles()
            ->join('vehicle_models', 'vehicles.vehicle_model_id', '=', 'vehicle_models.id')
            ->sum('vehicle_models.price');

        return (int) ($this->cash + $fleet - $this->debt);
    }
}
