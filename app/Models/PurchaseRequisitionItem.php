<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequisitionItem extends Model
{
    protected $table = 'purchase_requisition_items';
    protected $guarded = [];

    protected $casts = [
        'quantity'                   => 'decimal:6',
        'required_date'              => 'date',
        'latest_purchase_unit_price' => 'decimal:8',
        'total_price'                => 'decimal:8',
        'source_purchase_order_date' => 'date',
    ];

    public function purchaseRequisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function accurateItem(): BelongsTo
    {
        return $this->belongsTo(AccurateItem::class, 'accurate_item_id');
    }
}
