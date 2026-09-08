<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseRequisitionSmartSyncCostValueState extends Model
{
    protected $table = 'pr_smart_sync_cv_states';
    protected $guarded = [];

    protected $casts = [
        'initialized_at' => 'datetime',
        'last_full_started_at' => 'datetime',
        'last_full_completed_at' => 'datetime',
    ];
}
