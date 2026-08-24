<?php

namespace App\Filament\Resources\LaporanPemakaianBahanResource\Pages;

use App\Filament\Resources\LaporanPemakaianBahanResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLaporanPemakaianBahan extends EditRecord
{
    protected static string $resource = LaporanPemakaianBahanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
