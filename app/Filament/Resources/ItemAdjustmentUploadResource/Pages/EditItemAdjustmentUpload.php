<?php

namespace App\Filament\Resources\ItemAdjustmentUploadResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use App\Filament\Resources\ItemAdjustmentUploadResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditItemAdjustmentUpload extends EditRecord
{
    protected static string $resource = ItemAdjustmentUploadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
