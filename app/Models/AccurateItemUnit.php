<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class AccurateItemUnit extends Model
{
    protected $table = 'accurate_item_units';
    protected $guarded = [];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    public function accurateItem(): BelongsTo
    {
        return $this->belongsTo(AccurateItem::class, 'accurate_item_id');
    }

    protected static function booted(): void
    {
        static::saving(function (AccurateItemUnit $unit): void {
            $position = (int) $unit->position;

            if ($position < 1 || $position > 5) {
                throw new InvalidArgumentException('Posisi satuan Accurate harus antara 1 sampai 5.');
            }
        });
    }
}
