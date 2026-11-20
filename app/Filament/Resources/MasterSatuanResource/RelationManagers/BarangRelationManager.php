<?php

namespace App\Filament\Resources\MasterSatuanResource\RelationManagers;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms;
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

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nama')
                    ->required()
                    ->maxLength(255),
                Select::make('jenis')
                    ->required()
                    ->options([
                        'bahan' => 'Bahan',
                        'setengah_jadi' => 'Barang 1/2 Jadi',
                        'jadi' => 'Barang Jadi',
                    ]),
                TextInput::make('stok')
                    ->numeric()
                    ->default(0),
                TextInput::make('stok_minimal')
                    ->numeric()
                    ->default(0),
                Textarea::make('deskripsi')
                    ->maxLength(65535)
                    ->columnSpanFull(),
                Toggle::make('status_aktif')
                    ->required()
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('nama')
            ->columns([
                TextColumn::make('nama')
                    ->searchable(),
                TextColumn::make('jenis')
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
                TextColumn::make('stok')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('stok_minimal')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('status_aktif')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('jenis')
                    ->options([
                        'bahan' => 'Bahan',
                        'setengah_jadi' => 'Barang 1/2 Jadi',
                        'jadi' => 'Barang Jadi',
                    ]),
                SelectFilter::make('status_aktif')
                    ->options([
                        '1' => 'Aktif',
                        '0' => 'Nonaktif',
                    ])
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