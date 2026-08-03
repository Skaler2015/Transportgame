<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketPrice extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'price' => 'float',
        'demand_index' => 'float',
        'recorded_at' => 'datetime',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function commodity(): BelongsTo
    {
        return $this->belongsTo(Commodity::class);
    }
}
