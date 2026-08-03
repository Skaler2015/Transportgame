<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseInventory extends Model
{
    protected $table = 'warehouse_inventory';

    protected $guarded = ['id'];

    protected $casts = [
        'units' => 'integer',
        'avg_unit_cost' => 'float',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function commodity(): BelongsTo
    {
        return $this->belongsTo(Commodity::class);
    }
}
