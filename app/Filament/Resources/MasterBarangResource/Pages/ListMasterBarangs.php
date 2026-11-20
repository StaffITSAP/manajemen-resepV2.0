<?php

namespace App\Filament\Resources\MasterBarangResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\MasterBarangResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMasterBarangs extends ListRecords
{
    protected static string $resource = MasterBarangResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
