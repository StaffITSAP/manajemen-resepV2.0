<?php

namespace App\Filament\Resources;

use Filament\Tables\Columns\TextColumn;
use App\Filament\Resources\AccurateItemResource\Pages\ListAccurateItems;
use App\Filament\Resources\AccurateItemResource\Pages;
use App\Models\AccurateItem;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AccurateItemResource extends Resource
{
    protected static ?string $model = AccurateItem::class;

    protected static string | \BackedEnum | null $navigationIcon  = 'heroicon-o-archive-box';
    protected static ?string $navigationLabel = 'Barang Accurate';
    protected static string | \UnitEnum | null $navigationGroup = 'Accurate Online';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('accurate_id')
                    ->label('Accurate ID')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('no')
                    ->label('Kode Barang')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('name')
                    ->label('Nama Barang')
                    ->sortable()
                    ->searchable(),

                // Tables\Columns\TextColumn::make('updated_at')
                //     ->label('Terakhir Update')
                //     ->dateTime('d M Y H:i'),
            ])
            ->defaultSort('name', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccurateItems::route('/'),
        ];
    }
}
