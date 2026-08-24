<?php

namespace App\Filament\Resources\LogPerubahanResource\Pages;

use App\Filament\Resources\LogPerubahanResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLogPerubahan extends EditRecord
{
    protected static string $resource = LogPerubahanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
