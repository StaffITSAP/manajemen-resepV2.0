<?php

namespace App\Filament\Resources\MasterSatuanResource\Pages;

use App\Filament\Resources\MasterSatuanResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMasterSatuan extends EditRecord
{
    protected static string $resource = MasterSatuanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
