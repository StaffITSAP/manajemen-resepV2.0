<?php

namespace App\Filament\Resources\MasterSatuanResource\Pages;

use App\Filament\Resources\MasterSatuanResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateMasterSatuan extends CreateRecord
{
    protected static string $resource = MasterSatuanResource::class;
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
