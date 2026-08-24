<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemProduksiHasil extends Model
{
    protected $table = 'item_produksi_hasil';

    protected $fillable = [
        'item_produksi_id',
        'nama_barang',
        'satuan',
        'jumlah_total',
        'keterangan',
    ];

    public function itemProduksi()
    {
        return $this->belongsTo(ItemProduksi::class, 'item_produksi_id');
    }
}
