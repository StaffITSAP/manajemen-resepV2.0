<?php

namespace App\Filament\Resources\PurchaseRequisitionResource\Pages;

use App\Filament\Resources\PurchaseRequisitionResource;
use App\Models\PurchaseRequisition;
use App\Services\PurchaseRequisitions\UpdateLocalPurchaseRequisition;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditPurchaseRequisition extends EditRecord
{
    protected static string $resource = PurchaseRequisitionResource::class;

    protected static ?string $title = 'Edit Permintaan Barang';

    protected static ?string $breadcrumb = 'Edit';

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        if (! $this->record instanceof PurchaseRequisition || ! $this->record->fresh()?->isPendingApprovalEditable()) {
            throw new AuthorizationException('Permintaan Barang sudah diproses oleh approver dan tidak dapat diedit.');
        }
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var PurchaseRequisition $record */
        $record = $this->record->loadMissing('items');

        $data['items'] = $record->items->map(fn($item): array => [
            'accurate_item_id' => $item->accurate_item_id,
            'item_no_display' => $item->item_no,
            'quantity' => $item->quantity,
            'item_unit_accurate_id' => $item->item_unit_accurate_id,
            'required_date' => $item->required_date,
            'note' => $item->note,
            'latest_purchase_unit_price' => $item->latest_purchase_unit_price,
            'total_price' => $item->total_price,
            'latest_price_display' => 'Rp ' . number_format((float) $item->latest_purchase_unit_price, 0, ',', '.'),
            'total_price_display' => 'Rp ' . number_format((float) $item->total_price, 0, ',', '.'),
        ])->values()->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof PurchaseRequisition) {
            return $record;
        }

        try {
            return app(UpdateLocalPurchaseRequisition::class)
                ->update($record, $data, auth()->id());
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title('Permintaan Barang tidak dapat diedit.')
                ->body($exception->validator->errors()->first() ?: 'Data edit tidak valid.')
                ->send();

            throw $exception;
        }
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label('Simpan Perubahan');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Batal');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Permintaan Barang berhasil diperbarui.')
            ->body('Perubahan disimpan secara lokal dan tetap menunggu approval.');
    }
}
