<?php

namespace App\Filament\Resources\AccurateItemResource\Pages;

use App\Filament\Resources\AccurateItemResource;
use App\Services\Accurate\AccurateItemSyncRunner;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ListAccurateItems extends ListRecords
{
    protected static string $resource = AccurateItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sync')
                ->label('Sinkron dari Accurate')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->visible(fn() => Auth::user()?->hasRole('superadmin'))
                ->requiresConfirmation()
                ->action(function (AccurateItemSyncRunner $runner) {
                    if (! $runner->run()) {
                        Notification::make()
                            ->title('Sinkronisasi sedang berjalan')
                            ->body('Sinkronisasi barang Accurate lain masih aktif.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Sinkronisasi selesai')
                        ->body('Data Barang Accurate berhasil diperbarui.')
                        ->success()
                        ->send();

                    $this->dispatch('refresh');
                }),
        ];
    }
}
