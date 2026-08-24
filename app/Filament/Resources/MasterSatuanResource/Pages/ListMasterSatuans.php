<?php

namespace App\Filament\Resources\MasterSatuanResource\Pages;

use App\Filament\Resources\MasterSatuanResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMasterSatuans extends ListRecords
{
    protected static string $resource = MasterSatuanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
