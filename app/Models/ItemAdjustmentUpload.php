<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class ItemAdjustmentUpload extends Model
{
    use HasFactory, SoftDeletes;
    protected $fillable = [
        'user_id',
        'original_name',
        'path',
        'trans_date',
        'description',
        'payload',
        'response',
        'accurate_number',
        'accurate_id',
        'status',
        'error_message',
        'adjustment_account_no',
    ];

    protected $casts = [
        'trans_date' => 'date',
        'payload'    => 'array',
        'response'   => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
