<?php

namespace App\Filament\Resources\ProduksiResource\Pages;

use App\Filament\Resources\ProduksiResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Filament\Notifications\Notification;

class EditProduksi extends EditRecord
{
    protected static string $resource = ProduksiResource::class;

    public function mount($record): void
    {
        parent::mount($record);

        if ($this->record->status === 'selesai' && !Auth::user()?->hasRole('superadmin')) {
            Notification::make()
                ->title('Tidak dapat diakses')
                ->body('Produksi dengan status "selesai" tidak dapat diedit oleh pengguna non-superadmin.')
                ->danger()
                ->send();

            $this->redirect(ProduksiResource::getUrl('index'));
        }
    }

    protected function canEdit($record): bool
    {
        return !($record->status === 'selesai' && !Auth::user()?->hasRole('superadmin'));
    }
    protected function getRedirectUrl(): string
    {
        // redirect ke halaman edit record yang baru dibuat
        return $this->getResource()::getUrl('edit', [
            'record' => $this->record, // atau $this->getRecord()
        ]);
    }
}
