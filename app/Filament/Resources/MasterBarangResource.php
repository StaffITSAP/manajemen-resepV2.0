<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MasterBarangResource\Pages;
use App\Filament\Resources\MasterBarangResource\RelationManagers;
use App\Models\MasterBarang;
use App\Models\MasterSatuan;
use App\Models\AccurateItem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\Rule;

class MasterBarangResource extends Resource
{
    protected static ?string $model = MasterBarang::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?string $navigationLabel = 'Barang';
    protected static ?string $navigationGroup = 'Master Data';
    protected static ?string $modelLabel = 'Barang';
    protected static ?string $pluralModelLabel = 'Barang';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // === Pilih Nama Barang dari Accurate Items ===
                Forms\Components\Select::make('nama')
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
                Forms\Components\TextInput::make('kode')
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

                Forms\Components\Select::make('satuan_id')
                    ->label('Satuan')
                    ->required()
                    ->options(MasterSatuan::where('status_aktif', true)->pluck('nama', 'id'))
                    ->searchable(),

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('kode')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('nama')
                    ->searchable(),

                Tables\Columns\TextColumn::make('satuan.nama')
                    ->sortable(),

                Tables\Columns\TextColumn::make('jenis')
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

                Tables\Columns\TextColumn::make('stok')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('stok_minimal')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\IconColumn::make('status_aktif')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TrashedFilter::make(),
                Tables\Filters\SelectFilter::make('jenis')->options([
                    'bahan' => 'Bahan',
                    'setengah_jadi' => 'Barang 1/2 Jadi',
                    'jadi' => 'Barang Jadi',
                ]),
                Tables\Filters\SelectFilter::make('status_aktif')->options([
                    '1' => 'Aktif',
                    '0' => 'Nonaktif',
                ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
                Tables\Actions\ForceDeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ResepRelationManager::class,
            RelationManagers\BahanResepRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListMasterBarangs::route('/'),
            'create' => Pages\CreateMasterBarang::route('/create'),
            'edit'   => Pages\EditMasterBarang::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
