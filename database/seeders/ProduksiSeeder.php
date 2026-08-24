<?php

namespace Database\Seeders;

use App\Models\Produksi;
use App\Models\ItemProduksi;
use App\Models\MasterBarang;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProduksiSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::first();
        $tahuRebung = MasterBarang::where('nama', 'Tahu Rebung')->first();
        $kueBolu = MasterBarang::where('nama', 'Kue Bolu')->first();

        // Produksi 1
        $produksi1 = Produksi::create([
            'nomor_produksi' => 'PRD-' . date('Ymd') . '-001',
            'tanggal' => now(),
            'status' => 'selesai',
            'catatan' => 'Produksi tahu rebung batch pertama',
            'user_id' => $user->id,
        ]);

        ItemProduksi::create([
            'produksi_id' => $produksi1->id,
            'barang_setengah_jadi_id' => $tahuRebung->id,
            'jumlah' => 20,
        ]);

        // Produksi 2
        $produksi2 = Produksi::create([
            'nomor_produksi' => 'PRD-' . date('Ymd') . '-002',
            'tanggal' => now()->subDay(),
            'status' => 'diproses',
            'catatan' => 'Produksi kue bolu untuk pesanan',
            'user_id' => $user->id,
        ]);

        ItemProduksi::create([
            'produksi_id' => $produksi2->id,
            'barang_setengah_jadi_id' => $kueBolu->id,
            'jumlah' => 15,
        ]);
    }
}