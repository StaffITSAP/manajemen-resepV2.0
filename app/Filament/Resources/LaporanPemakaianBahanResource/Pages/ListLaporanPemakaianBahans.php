<?php

namespace App\Filament\Resources\LaporanPemakaianBahanResource\Pages;

use App\Filament\Resources\LaporanPemakaianBahanResource;
use Filament\Resources\Pages\ListRecords;

class ListLaporanPemakaianBahans extends ListRecords
{
    protected static string $resource = LaporanPemakaianBahanResource::class;

    protected static ?string $title = 'Laporan Pemakaian Bahan';

    protected function getHeaderActions(): array
    {
        return [];
    }

    // 🧩 Tambahkan ini:
    protected function getViewData(): array
    {
        return [
            'title' => 'Laporan Pemakaian Bahan',
            'icon'  => 'heroicon-o-document-chart-bar',
        ];
    }
}
