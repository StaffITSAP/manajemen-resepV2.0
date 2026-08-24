<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccurateItemResource\Pages;
use App\Models\AccurateItem;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AccurateItemResource extends Resource
{
    protected static ?string $model = AccurateItem::class;

    protected static ?string $navigationIcon  = 'heroicon-o-archive-box';
    protected static ?string $navigationLabel = 'Barang Accurate';
    protected static ?string $navigationGroup = 'Accurate Online';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('accurate_id')
                    ->label('Accurate ID')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('no')
                    ->label('Kode Barang')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
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
            'index' => Pages\ListAccurateItems::route('/'),
        ];
    }
}
