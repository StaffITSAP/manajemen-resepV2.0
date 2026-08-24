<?php

namespace App\Filament\Resources\LogPerubahanResource\Pages;

use App\Filament\Resources\LogPerubahanResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLogPerubahans extends ListRecords
{
    protected static string $resource = LogPerubahanResource::class;

    // protected function getHeaderActions(): array
    // {
    //     return [
    //         Actions\CreateAction::make(),
    //     ];
    // }
}
