<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyResearch extends Model
{
    protected $table = 'company_research';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'unlocked_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
