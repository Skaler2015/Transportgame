<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guild extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'treasury' => 'integer',
        'member_count' => 'integer',
        'total_reputation' => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'owner_company_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /** Recompute cached member count and total reputation. */
    public function recalculate(): void
    {
        $this->member_count = $this->members()->count();
        $this->total_reputation = (int) $this->members()->sum('reputation');
        $this->save();
    }
}
