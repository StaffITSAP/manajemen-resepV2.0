<?php

namespace App\Filament\Resources\ResepResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\ResepResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListReseps extends ListRecords
{
    protected static string $resource = ResepResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
