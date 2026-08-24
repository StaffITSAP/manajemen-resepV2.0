<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItemLatestPrice extends Model
{
    protected $table = 'purchase_item_latest_prices';
    protected $guarded = [];

    protected $casts = [
        'unit_price'          => 'decimal:8',
        'purchase_order_date' => 'date',
        'source_updated_at'   => 'datetime',
        'synced_at'           => 'datetime',
    ];

    public function accurateItem(): BelongsTo
    {
        return $this->belongsTo(AccurateItem::class, 'accurate_item_id');
    }
}
