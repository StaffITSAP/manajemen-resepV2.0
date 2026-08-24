<?php

namespace App\Filament\Resources\ItemAdjustmentUploadResource\Pages;

use App\Filament\Resources\ItemAdjustmentUploadResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Actions;
use Illuminate\Support\Facades\Storage;
use App\Models\ItemAdjustmentUpload;

class ViewItemAdjustmentUpload extends ViewRecord
{
    protected static string $resource = ItemAdjustmentUploadResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Ringkasan')->schema([
                TextEntry::make('user.name')->label('Diunggah oleh'),
                TextEntry::make('status')->badge()
                    ->color(fn($state) => match ($state) {
                        'pending'    => 'warning',
                        'processing' => 'info',
                        'success'    => 'success',
                        'failed'     => 'danger',
                        default      => 'gray',
                    }),
                TextEntry::make('trans_date')->date('d/m/Y')->label('Trans Date'),
                TextEntry::make('description'),
                TextEntry::make('accurate_number')->copyable(),
                TextEntry::make('accurate_id')->copyable(),
            ])->columns(2),

            Section::make('Payload (JSON)')->schema([
                TextEntry::make('payload')
                    // set state jadi string lebih awal (hindari array to string conversion)
                    ->state(fn($record) => json_encode($record->payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                    ->extraAttributes(['class' => 'whitespace-pre-wrap font-mono text-xs']),
            ]),

            Section::make('Response Accurate (JSON)')->schema([
                TextEntry::make('response')
                    ->state(fn($record) => json_encode($record->response ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                    ->extraAttributes(['class' => 'whitespace-pre-wrap font-mono text-xs']),
            ]),
        ]);
    }
    protected function getHeaderActions(): array
    {
        return [
            // ✅ tombol download di halaman view
            Actions\Action::make('download')
                ->label('Download File Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn(ItemAdjustmentUpload $record) => filled($record->path))
                ->url(fn(ItemAdjustmentUpload $record) => Storage::disk('public')->url($record->path))
                ->openUrlInNewTab(),

            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }
}
