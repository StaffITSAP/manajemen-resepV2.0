<?php

namespace App\Filament\Resources\ProduksiResource\RelationManagers;

use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LogPerubahanRelationManager extends RelationManager
{
    protected static string $relationship = 'logPerubahan';

    protected static ?string $title = 'Log Perubahan';

    protected static ?string $modelLabel = 'Log';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Tidak perlu form untuk log
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('aksi')
            ->columns([
                TextColumn::make('user.name')
                    ->label('User'),
                TextColumn::make('aksi')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'create' => 'success',
                        'update' => 'warning',
                        'delete' => 'danger',
                    }),
                TextColumn::make('keterangan')
                    ->limit(50),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // Tidak ada action untuk membuat log manual
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                // Tidak ada bulk actions untuk log
            ]);
    }
}