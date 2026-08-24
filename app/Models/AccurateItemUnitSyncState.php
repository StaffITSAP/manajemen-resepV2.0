<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccurateItemUnitSyncState extends Model
{
    protected $table = 'accurate_item_unit_sync_states';
    protected $guarded = [];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];

    public function accurateItem(): BelongsTo
    {
        return $this->belongsTo(AccurateItem::class, 'accurate_item_id');
    }
}
