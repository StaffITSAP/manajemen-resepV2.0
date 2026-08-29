<?php

namespace App\Filament\Resources\PurchaseRequisitionResource\Pages;

use App\Filament\Resources\PurchaseRequisitionResource;
use App\Models\PurchaseRequisition;
use App\Services\PurchaseRequisitions\CreateLocalPurchaseRequisition;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePurchaseRequisition extends CreateRecord
{
    protected static string $resource = PurchaseRequisitionResource::class;

    protected static ?string $title = 'Buat Permintaan Barang';

    protected static ?string $breadcrumb = 'Buat';

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateLocalPurchaseRequisition::class)
            ->create($data, auth()->id());
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Submit')
            ->requiresConfirmation()
            ->modalHeading('Konfirmasi Permintaan Barang')
            ->modalDescription('Permintaan Barang akan disimpan dan menunggu approval SPV sebelum dikirim ke Accurate.')
            ->modalSubmitActionLabel('Submit')
            ->modalCancelActionLabel('Batal');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Batal');
    }

    protected function getRedirectUrl(): string
    {
        /** @var PurchaseRequisition $record */
        $record = $this->record;

        return static::getResource()::getUrl('view', ['record' => $record]);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Permintaan Barang berhasil disubmit')
            ->body('Permintaan Barang menunggu approval SPV sebelum dikirim ke Accurate.');
    }
}
