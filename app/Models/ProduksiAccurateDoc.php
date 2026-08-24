<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProduksiAccurateDoc extends Model
{
    protected $table = 'produksi_accurate_docs';
    protected $guarded = [];

    protected $casts = [
        'trans_date'        => 'date',
        'total_amount'      => 'decimal:8',      // selalu string "361808.29482300"
        'allocation_amount' => 'decimal:8',
        'payload'           => 'array',
        'response'          => 'array',
    ];

    public function produksi(): BelongsTo
    {
        return $this->belongsTo(Produksi::class, 'produksi_id');
    }

    public function scopeJo($q)
    {
        return $q->where('doc_type', 'JOB_ORDER');
    }
    public function scopeRo($q)
    {
        return $q->where('doc_type', 'ROLL_OVER');
    }
}
