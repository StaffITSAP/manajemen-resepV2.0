<?php

namespace App\Filament\Resources\ResepResource\RelationManagers;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Models\MasterBarang;
use Filament\Forms;
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

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('bahan_id')
                    ->label('Bahan')
                    ->required()
                    ->options(MasterBarang::where('jenis', 'bahan')->where('status_aktif', true)->pluck('nama', 'id'))
                    ->searchable()
                    ->reactive(),
                TextInput::make('jumlah')
                    ->required()
                    ->numeric()
                    ->label('Jumlah'),
                Textarea::make('catatan')
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
                TextColumn::make('bahan.nama')
                    ->searchable()
                    ->label('Nama Bahan'),
                TextColumn::make('bahan.satuan.nama')
                    ->label('Satuan'),
                TextColumn::make('jumlah')
                    ->numeric()
                    ->sortable()
                    ->label('Jumlah'),
                TextColumn::make('catatan')
                    ->limit(50)
                    ->label('Catatan'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}