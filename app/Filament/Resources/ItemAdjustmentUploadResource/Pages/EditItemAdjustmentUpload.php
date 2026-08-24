<?php

namespace App\Filament\Resources\ItemAdjustmentUploadResource\Pages;

use App\Filament\Resources\ItemAdjustmentUploadResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditItemAdjustmentUpload extends EditRecord
{
    protected static string $resource = ItemAdjustmentUploadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }
}
