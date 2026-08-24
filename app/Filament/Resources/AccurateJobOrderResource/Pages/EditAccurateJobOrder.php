<?php

namespace App\Filament\Resources\AccurateJobOrderResource\Pages;

use App\Filament\Resources\AccurateJobOrderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAccurateJobOrder extends EditRecord
{
    protected static string $resource = AccurateJobOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
