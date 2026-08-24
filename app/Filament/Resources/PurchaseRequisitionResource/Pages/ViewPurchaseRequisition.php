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
            Action::make('retryAccurate')
                ->label('Kirim Ulang ke Accurate')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                ->visible(fn(PurchaseRequisition $record): bool => $this->isRetrySafeFailure($record))
                ->requiresConfirmation()
                ->modalHeading('Konfirmasi Kirim Ulang')
                ->modalDescription("Permintaan Barang akan dikirim ulang ke Accurate sebagai DRAFT.\n\nPastikan pengiriman sebelumnya benar-benar gagal dan belum membentuk dokumen di Accurate.")
                ->modalSubmitActionLabel('Kirim Ulang')
                ->modalCancelActionLabel('Batal')
                ->action(function (PurchaseRequisition $record): void {
                    if (! $this->isRetrySafeFailure($record)) {
                        Notification::make()
                            ->danger()
                            ->title('Permintaan Barang tidak dapat dikirim ulang.')
                            ->body('Status pengiriman saat ini tidak memenuhi syarat kirim ulang yang aman.')
                            ->send();

                        return;
                    }

                    try {
                        /** @var PurchaseRequisition $updated */
                        $updated = app(PurchaseRequisitionSender::class)->sendDraft($record);
                    } catch (Throwable $exception) {
                        Log::error('Purchase Requisition retry send failed unexpectedly.', [
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
                            ->title('Permintaan Barang berhasil dikirim ke Accurate.')
                            ->body("Nomor Accurate: {$this->record->accurate_number}\nStatus Accurate: {$this->record->accurate_status}")
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->danger()
                        ->title('Permintaan Barang belum berhasil dikirim ke Accurate.')
                        ->body('Data lokal tetap tersimpan. Silakan tinjau status pengiriman sebelum mencoba lagi.')
                        ->send();
                }),
        ];
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
