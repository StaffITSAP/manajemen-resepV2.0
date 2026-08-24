<?php

namespace App\Filament\Resources\ProduksiResource\Pages;

use App\Filament\Resources\ProduksiResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProduksi extends CreateRecord
{
    protected static string $resource = ProduksiResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id(); // ✅ isi otomatis user
        return $data;
    }
    protected function getRedirectUrl(): string
    {
        // redirect ke halaman edit record yang baru dibuat
        return $this->getResource()::getUrl('edit', [
            'record' => $this->record, // atau $this->getRecord()
        ]);
    }
}
