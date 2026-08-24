<?php

namespace App\Filament\Resources\PurchaseRequisitionResource\Pages;

use App\Filament\Resources\PurchaseRequisitionResource;
use App\Services\PurchaseRequisitions\SmartSync\PurchaseRequisitionSmartSync;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListPurchaseRequisitions extends ListRecords
{
    protected static string $resource = PurchaseRequisitionResource::class;

    protected static ?string $breadcrumb = 'Daftar';

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('smartSync')
                ->label('Sinkron Data Permintaan Barang')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->visible(fn() => Auth::user()?->hasRole('superadmin'))
                ->requiresConfirmation()
                ->modalHeading('Sinkron Data Permintaan Barang')
                ->modalDescription('Sistem akan memperbarui satuan barang dan harga pembelian terakhir dari Accurate secara bertahap. Proses akan berjalan di background.')
                ->modalSubmitActionLabel('Mulai Sinkronisasi')
                ->modalCancelActionLabel('Batal')
                ->action(function (PurchaseRequisitionSmartSync $smartSync): void {
                    $result = $smartSync->start();

                    if (($result['status'] ?? null) === 'already_running') {
                        Notification::make()
                            ->title('Sinkronisasi sedang berjalan.')
                            ->body('Tunggu proses yang sedang berjalan selesai sebelum menjalankan sinkronisasi lagi.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Sinkronisasi dimulai.')
                        ->body('Data satuan barang dan harga pembelian akan diperbarui secara bertahap di background.')
                        ->success()
                        ->send();
                }),
            Actions\CreateAction::make()
                ->label('Buat Permintaan Barang'),
        ];
    }
}
