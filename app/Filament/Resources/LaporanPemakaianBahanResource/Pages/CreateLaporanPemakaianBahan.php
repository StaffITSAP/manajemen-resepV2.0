<?php

namespace App\Filament\Resources\LaporanPemakaianBahanResource\Pages;

use App\Filament\Resources\LaporanPemakaianBahanResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateLaporanPemakaianBahan extends CreateRecord
{
    protected static string $resource = LaporanPemakaianBahanResource::class;
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
