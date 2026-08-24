<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class MasterBarang extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'master_barang';
    protected $guarded = [];

    protected $casts = [
        'jenis' => 'string', // Pastikan tidak ada default untuk 'jadi'
    ];

    public function satuan(): BelongsTo
    {
        return $this->belongsTo(MasterSatuan::class, 'satuan_id');
    }

    public function resepSebagaiBarangSetengahJadi(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Resep::class, 'barang_setengah_jadi_id');
    }

    public function bahanResep(): HasMany
    {
        return $this->hasMany(BahanResep::class, 'bahan_id');
    }

    public function itemProduksi(): HasMany
    {
        return $this->hasMany(ItemProduksi::class, 'barang_setengah_jadi_id');
    }
}
