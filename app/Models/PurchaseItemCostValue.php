<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItemCostValue extends Model
{
    protected $table = 'purchase_item_cost_values';
    protected $guarded = [];

    protected $casts = [
        'unit_price' => 'decimal:8',
        'balance_unit_cost' => 'decimal:8',
        'ratio' => 'decimal:12',
        'balance_total_cost' => 'decimal:8',
        'synced_at' => 'datetime',
    ];

    public function accurateItem(): BelongsTo
    {
        return $this->belongsTo(AccurateItem::class, 'accurate_item_id');
    }
}
