<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
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
use App\Filament\Resources\MasterBarangResource\RelationManagers\ResepRelationManager;
use App\Filament\Resources\MasterBarangResource\RelationManagers\BahanResepRelationManager;
use App\Filament\Resources\MasterBarangResource\Pages\ListMasterBarangs;
use App\Filament\Resources\MasterBarangResource\Pages\CreateMasterBarang;
use App\Filament\Resources\MasterBarangResource\Pages\EditMasterBarang;
use App\Filament\Resources\MasterBarangResource\Pages;
use App\Filament\Resources\MasterBarangResource\RelationManagers;
use App\Models\MasterBarang;
use App\Models\MasterSatuan;
use App\Models\AccurateItem;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\Rule;

class MasterBarangResource extends Resource
{
    protected static ?string $model = MasterBarang::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cube';
    protected static ?string $navigationLabel = 'Barang';
    protected static string | \UnitEnum | null $navigationGroup = 'Master Data';
    protected static ?string $modelLabel = 'Barang';
    protected static ?string $pluralModelLabel = 'Barang';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // === Pilih Nama Barang dari Accurate Items ===
                Select::make('nama')
                    ->label('Nama Barang')
                    ->required()
                    ->options(
                        AccurateItem::query()
                            ->orderBy('name')
                            ->pluck('name', 'name') // key dan value sama
                    )
                    ->searchable()
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $set) {
                        if ($state) {
                            $item = AccurateItem::where('name', $state)->first();
                            if ($item) {
                                $set('kode', $item->no); // kode ambil dari kolom no
                            }
                        } else {
                            $set('kode', null);
                        }
                    })
                    ->rule(function (?MasterBarang $record) {
                        return Rule::unique('master_barang', 'nama')
                            ->whereNull('deleted_at')
                            ->ignore($record?->getKey());
                    })
                    ->validationMessages([
                        'unique'   => 'Barang ini sudah pernah dipilih.',
                        'required' => 'Nama Barang wajib diisi.',
                    ]),

                // === Kode Barang otomatis dari Accurate ===
                TextInput::make('kode')
                    ->label('Kode (dari Accurate)')
                    ->disabled()
                    ->dehydrated() // tetap disimpan ke database
                    ->required()
                    ->rule(function (?MasterBarang $record) {
                        return Rule::unique('master_barang', 'kode')
                            ->whereNull('deleted_at')
                            ->ignore($record?->getKey());
                    })
                    ->validationMessages([
                        'unique'   => 'Kode ini sudah digunakan.',
                        'required' => 'Kode wajib diisi.',
                    ]),

                Select::make('satuan_id')
                    ->label('Satuan')
                    ->required()
                    ->options(MasterSatuan::where('status_aktif', true)->pluck('nama', 'id'))
                    ->searchable(),

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kode')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('nama')
                    ->searchable(),

                TextColumn::make('satuan.nama')
                    ->sortable(),

                TextColumn::make('jenis')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'bahan' => 'info',
                        'setengah_jadi' => 'warning',
                        'jadi' => 'success',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
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
                SelectFilter::make('jenis')->options([
                    'bahan' => 'Bahan',
                    'setengah_jadi' => 'Barang 1/2 Jadi',
                    'jadi' => 'Barang Jadi',
                ]),
                SelectFilter::make('status_aktif')->options([
                    '1' => 'Aktif',
                    '0' => 'Nonaktif',
                ]),
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
            ResepRelationManager::class,
            BahanResepRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListMasterBarangs::route('/'),
            'create' => CreateMasterBarang::route('/create'),
            'edit'   => EditMasterBarang::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
