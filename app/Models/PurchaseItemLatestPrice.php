<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItemLatestPrice extends Model
{
    public const SOURCE_TYPE_PI = 'PI';
    public const SOURCE_TYPE_PO = 'PO';
    public const SOURCE_TYPE_COST_VALUE = 'COST_VALUE';

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
