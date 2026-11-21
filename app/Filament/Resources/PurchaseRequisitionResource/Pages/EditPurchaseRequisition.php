<?php

namespace App\Filament\Resources\PurchaseRequisitionResource\Pages;

use App\Filament\Resources\PurchaseRequisitionResource;
use App\Models\AccurateItem;
use App\Models\AccurateItemUnit;
use App\Models\PurchaseRequisition;
use App\Services\PurchaseRequisitions\UpdateLocalPurchaseRequisition;
use App\Support\PurchaseRequisitionFormatter;
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

        $accurateItemIds = $record->items
            ->pluck('item_accurate_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $accurateItemsByAccurateId = $accurateItemIds->isEmpty()
            ? collect()
            : AccurateItem::query()
                ->whereIn('accurate_id', $accurateItemIds)
                ->get(['id', 'accurate_id', 'no', 'name'])
                ->keyBy('accurate_id');
        $unitOptionsByAccurateItemId = $accurateItemIds->isEmpty()
            ? []
            : AccurateItemUnit::query()
                ->whereIn('item_accurate_id', $accurateItemIds)
                ->orderBy('position')
                ->get(['item_accurate_id', 'item_unit_name', 'item_unit_accurate_id'])
                ->groupBy('item_accurate_id')
                ->map(fn ($units): array => $units
                    ->mapWithKeys(fn (AccurateItemUnit $unit): array => [
                        $unit->item_unit_accurate_id => $unit->item_unit_name,
                    ])
                    ->all())
                ->all();

        $data['requisition_type_display'] = 'Beli Barang';
        $data['branch_display'] = $record->branch_name;
        $data['items'] = $record->items->map(function ($item) use ($accurateItemsByAccurateId, $unitOptionsByAccurateItemId): array {
            $accurateItem = $accurateItemsByAccurateId->get((int) $item->item_accurate_id);
            $unitOptions = $unitOptionsByAccurateItemId[(int) $item->item_accurate_id] ?? [];

            return [
                'accurate_item_id' => $item->accurate_item_id ?: $accurateItem?->id,
                'accurate_item_label_display' => $accurateItem
                    ? trim(($accurateItem->no ? "{$accurateItem->no} - " : '') . (string) $accurateItem->name)
                    : null,
                'item_no_display' => $item->item_no,
                'unit_options' => $unitOptions,
                'unit_options_available' => $unitOptions !== [],
                'quantity' => $item->quantity,
                'item_unit_accurate_id' => $item->item_unit_accurate_id,
                'required_date' => $item->required_date,
                'note' => $item->note,
                'latest_purchase_unit_price' => $item->latest_purchase_unit_price,
                'total_price' => $item->total_price,
                'latest_price_display' => PurchaseRequisitionFormatter::rupiah($item->latest_purchase_unit_price),
                'total_price_display' => PurchaseRequisitionFormatter::rupiah($item->total_price),
            ];
        })->values()->all();

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
            ->label('Simpan Perubahan')
            ->extraAttributes(['class' => 'pr-form-submit'], merge: true);
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Batal')
            ->extraAttributes(['class' => 'pr-form-cancel'], merge: true);
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
