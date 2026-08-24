<?php

namespace App\Models;

use App\Models\Traits\AuditableProduksiLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class BahanProduksi extends Model
{
    use HasFactory, SoftDeletes, AuditableProduksiLog;

    protected $table = 'bahan_produksi';
    protected $guarded = [];

    protected $casts = [
        'jumlah'            => 'decimal:2',
        'jumlah_aktual'     => 'decimal:2',
        'selisih'           => 'decimal:2',
        'total_produksi'    => 'decimal:2',   // NEW
        'selisih_produksi'  => 'decimal:2',   // NEW
        'is_manual'         => 'boolean',
    ];

    public function itemProduksi(): BelongsTo
    {
        return $this->belongsTo(ItemProduksi::class, 'item_produksi_id');
    }

    public function bahan(): BelongsTo
    {
        return $this->belongsTo(MasterBarang::class, 'bahan_id');
    }

    protected static function booted(): void
    {
        static::saving(function (BahanProduksi $m) {
            // jaga default
            $m->total_produksi ??= 0;

            // selisih rencana vs aktual (yg lama, tetap)
            if (!is_null($m->jumlah) && !is_null($m->jumlah_aktual)) {
                $m->selisih = (float)$m->jumlah - (float)$m->jumlah_aktual;
            }

            // NEW: selisih_produksi (default dihitung total_produksi - jumlah_aktual)
            // Kalau user tidak mengubah manual, kita hitung ulang otomatis.
            if (!$m->isDirty('selisih_produksi')) {
                $m->selisih_produksi = (float)($m->total_produksi ?? 0) - (float)($m->jumlah_aktual ?? 0);
            }
        });
    }

    // status opsional
    public function getStatusAttribute(): string
    {
        if ($this->jumlah_aktual === null) return 'belum_diinput';
        if ($this->selisih > 0) return 'lebih';
        if ($this->selisih < 0) return 'kurang';
        return 'sesuai';
    }
}
