<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ProduksiLog extends Model
{
    use SoftDeletes;

    protected $table   = 'produksi_logs';
    protected $guarded = [];

    protected $casts = [
        'changes_old' => 'array',
        'changes_new' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function produksi(): BelongsTo
    {
        return $this->belongsTo(Produksi::class, 'produksi_id');
    }

    public function itemProduksi(): BelongsTo
    {
        return $this->belongsTo(ItemProduksi::class, 'item_produksi_id');
    }
    /** Target model dari log (Produksi / ItemProduksi / BahanProduksi) */
    public function model(): MorphTo
    {
        // kolom sudah bernama model_type & model_id
        return $this->morphTo(__FUNCTION__, 'model_type', 'model_id');
    }
}
