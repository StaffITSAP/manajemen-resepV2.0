<?php

namespace App\Filament\Resources\ItemAdjustmentUploadResource\Pages;

use App\Filament\Resources\ItemAdjustmentUploadResource;
use App\Models\ItemAdjustmentUpload;
use App\Services\Accurate\ItemAdjustmentImporter;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreateItemAdjustmentUpload extends CreateRecord
{
    protected static string $resource = ItemAdjustmentUploadResource::class;
    /** Set user_id sebelum disimpan oleh parent */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        return $data;
    }
    /** Proses sinkron TANPA queue */
    protected function handleRecordCreation(array $data): ItemAdjustmentUpload
    {
        // 1) Simpan record via parent -> ini memindahkan file dari livewire-tmp ke disk 'public'
        $record = parent::handleRecordCreation($data);

        // 2) Validasi file eksis di disk public
        $disk = 'public';
        $path = $record->path;
        $fullPath = $path ? Storage::disk($disk)->path($path) : null;

        if (! $path || ! Storage::disk($disk)->exists($path)) {
            $record->update([
                'status' => 'failed',
                'error_message' => "File not found: " . ($fullPath ?? '(empty path)'),
            ]);

            Notification::make()
                ->title('Gagal menemukan file Excel')
                ->body('Periksa apakah file berhasil diupload.')
                ->danger()
                ->send();

            return $record;
        }

        // 3) Jalankan import sinkron (service)
        /** @var ItemAdjustmentImporter $importer */
        $importer = app(ItemAdjustmentImporter::class);
        $importer->process($record); // importer akan mencari file di disk public

        // 4) Notifikasi hasil
        if ($record->fresh()->status === 'success') {
            Notification::make()
                ->title('Berhasil kirim ke Accurate')
                ->body('Nomor: ' . ($record->fresh()->accurate_number ?: '-'))
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Gagal kirim ke Accurate')
                ->body($record->fresh()->error_message ?: 'Periksa log')
                ->danger()
                ->send();
        }

        return $record->fresh();
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null; // sudah pakai notifikasi manual
    }
}
