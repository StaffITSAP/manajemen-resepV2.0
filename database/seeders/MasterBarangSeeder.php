<?php

namespace Database\Seeders;

use App\Models\MasterBarang;
use App\Models\MasterSatuan;
use Illuminate\Database\Seeder;

class MasterBarangSeeder extends Seeder
{
    public function run(): void
    {
        $gram = MasterSatuan::where('kode', 'GR')->first();
        $pcs = MasterSatuan::where('kode', 'PCS')->first();
        $kg = MasterSatuan::where('kode', 'KG')->first();

        $barangs = [
            // Bahan
            ['nama' => 'Tepung Terigu', 'satuan_id' => $gram->id, 'jenis' => 'bahan', 'stok' => 10000, 'stok_minimal' => 5000, 'status_aktif' => true],
            ['nama' => 'Gula Pasir', 'satuan_id' => $gram->id, 'jenis' => 'bahan', 'stok' => 8000, 'stok_minimal' => 3000, 'status_aktif' => true],
            ['nama' => 'Telur', 'satuan_id' => $pcs->id, 'jenis' => 'bahan', 'stok' => 100, 'stok_minimal' => 50, 'status_aktif' => true],
            ['nama' => 'Mentega', 'satuan_id' => $gram->id, 'jenis' => 'bahan', 'stok' => 5000, 'stok_minimal' => 2000, 'status_aktif' => true],
            
            // Barang 1/2 Jadi
            ['nama' => 'Adonan Kue', 'satuan_id' => $kg->id, 'jenis' => 'setengah_jadi', 'stok' => 20, 'stok_minimal' => 10, 'status_aktif' => true],
            ['nama' => 'Cream Frosting', 'satuan_id' => $gram->id, 'jenis' => 'setengah_jadi', 'stok' => 5000, 'stok_minimal' => 2000, 'status_aktif' => true],
            
            // Barang setengah_jadi
            ['nama' => 'Kue Bolu', 'satuan_id' => $pcs->id, 'jenis' => 'setengah_jadi', 'stok' => 50, 'stok_minimal' => 20, 'status_aktif' => true],
            ['nama' => 'Kue Tart', 'satuan_id' => $pcs->id, 'jenis' => 'setengah_jadi', 'stok' => 10, 'stok_minimal' => 5, 'status_aktif' => true],
            ['nama' => 'Tahu Rebung', 'satuan_id' => $pcs->id, 'jenis' => 'setengah_jadi', 'stok' => 30, 'stok_minimal' => 15, 'status_aktif' => true],
        ];

        foreach ($barangs as $barang) {
            MasterBarang::create($barang);
        }
    }
}