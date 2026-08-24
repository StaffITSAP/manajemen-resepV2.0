<?php

namespace App\Filament\Resources\ResepResource\RelationManagers;

use App\Models\MasterBarang;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BahanResepRelationManager extends RelationManager
{
    protected static string $relationship = 'bahanResep';

    protected static ?string $title = 'Bahan-bahan Resep';

    protected static ?string $modelLabel = 'Bahan';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('bahan_id')
                    ->label('Bahan')
                    ->required()
                    ->options(MasterBarang::where('jenis', 'bahan')->where('status_aktif', true)->pluck('nama', 'id'))
                    ->searchable()
                    ->reactive(),
                Forms\Components\TextInput::make('jumlah')
                    ->required()
                    ->numeric()
                    ->label('Jumlah'),
                Forms\Components\Textarea::make('catatan')
                    ->maxLength(65535)
                    ->columnSpanFull()
                    ->label('Catatan'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('bahan.nama')
            ->columns([
                Tables\Columns\TextColumn::make('bahan.nama')
                    ->searchable()
                    ->label('Nama Bahan'),
                Tables\Columns\TextColumn::make('bahan.satuan.nama')
                    ->label('Satuan'),
                Tables\Columns\TextColumn::make('jumlah')
                    ->numeric()
                    ->sortable()
                    ->label('Jumlah'),
                Tables\Columns\TextColumn::make('catatan')
                    ->limit(50)
                    ->label('Catatan'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}