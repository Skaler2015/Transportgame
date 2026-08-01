<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ListedCompany extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'base_price' => 'float',
        'share_price' => 'float',
        'dividend_yield' => 'float',
        'volatility' => 'float',
    ];

    /** Percentage change vs the reference base price. */
    public function changePct(): float
    {
        if ($this->base_price <= 0) {
            return 0.0;
        }

        return round(($this->share_price - $this->base_price) / $this->base_price * 100, 1);
    }
}
