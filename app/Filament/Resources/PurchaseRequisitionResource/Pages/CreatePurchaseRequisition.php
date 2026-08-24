<?php

namespace App\Filament\Resources\PurchaseRequisitionResource\Pages;

use App\Filament\Resources\PurchaseRequisitionResource;
use App\Models\PurchaseRequisition;
use App\Services\PurchaseRequisitions\Accurate\PurchaseRequisitionSender;
use App\Services\PurchaseRequisitions\CreateLocalPurchaseRequisition;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Filament\Support\Facades\FilamentView;
use function Filament\Support\is_app_url;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreatePurchaseRequisition extends CreateRecord
{
    protected static string $resource = PurchaseRequisitionResource::class;

    protected static ?string $title = 'Buat Permintaan Barang';

    protected static ?string $breadcrumb = 'Buat';

    protected static bool $canCreateAnother = false;

    protected ?PurchaseRequisition $sendResultRecord = null;

    protected ?string $sendOutcome = null;

    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateLocalPurchaseRequisition::class)
            ->create($data, auth()->id());
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Simpan & Kirim')
            ->requiresConfirmation()
            ->modalHeading('Konfirmasi Permintaan Barang')
            ->modalDescription('Permintaan Barang akan disimpan dan dikirim ke Accurate sebagai DRAFT.')
            ->modalSubmitActionLabel('Simpan & Kirim')
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
        $notification = match ($this->sendOutcome) {
            'synced' => Notification::make()
                ->success()
                ->title('Permintaan Barang berhasil dibuat')
                ->body("Nomor Accurate: {$this->sendResultRecord?->accurate_number}\nStatus Accurate: {$this->sendResultRecord?->accurate_status}"),
            'failed' => Notification::make()
                ->danger()
                ->title('Permintaan Barang berhasil disimpan, tetapi belum berhasil dikirim ke Accurate.')
                ->body('Silakan buka detail Permintaan Barang untuk meninjau status pengiriman.'),
            'ambiguous' => Notification::make()
                ->warning()
                ->title('Status pengiriman ke Accurate perlu diperiksa.')
                ->body('Jangan kirim ulang sebelum memastikan apakah Permintaan Barang sudah terbentuk di Accurate.'),
            'exception' => Notification::make()
                ->danger()
                ->title('Permintaan Barang berhasil disimpan, tetapi proses kirim ke Accurate mengalami kendala.')
                ->body('Silakan buka detail Permintaan Barang dan periksa status sinkronisasi.'),
            default => parent::getCreatedNotification(),
        };

        return $notification;
    }

    public function create(bool $another = false): void
    {
        $this->authorizeAccess();

        try {
            $this->beginDatabaseTransaction();

            $this->callHook('beforeValidate');

            $data = $this->form->getState();

            $this->callHook('afterValidate');

            $data = $this->mutateFormDataBeforeCreate($data);

            $this->callHook('beforeCreate');

            $this->record = $this->handleRecordCreation($data);

            $this->form->model($this->getRecord())->saveRelationships();

            $this->callHook('afterCreate');

            $this->commitDatabaseTransaction();
        } catch (Halt $exception) {
            $exception->shouldRollbackDatabaseTransaction()
                ? $this->rollBackDatabaseTransaction()
                : $this->commitDatabaseTransaction();

            return;
        } catch (Throwable $exception) {
            $this->rollBackDatabaseTransaction();

            throw $exception;
        }

        if ($this->record instanceof PurchaseRequisition) {
            $this->sendAfterLocalCommit($this->record);
        }

        $this->rememberData();

        $this->getCreatedNotification()?->send();

        if ($another) {
            $this->form->model($this->getRecord()::class);
            $this->record = null;

            $this->fillForm();

            return;
        }

        $redirectUrl = $this->getRedirectUrl();

        $this->redirect($redirectUrl, navigate: FilamentView::hasSpaMode() && is_app_url($redirectUrl));
    }

    private function sendAfterLocalCommit(PurchaseRequisition $record): void
    {
        try {
            /** @var PurchaseRequisition $updated */
            $updated = app(PurchaseRequisitionSender::class)->sendDraft($record);

            $this->record = $updated;
            $this->sendResultRecord = $updated;
            $this->sendOutcome = str_contains((string) $updated->error_message, 'AMBIGUOUS_REVIEW_REQUIRED')
                ? 'ambiguous'
                : ($updated->sync_status === 'synced' ? 'synced' : 'failed');
        } catch (Throwable $exception) {
            $this->sendOutcome = 'exception';
            $this->sendResultRecord = $record->fresh(['items']) ?? $record;
            $this->record = $this->sendResultRecord;

            Log::error('Purchase Requisition auto-send after local save failed.', [
                'purchase_requisition_id' => $record->id,
                'exception' => $exception,
            ]);
        }
    }
}
