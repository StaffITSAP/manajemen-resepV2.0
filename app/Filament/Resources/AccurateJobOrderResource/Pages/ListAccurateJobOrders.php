<?php

namespace App\Filament\Resources\AccurateJobOrderResource\Pages;

use App\Filament\Resources\AccurateJobOrderResource;
use App\Jobs\Accurate\FullSyncJobOrders;
use App\Services\Accurate\JobOrderSyncService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListAccurateJobOrders extends ListRecords
{
    protected static string $resource = AccurateJobOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // 🔄 Sinkron terbaru (langsung)
            Actions\Action::make('sync-latest')
                ->label('Sinkron Terbaru')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->visible(fn() => Auth::user()?->hasRole('superadmin'))
                ->requiresConfirmation()
                ->action(function (JobOrderSyncService $svc) {
                    $result = $svc->syncLatest();

                    Notification::make()
                        ->title('Sinkronisasi Berhasil')
                        ->body("📦 {$result['created']} baru, {$result['updated']} sudah ada, total {$result['total']}")
                        ->success()
                        ->send();
                }),

            // ☁️ Sinkron via Worker
            Actions\Action::make('sync-worker')
                ->label('Sinkron (Manual)')
                ->icon('heroicon-o-cloud-arrow-down')
                ->color('success')
                ->visible(fn() => Auth::user()?->hasRole('superadmin'))
                ->requiresConfirmation()
                ->modalDescription('Akan mengirim job full-sync ke queue. Jalankan worker di server agar proses berjalan di background.')
                ->action(function () {
                    FullSyncJobOrders::dispatch(null);

                    Notification::make()
                        ->title('Job Sinkron Dikirim')
                        ->body('Job full-sync dikirim ke queue. Jalankan: php artisan queue:work')
                        ->success()
                        ->send();
                }),
            Actions\Action::make('sync-branches')
                ->label('Sinkron Cabang')
                ->icon('heroicon-o-building-office')
                ->color('gray')
                ->visible(fn() => Auth::user()?->hasRole('superadmin'))
                ->requiresConfirmation()
                ->action(function (\App\Services\Accurate\BranchSyncService $svc) {
                    $res = $svc->sync();
                    \Filament\Notifications\Notification::make()
                        ->title('Sinkron Cabang')
                        ->body("Berhasil sinkron {$res['total']} cabang Accurate.")
                        ->success()
                        ->send();
                }),

        ];
    }
}
