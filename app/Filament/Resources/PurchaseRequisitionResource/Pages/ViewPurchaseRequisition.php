<?php

namespace App\Filament\Resources\PurchaseRequisitionResource\Pages;

use App\Filament\Resources\PurchaseRequisitionResource;
use App\Models\PurchaseRequisition;
use App\Services\PurchaseRequisitions\Accurate\PurchaseRequisitionSender;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Log;
use Throwable;

class ViewPurchaseRequisition extends ViewRecord
{
    protected static string $resource = PurchaseRequisitionResource::class;

    protected static ?string $breadcrumb = 'Lihat';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn(PurchaseRequisition $record): bool => $this->canApprove($record))
                ->requiresConfirmation()
                ->modalHeading('Approve Permintaan Barang')
                ->modalDescription('Permintaan Barang akan dikirim ke Accurate sebagai DRAFT.')
                ->modalSubmitActionLabel('Approve')
                ->modalCancelActionLabel('Batal')
                ->action(function (PurchaseRequisition $record): void {
                    if (! $this->canApprove($record)) {
                        Notification::make()
                            ->danger()
                            ->title('Permintaan Barang tidak dapat di-approve.')
                            ->body('Status atau akses approval tidak memenuhi syarat.')
                            ->send();

                        return;
                    }

                    $this->sendToAccurate($record);
                }),
            Action::make('retryAccurate')
                ->label('Kirim Ulang ke Accurate')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                ->visible(fn(PurchaseRequisition $record): bool => $this->canRetry($record))
                ->requiresConfirmation()
                ->modalHeading('Konfirmasi Kirim Ulang')
                ->modalDescription("Permintaan Barang akan dikirim ulang ke Accurate sebagai DRAFT.\n\nPastikan pengiriman sebelumnya benar-benar gagal dan belum membentuk dokumen di Accurate.")
                ->modalSubmitActionLabel('Kirim Ulang')
                ->modalCancelActionLabel('Batal')
                ->action(function (PurchaseRequisition $record): void {
                    if (! $this->canRetry($record)) {
                        Notification::make()
                            ->danger()
                            ->title('Permintaan Barang tidak dapat dikirim ulang.')
                            ->body('Status pengiriman saat ini tidak memenuhi syarat kirim ulang yang aman.')
                            ->send();

                        return;
                    }
                    $this->sendToAccurate($record);
                }),
            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn(PurchaseRequisition $record): bool => $this->canReject($record))
                ->requiresConfirmation()
                ->modalHeading('Reject Permintaan Barang')
                ->modalDescription('Permintaan Barang akan dibatalkan secara lokal dan tidak dikirim ke Accurate.')
                ->modalSubmitActionLabel('Reject')
                ->modalCancelActionLabel('Batal')
                ->action(function (PurchaseRequisition $record): void {
                    if (! $this->canReject($record)) {
                        Notification::make()
                            ->danger()
                            ->title('Permintaan Barang tidak dapat di-reject.')
                            ->body('Status atau akses reject tidak memenuhi syarat.')
                            ->send();

                        return;
                    }

                    $record->update([
                        'status' => 'cancelled',
                        'error_message' => null,
                    ]);

                    $this->record = $record->fresh(['items']) ?? $record;

                    Notification::make()
                        ->success()
                        ->title('Permintaan Barang berhasil di-reject.')
                        ->body('Data lokal dibatalkan dan tidak dikirim ke Accurate.')
                        ->send();
                }),
        ];
    }

    private function canApprove(PurchaseRequisition $record): bool
    {
        return auth()->user()?->can('approve', $record) === true
            && $record->status === 'submitted'
            && $record->sync_status === 'pending'
            && blank($record->accurate_id)
            && blank($record->accurate_number);
    }

    private function canRetry(PurchaseRequisition $record): bool
    {
        return auth()->user()?->can('approve', $record) === true
            && $this->isRetrySafeFailure($record);
    }

    private function canReject(PurchaseRequisition $record): bool
    {
        return auth()->user()?->can('reject', $record) === true
            && $record->status === 'submitted'
            && $record->sync_status === 'pending'
            && blank($record->accurate_id)
            && blank($record->accurate_number);
    }

    private function sendToAccurate(PurchaseRequisition $record): void
    {
        try {
            /** @var PurchaseRequisition $updated */
            $updated = app(PurchaseRequisitionSender::class)->sendDraft($record);
        } catch (Throwable $exception) {
            Log::error('Purchase Requisition send failed unexpectedly.', [
                'purchase_requisition_id' => $record->id,
                'exception' => $exception,
            ]);

            $this->record = $record->fresh(['items']) ?? $record;

            Notification::make()
                ->danger()
                ->title('Permintaan Barang belum berhasil dikirim ke Accurate.')
                ->body('Silakan tinjau kembali status pengiriman sebelum mencoba lagi.')
                ->send();

            return;
        }

        $this->record = $updated->fresh(['items']) ?? $updated;

        if (str_contains((string) $this->record->error_message, 'AMBIGUOUS_REVIEW_REQUIRED')) {
            Notification::make()
                ->warning()
                ->title('Status pengiriman ke Accurate perlu diperiksa.')
                ->body('Jangan kirim ulang sebelum memastikan dokumen di Accurate.')
                ->send();

            return;
        }

        if ($this->record->sync_status === 'synced') {
            Notification::make()
                ->success()
                ->title('Permintaan Barang berhasil di-approve dan dikirim ke Accurate.')
                ->body("Nomor Accurate: {$this->record->accurate_number}\nStatus Accurate: {$this->record->accurate_status}")
                ->send();

            return;
        }

        Notification::make()
            ->danger()
            ->title('Permintaan Barang belum berhasil dikirim ke Accurate.')
            ->body('Data lokal tetap tersimpan. Silakan tinjau status pengiriman sebelum mencoba lagi.')
            ->send();
    }

    private function isRetrySafeFailure(PurchaseRequisition $record): bool
    {
        return $record->sync_status === 'failed'
            && blank($record->accurate_id)
            && blank($record->accurate_number)
            && ! str_contains((string) $record->error_message, 'AMBIGUOUS_REVIEW_REQUIRED')
            && filled($record->error_message);
    }
}
