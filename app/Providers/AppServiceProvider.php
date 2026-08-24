<?php

namespace App\Providers;

use App\Models\ItemProduksi;
use App\Models\Resep;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ItemProduksi::saving(function (ItemProduksi $item) {
            // Paksa jumlah rencana ikut resep
            if ($item->barang_setengah_jadi_id) {
                $qty = Resep::where('barang_setengah_jadi_id', $item->barang_setengah_jadi_id)
                    ->value('jumlah_barang_setengah_jadi');
                if ($qty !== null) {
                    $item->jumlah = $qty;
                }
            }

            // Hitung selisih = jumlah - jumlah_aktual
            $item->selisih = (float) ($item->jumlah ?? 0) - (float) ($item->jumlah_aktual ?? 0);
        });
    }
}
