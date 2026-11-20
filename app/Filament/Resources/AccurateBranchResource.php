<?php

namespace App\Filament\Resources;

use Filament\Tables\Columns\TextColumn;
use App\Filament\Resources\AccurateBranchResource\Pages\ListAccurateBranches;
use App\Filament\Resources\AccurateBranchResource\Pages;
use App\Models\AccurateBranch;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AccurateBranchResource extends Resource
{
    protected static ?string $model = AccurateBranch::class;

    protected static string | \BackedEnum | null $navigationIcon  = 'heroicon-o-building-office';
    protected static ?string $navigationLabel = 'Cabang Accurate';
    protected static string | \UnitEnum | null $navigationGroup = 'Accurate Online';
    protected static ?string $modelLabel      = 'Cabang Accurate';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('accurate_id')
                    ->label('ID Accurate')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('name')
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

                TextColumn::make('updated_at')
                    ->label('Terakhir Update')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('name', 'asc')
            ->filters([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccurateBranches::route('/'),
        ];
    }
}
