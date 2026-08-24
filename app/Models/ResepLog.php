<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ResepLog extends Model
{
    use SoftDeletes;

    protected $table   = 'resep_logs';
    protected $guarded = [];

    protected $casts = [
        'changes_old' => 'array',
        'changes_new' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resep(): BelongsTo
    {
        return $this->belongsTo(Resep::class, 'resep_id');
    }

    public function bahanResep(): BelongsTo
    {
        return $this->belongsTo(BahanResep::class, 'bahan_resep_id');
    }
}
