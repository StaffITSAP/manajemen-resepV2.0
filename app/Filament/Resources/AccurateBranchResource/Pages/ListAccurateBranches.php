<?php

namespace App\Filament\Resources\AccurateBranchResource\Pages;

use App\Filament\Resources\AccurateBranchResource;
use App\Services\Accurate\BranchSyncService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ListAccurateBranches extends ListRecords
{
    protected static string $resource = AccurateBranchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sync')
                ->label('Sinkron dari Accurate')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->visible(fn() => Auth::user()?->hasRole('superadmin'))
                ->requiresConfirmation()
                ->action(function () {
                    try {
                        /** @var BranchSyncService $svc */
                        $svc = app(BranchSyncService::class);
                        $res = $svc->sync();

                        $total = (int) ($res['total'] ?? 0);

                        Notification::make()
                            ->title('Sinkron cabang berhasil')
                            ->body("Berhasil sinkron {$total} cabang dari Accurate.")
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Sinkron cabang gagal')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
