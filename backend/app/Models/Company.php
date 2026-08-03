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
        'guild_contribution' => 'integer',
        'last_tick_at' => 'datetime',
        'missions_generated_at' => 'datetime',
        'onboarded_at' => 'datetime',
        'tutorial_step' => 'integer',
        'is_ai' => 'boolean',
        'ai_fleet_size' => 'integer',
        'ai_state' => 'array',
        'ai_bankrupt_at' => 'datetime',
    ];

    /** Rival firms run by the world, not by a human player. */
    public function scopeAi($query)
    {
        return $query->where('is_ai', true);
    }

    /** Human-owned companies only (everything the player sees as "theirs"). */
    public function scopeHuman($query)
    {
        return $query->where('is_ai', false);
    }

    public function isAi(): bool
    {
        return (bool) $this->is_ai;
    }

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

    public function guild(): BelongsTo
    {
        return $this->belongsTo(Guild::class);
    }

    public function missions(): HasMany
    {
        return $this->hasMany(Mission::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function tradeListings(): HasMany
    {
        return $this->hasMany(TradeListing::class, 'seller_company_id');
    }

    /** XP threshold to advance from $level to $level + 1. */
    public static function xpForLevel(int $level): int
    {
        return (int) round(self::XP_CURVE_BASE * ($level ** 1.6));
    }

    /** Estimated net worth: cash + fleet value + warehouses - debt. */
    public function estimatedValue(): int
    {
        // AI rivals hold their fleet as a counter, not Vehicle rows — value it
        // at the standard truck price so they rank fairly against players.
        if ($this->isAi()) {
            $truck = (int) config('transoria.ai.truck_capex', 220000_00);

            return (int) ($this->cash + $this->ai_fleet_size * $truck - $this->debt);
        }

        $fleet = $this->vehicles()
            ->join('vehicle_models', 'vehicles.vehicle_model_id', '=', 'vehicle_models.id')
            ->sum('vehicle_models.price');

        return (int) ($this->cash + $fleet - $this->debt);
    }
}
