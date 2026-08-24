<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Traits\AuditableResepLog;


class BahanResep extends Model
{
    use HasFactory, SoftDeletes, AuditableResepLog;

    protected $table = 'bahan_resep';
    protected $guarded = [];

    public function resep(): BelongsTo
    {
        return $this->belongsTo(Resep::class, 'resep_id');
    }

    public function bahan(): BelongsTo
    {
        return $this->belongsTo(MasterBarang::class, 'bahan_id');
    }
}
