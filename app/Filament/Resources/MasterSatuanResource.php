<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use App\Filament\Resources\MasterSatuanResource\RelationManagers\BarangRelationManager;
use App\Filament\Resources\MasterSatuanResource\Pages\ListMasterSatuans;
use App\Filament\Resources\MasterSatuanResource\Pages\CreateMasterSatuan;
use App\Filament\Resources\MasterSatuanResource\Pages\EditMasterSatuan;
use App\Filament\Resources\MasterSatuanResource\Pages;
use App\Filament\Resources\MasterSatuanResource\RelationManagers;
use App\Models\MasterSatuan;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MasterSatuanResource extends Resource
{
    protected static ?string $model = MasterSatuan::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Satuan';

    protected static string | \UnitEnum | null $navigationGroup = 'Master Data';

    protected static ?string $modelLabel = 'Satuan';
    protected static ?string $pluralModelLabel = 'Satuan';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nama')
                    ->required()
                    ->maxLength(255),
                TextInput::make('kode')
                    ->maxLength(50)
                    ->unique(ignoreRecord: true),
                Textarea::make('deskripsi')
                    ->maxLength(65535)
                    ->columnSpanFull(),
                Toggle::make('status_aktif')
                    ->required()
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nama')
                    ->searchable(),
                TextColumn::make('kode')
                    ->searchable(),
                IconColumn::make('status_aktif')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TrashedFilter::make(),
                SelectFilter::make('status_aktif')
                    ->options([
                        '1' => 'Aktif',
                        '0' => 'Nonaktif',
                    ])
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            BarangRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMasterSatuans::route('/'),
            'create' => CreateMasterSatuan::route('/create'),
            // 'view' => Pages\ViewMasterSatuan::route('/{record}'),
            'edit' => EditMasterSatuan::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
