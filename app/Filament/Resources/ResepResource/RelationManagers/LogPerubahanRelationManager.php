<?php

namespace App\Filament\Resources\ResepResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
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

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                // Tidak perlu form untuk log
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('aksi')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User'),
                Tables\Columns\TextColumn::make('aksi')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'create' => 'success',
                        'update' => 'warning',
                        'delete' => 'danger',
                    }),
                Tables\Columns\TextColumn::make('keterangan')
                    ->limit(50),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // Tidak ada action untuk membuat log manual
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                // Tidak ada bulk actions untuk log
            ]);
    }
}