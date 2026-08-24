<?php

namespace App\Filament\Resources\AccurateItemResource\Pages;

use App\Filament\Resources\AccurateItemResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAccurateItem extends EditRecord
{
    protected static string $resource = AccurateItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
