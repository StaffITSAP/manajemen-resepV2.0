<?php

namespace App\Filament\Resources\AccurateItemResource\Pages;

use App\Filament\Resources\AccurateItemResource;
use App\Jobs\SyncAccurateItemsJob;
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
                ->action(function () {
                    SyncAccurateItemsJob::dispatchSync();

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
