<?php

namespace App\Models;

use App\Models\Commodity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrailerModel extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'price' => 'integer',
        'capacity_weight' => 'float',
        'capacity_volume' => 'float',
        'can_reefer' => 'boolean',
        'can_tanker' => 'boolean',
        'can_hazmat' => 'boolean',
        'unlock_level' => 'integer',
    ];

    public function trailers(): HasMany
    {
        return $this->hasMany(Trailer::class);
    }

    /** Can this trailer type legally carry the given commodity? */
    public function canCarry(Commodity $commodity): bool
    {
        if ($commodity->requires_reefer && ! $this->can_reefer) {
            return false;
        }
        if ($commodity->requires_tanker && ! $this->can_tanker) {
            return false;
        }
        if ($commodity->is_hazardous && ! $this->can_hazmat) {
            return false;
        }

        return true;
    }
}
