<?php

namespace App\Models;

use App\Models\Traits\AuditableProduksiLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemProduksi extends Model
{
    use HasFactory, SoftDeletes, AuditableProduksiLog;

    protected $table = 'item_produksi';
    protected $guarded = [];

    protected $casts = [
        'jumlah'        => 'decimal:2',
        'jumlah_aktual' => 'decimal:2',
        'selisih'       => 'decimal:2',
        'enable_bahan_tambahan'  => 'boolean',
    ];

    public function produksi(): BelongsTo
    {
        return $this->belongsTo(Produksi::class, 'produksi_id');
    }

    public function barang(): BelongsTo
    {
        return $this->belongsTo(MasterBarang::class, 'barang_setengah_jadi_id');
    }

    public function barangSetengahJadi(): BelongsTo
    {
        return $this->belongsTo(MasterBarang::class, 'barang_setengah_jadi_id');
    }

    protected static function boot()
    {
        parent::boot();

        // hitung selisih item (rencana - aktual) saat simpan
        static::saving(function ($m) {
            if (!is_null($m->jumlah_aktual)) {
                $m->selisih = (float)$m->jumlah - (float)$m->jumlah_aktual;
            }
        });
    }
    public function bahan() // MasterBarang (bahan_id di item_produksi)
    {
        return $this->belongsTo(\App\Models\MasterBarang::class, 'bahan_id');
    }
    public function bahanProduksi(): HasMany
    {
        return $this->hasMany(BahanProduksi::class, 'item_produksi_id');
    }
    public function bahanOtomatis(): HasMany
    {
        return $this->hasMany(BahanProduksi::class, 'item_produksi_id')
            ->where('is_manual', false);
    }
    public function bahanTambahan()
    {
        return $this->hasMany(BahanProduksi::class, 'item_produksi_id')
            ->where('is_manual', true);
    }

    public static function buildBahanFromResep(float $jumlahItem, ?int $barangSetengahJadiId): array
    {
        if (!$barangSetengahJadiId) return [];

        $resep = \App\Models\Resep::with('bahanResep.bahan.satuan')
            ->where('barang_setengah_jadi_id', $barangSetengahJadiId)
            ->first();

        if (!$resep) return [];

        $den = (float) max(1, $resep->jumlah_barang_setengah_jadi);
        $factor = (float) $jumlahItem / $den;

        $rows = [];
        foreach ($resep->bahanResep as $br) {
            $rows[] = [
                'bahan_id'          => $br->bahan_id,
                'jumlah'            => round(((float)$br->jumlah) * $factor, 2),
                'jumlah_aktual'     => null,
                'selisih'           => 0,
                'total_produksi'    => 0,    // NEW
                'selisih_produksi'  => 0,    // NEW
                'keterangan_aktual' => null,
                'is_manual'         => false,
            ];
        }
        return $rows;
    }

    protected static function booted(): void
    {
        // cascade delete/restore ke bahan (tetap)
        static::deleting(function (ItemProduksi $item) {
            $force = method_exists($item, 'isForceDeleting') && $item->isForceDeleting();
            $item->bahanProduksi()->withTrashed()->get()->each(function (BahanProduksi $bp) use ($force) {
                $force ? $bp->forceDelete() : $bp->delete();
            });
        });

        static::restoring(function (ItemProduksi $item) {
            $item->bahanProduksi()->onlyTrashed()->restore();
        });

        // === OTOMATIS: hitung jumlah_aktual bahan dari rasio ===
        static::saved(function (ItemProduksi $item) {
            $den   = (float) ($item->jumlah ?? 0);
            $den   = $den > 0 ? $den : 1.0; // hindari bagi 0
            $ratio = (float) ($item->jumlah_aktual ?? 0) / $den;

            $item->bahanProduksi()->get()->each(function (BahanProduksi $bp) use ($ratio) {
                $planned  = (float) ($bp->jumlah ?? 0);
                $actual   = $ratio * $planned;   // otomatis
                $bp->jumlah_aktual = $actual;
                $bp->selisih       = $planned - $actual;

                // NEW: default selisih_produksi = total_produksi - jumlah_aktual (kalau user belum set)
                if (!$bp->isDirty('selisih_produksi')) {
                    $bp->selisih_produksi = (float)($bp->total_produksi ?? 0) - (float)$bp->jumlah_aktual;
                }

                $bp->saveQuietly();
            });
        });
    }
    public function hasil()
    {
        return $this->hasMany(ItemProduksiHasil::class, 'item_produksi_id');
    }
}
