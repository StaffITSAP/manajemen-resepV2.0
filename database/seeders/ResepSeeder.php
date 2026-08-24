<?php

namespace Database\Seeders;

use App\Models\Resep;
use App\Models\MasterBarang;
use App\Models\BahanResep;
use Illuminate\Database\Seeder;

class ResepSeeder extends Seeder
{
    public function run(): void
    {
        $tahuRebung = MasterBarang::where('nama', 'Tahu Rebung')->first();
        $kueBolu = MasterBarang::where('nama', 'Kue Bolu')->first();

        // Resep Tahu Rebung
        $resep1 = Resep::create([
            'nama' => 'Resep Tahu Rebung Special',
            'barang_setengah_jadi_id' => $tahuRebung->id,
            'jumlah_barang_setengah_jadi' => 10,
            'deskripsi' => 'Resep tahu rebung dengan citarasa special',
            'cara_pembuatan' => '1. Campur semua bahan\n2. Kukus selama 30 menit\n3. Sajikan',
            'status_aktif' => true,
        ]);

        // Bahan untuk Tahu Rebung
        $bahanTahuRebung = [
            ['resep_id' => $resep1->id, 'bahan_id' => MasterBarang::where('nama', 'Tepung Terigu')->first()->id, 'jumlah' => 500, 'catatan' => 'Tepung terigu protein tinggi'],
            ['resep_id' => $resep1->id, 'bahan_id' => MasterBarang::where('nama', 'Telur')->first()->id, 'jumlah' => 3, 'catatan' => 'Telur ayam segar'],
        ];

        foreach ($bahanTahuRebung as $bahan) {
            BahanResep::create($bahan);
        }

        // Resep Kue Bolu
        $resep2 = Resep::create([
            'nama' => 'Resep Kue Bolu Lembut',
            'barang_setengah_jadi_id' => $kueBolu->id,
            'jumlah_barang_setengah_jadi' => 5,
            'deskripsi' => 'Resep kue bolu lembut dan enak',
            'cara_pembuatan' => '1. Kocok telur dan gula\n2. Tambahkan tepung dan mentega\n3. Panggang 30 menit',
            'status_aktif' => true,
        ]);

        // Bahan untuk Kue Bolu
        $bahanKueBolu = [
            ['resep_id' => $resep2->id, 'bahan_id' => MasterBarang::where('nama', 'Tepung Terigu')->first()->id, 'jumlah' => 300, 'catatan' => ''],
            ['resep_id' => $resep2->id, 'bahan_id' => MasterBarang::where('nama', 'Gula Pasir')->first()->id, 'jumlah' => 200, 'catatan' => ''],
            ['resep_id' => $resep2->id, 'bahan_id' => MasterBarang::where('nama', 'Telur')->first()->id, 'jumlah' => 4, 'catatan' => ''],
            ['resep_id' => $resep2->id, 'bahan_id' => MasterBarang::where('nama', 'Mentega')->first()->id, 'jumlah' => 150, 'catatan' => 'Lelehkan terlebih dahulu'],
        ];

        foreach ($bahanKueBolu as $bahan) {
            BahanResep::create($bahan);
        }
    }
}