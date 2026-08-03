<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'capacity' => 'integer',
        'tier' => 'integer',
        'cold_storage' => 'boolean',
        'hazmat_certified' => 'boolean',
        'automated' => 'boolean',
        'staff_level' => 'integer',
        'security_level' => 'integer',
        'upkeep' => 'integer',
    ];

    /** Usable capacity after staff (forklifts/throughput) and secure racking. */
    public function effectiveCapacity(): int
    {
        $cfg = config('transoria.warehouse');
        $bonus = 1
            + $cfg['staff_capacity_bonus'] * (int) $this->staff_level
            + $cfg['security_capacity_bonus'] * (int) $this->security_level;

        return (int) round($this->capacity * $bonus);
    }

    /** Trade spread after automation + staff efficiency (per side). */
    public function effectiveSpread(string $base): float
    {
        $cfg = config('transoria.warehouse');
        $spread = (float) $cfg[$base]
            - ($this->automated ? $cfg['automation_spread_cut'] : 0)
            - $cfg['staff_spread_cut'] * (int) $this->staff_level;

        return max($cfg['min_spread'], $spread);
    }

    /** Can this warehouse legally store the given commodity? */
    public function canStore(Commodity $commodity): bool
    {
        if ($commodity->is_perishable && ! $this->cold_storage) {
            return false;
        }
        if ($commodity->is_hazardous && ! $this->hazmat_certified) {
            return false;
        }

        return true;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(WarehouseInventory::class);
    }

    public function usedCapacity(): int
    {
        return (int) $this->inventory()->sum('units');
    }
}
