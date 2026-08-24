<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccurateJobOrderItem extends Model
{
    protected $table   = 'accurate_job_order_items';
    protected $guarded = [];

    protected $casts = [
        'raw'      => 'array',
        'quantity' => 'decimal:6',
        'amount'   => 'decimal:6',
    ];

    public function jobOrder()
    {
        return $this->belongsTo(AccurateJobOrder::class, 'job_order_id');
    }

    /**
     * Porsi (%):
     * - Jika item hasil (raw.item.materialProduced === true) -> 100
     * - Selain itu: (qty item / qty item hasil) * 100
     * - Jika tidak ada item hasil / qty hasil = 0 -> null
     */
    public function getPorsiAttribute(): ?float
    {
        $isProduced = (bool) data_get($this->raw, 'item.materialProduced', false);
        if ($isProduced) {
            return 100.0;
        }

        $parent = $this->jobOrder;
        if (!$parent) {
            return null;
        }

        // Cari item hasil (pertama yang materialProduced = true)
        $producedItem = $parent->items->first(function ($i) {
            return (bool) data_get($i->raw, 'item.materialProduced', false) === true;
        });

        $producedQty = (float) ($producedItem->quantity ?? 0);
        if ($producedQty <= 0) {
            return null;
        }

        $thisQty = (float) ($this->quantity ?? 0);
        $percent = ($thisQty / $producedQty) * 100;

        // Bulatkan 2 desimal
        return round($percent, 2);
    }
}
