<?php

namespace App\Filament\Widgets;

use App\Models\Produksi;
use App\Models\Resep;
use App\Models\MasterBarang;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatistikProduksi extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Total Produksi', Produksi::count())
                ->description('Jumlah keseluruhan produksi')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),
            Stat::make('Produksi Bulan Ini', Produksi::whereMonth('tanggal', now()->month)->count())
                ->description('Produksi bulan ' . now()->monthName)
                ->descriptionIcon('heroicon-m-calendar')
                ->color('info'),
            Stat::make('Total Resep', Resep::count())
                ->description('Jumlah resep aktif')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('primary'),
            Stat::make('Barang Stok Minimal', MasterBarang::whereColumn('stok', '<=', 'stok_minimal')->count())
                ->description('Perlu restock')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger'),
        ];
    }
}