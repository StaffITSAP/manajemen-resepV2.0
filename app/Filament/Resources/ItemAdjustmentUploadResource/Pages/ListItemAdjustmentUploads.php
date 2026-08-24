<?php

namespace App\Filament\Resources\ItemAdjustmentUploadResource\Pages;

use App\Filament\Resources\ItemAdjustmentUploadResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListItemAdjustmentUploads extends ListRecords
{
    protected static string $resource = ItemAdjustmentUploadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('downloadTemplate')
                ->label('Download Template')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(route('templates.item-adjustment'))
                ->openUrlInNewTab(),

            Actions\CreateAction::make()->label('Buat'),
        ];
    }

    protected function getEmptyStateIcon(): ?string
    {
        return 'heroicon-o-document-arrow-up';
    }

    protected function getEmptyStateHeading(): ?string
    {
        return 'Belum ada data';
    }

    protected function getEmptyStateDescription(): ?string
    {
        return 'Unggah file Excel untuk membuat Item Adjustment atau unduh template terlebih dahulu.';
    }

    protected function getEmptyStateActions(): array
    {
        return [
            Actions\Action::make('downloadTemplateEmpty')
                ->label('Download Template')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(route('templates.item-adjustment'))
                ->openUrlInNewTab(),

            Actions\CreateAction::make()->label('Buat'),
        ];
    }
}
