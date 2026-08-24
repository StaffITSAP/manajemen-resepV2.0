<?php

namespace App\Filament\Resources\MasterBarangResource\Pages;

use App\Filament\Resources\MasterBarangResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateMasterBarang extends CreateRecord
{
    protected static string $resource = MasterBarangResource::class;
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
