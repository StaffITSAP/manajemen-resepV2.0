<?php

namespace Database\Seeders;

use App\Models\MasterSatuan;
use Illuminate\Database\Seeder;

class MasterSatuanSeeder extends Seeder
{
    public function run(): void
    {
        $satuans = [
            ['nama' => 'Gram', 'kode' => 'GR', 'deskripsi' => 'Satuan gram', 'status_aktif' => true],
            ['nama' => 'Kilogram', 'kode' => 'KG', 'deskripsi' => 'Satuan kilogram', 'status_aktif' => true],
            ['nama' => 'Pcs', 'kode' => 'PCS', 'deskripsi' => 'Satuan pieces', 'status_aktif' => true],
            ['nama' => 'Liter', 'kode' => 'L', 'deskripsi' => 'Satuan liter', 'status_aktif' => true],
            ['nama' => 'Mililiter', 'kode' => 'ML', 'deskripsi' => 'Satuan mililiter', 'status_aktif' => true],
        ];

        foreach ($satuans as $satuan) {
            MasterSatuan::create($satuan);
        }
    }
}