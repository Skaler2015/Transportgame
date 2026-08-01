<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trailer extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'condition' => 'float',
    ];

    public const STATUS_IDLE = 'idle';
    public const STATUS_EN_ROUTE = 'en_route';

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(TrailerModel::class, 'trailer_model_id');
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_IDLE && $this->condition > 10;
    }
}
