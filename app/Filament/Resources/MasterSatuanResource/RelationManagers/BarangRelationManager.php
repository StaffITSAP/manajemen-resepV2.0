<?php

namespace App\Filament\Resources\MasterSatuanResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BarangRelationManager extends RelationManager
{
    protected static string $relationship = 'barang';

    protected static ?string $title = 'Daftar Barang';

    protected static ?string $modelLabel = 'Barang';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('nama')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('jenis')
                    ->required()
                    ->options([
                        'bahan' => 'Bahan',
                        'setengah_jadi' => 'Barang 1/2 Jadi',
                        'jadi' => 'Barang Jadi',
                    ]),
                Forms\Components\TextInput::make('stok')
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('stok_minimal')
                    ->numeric()
                    ->default(0),
                Forms\Components\Textarea::make('deskripsi')
                    ->maxLength(65535)
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('status_aktif')
                    ->required()
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('nama')
            ->columns([
                Tables\Columns\TextColumn::make('nama')
                    ->searchable(),
                Tables\Columns\TextColumn::make('jenis')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'bahan' => 'info',
                        'setengah_jadi' => 'warning',
                        'jadi' => 'success',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'bahan' => 'Bahan',
                        'setengah_jadi' => '1/2 Jadi',
                        'jadi' => 'Jadi',
                    }),
                Tables\Columns\TextColumn::make('stok')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('stok_minimal')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('status_aktif')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('jenis')
                    ->options([
                        'bahan' => 'Bahan',
                        'setengah_jadi' => 'Barang 1/2 Jadi',
                        'jadi' => 'Barang Jadi',
                    ]),
                Tables\Filters\SelectFilter::make('status_aktif')
                    ->options([
                        '1' => 'Aktif',
                        '0' => 'Nonaktif',
                    ])
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