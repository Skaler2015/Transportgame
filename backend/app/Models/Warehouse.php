<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Warehouse extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'capacity' => 'integer',
        'tier' => 'integer',
        'cold_storage' => 'boolean',
        'hazmat_certified' => 'boolean',
        'automated' => 'boolean',
        'upkeep' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }
}
