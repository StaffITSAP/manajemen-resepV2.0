<?php

namespace App\Filament\Resources\LogPerubahanResource\Pages;

use App\Filament\Resources\LogPerubahanResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateLogPerubahan extends CreateRecord
{
    protected static string $resource = LogPerubahanResource::class;
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
