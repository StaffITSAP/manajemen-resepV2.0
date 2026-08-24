<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AccurateJobOrder extends Model
{
    use SoftDeletes;

    protected $table   = 'accurate_job_orders';
    protected $guarded = [];

    protected $casts = [
        'trans_date' => 'date',
        'raw'        => 'array',
        'total_item'   => 'decimal:6',
        'total_amount' => 'decimal:6',
    ];

    public function items()
    {
        return $this->hasMany(AccurateJobOrderItem::class, 'job_order_id');
    }
    public function branch()
    {
        return $this->belongsTo(AccurateBranch::class, 'branch_id');
    }
    public function getDisplayTitleAttribute(): string
    {
        $tgl = $this->trans_date?->format('d/m/Y') ?? '-';
        return "{$this->number} • {$tgl}";
    }
}
