<?php

namespace App\Filament\Resources\AccurateJobOrderResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';
    protected static ?string $title = 'Detail Item';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('item_no')->label('Kode #')->searchable(),
                Tables\Columns\TextColumn::make('item_name')->label('Nama'),
                Tables\Columns\TextColumn::make('unit_name')->label('Satuan')->toggleable(),
                Tables\Columns\TextColumn::make('warehouse_name')->label('Gudang')->toggleable(),
                Tables\Columns\TextColumn::make('quantity')->label('Qty')->numeric(2)->alignRight(),
                Tables\Columns\TextColumn::make('porsi')
                    ->label('Porsi')
                    ->suffix('%')
                    ->numeric(2)
                    ->alignRight()
                    ->tooltip(function ($record) {
                        $isProduced = (bool) data_get($record->raw, 'item.materialProduced', false);
                        if ($isProduced) {
                            return 'Produk hasil (100%)';
                        }
                        return 'Persentase terhadap kuantitas produk';
                    })
                    ->color(function ($record) {
                        // Hijau untuk produk hasil (100%), abu untuk lainnya
                        $isProduced = (bool) data_get($record->raw, 'item.materialProduced', false);
                        return $isProduced ? 'success' : 'gray';
                    }),
                Tables\Columns\TextColumn::make('amount')->label('Biaya')->numeric(2)->alignRight(),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Belum ada detail item')
            ->emptyStateDescription('Data akan muncul setelah disinkron.');
    }
}
