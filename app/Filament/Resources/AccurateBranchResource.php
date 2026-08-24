<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccurateBranchResource\Pages;
use App\Models\AccurateBranch;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AccurateBranchResource extends Resource
{
    protected static ?string $model = AccurateBranch::class;

    protected static ?string $navigationIcon  = 'heroicon-o-building-office';
    protected static ?string $navigationLabel = 'Cabang Accurate';
    protected static ?string $navigationGroup = 'Accurate Online';
    protected static ?string $modelLabel      = 'Cabang Accurate';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('accurate_id')
                    ->label('ID Accurate')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Cabang')
                    ->sortable()
                    ->searchable(),

                // Tables\Columns\TextColumn::make('location_code')
                //     ->label('Kode Lokasi')
                //     ->sortable()
                //     ->searchable(),

                // Tables\Columns\TextColumn::make('description')
                //     ->label('Deskripsi')
                //     ->limit(60)
                //     ->toggleable(), // bisa disembunyikan via kolom

                // Tables\Columns\TextColumn::make('jobOrders_count')
                //     ->label('Jumlah Job Order')
                //     ->counts('jobOrders')
                //     ->badge()
                //     ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Terakhir Update')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('name', 'asc')
            ->filters([])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccurateBranches::route('/'),
        ];
    }
}
