<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequisitionActivityLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'changes' => 'array',
    ];

    public function purchaseRequisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
