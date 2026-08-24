<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccuratePurchaseOrderSyncState extends Model
{
    protected $table = 'accurate_purchase_order_sync_states';
    protected $guarded = [];

    protected $casts = [
        'purchase_order_date' => 'date',
        'last_synced_at' => 'datetime',
    ];
}
