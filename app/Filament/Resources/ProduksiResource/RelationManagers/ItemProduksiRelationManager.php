<?php

namespace App\Filament\Resources\ProduksiResource\RelationManagers;

use App\Models\MasterBarang;
use App\Models\Resep;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Resources\RelationManagers\RelationManager;

class ItemProduksiRelationManager extends RelationManager
{
    protected static string $relationship = 'itemProduksi';
    protected static ?string $title = 'Item Produksi';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('barang_setengah_jadi_id')
                ->label('Barang 1/2 Jadi')
                ->required()
                ->options(
                    MasterBarang::where('jenis', 'setengah_jadi')
                        ->where('status_aktif', true)
                        ->orderBy('nama')
                        ->pluck('nama', 'id')
                )
                ->searchable()
                ->reactive()
                ->afterStateUpdated(function ($state, Forms\Set $set) {
                    if ($state) {
                        $resep = Resep::where('barang_setengah_jadi_id', $state)->first();
                        if ($resep) {
                            $set('jumlah', $resep->jumlah_barang_setengah_jadi);
                        }
                    }
                }),

            Forms\Components\TextInput::make('jumlah')
                ->label('Jumlah Rencana')
                ->numeric()
                ->required(),

            Forms\Components\TextInput::make('jumlah_aktual')
                ->label('Jumlah Aktual')
                ->numeric()
                ->minValue(0)
                ->step(0.01),

            Forms\Components\TextInput::make('selisih')
                ->label('Selisih')
                ->numeric()
                ->disabled()
                ->dehydrated(),

            Forms\Components\Textarea::make('keterangan_aktual')
                ->label('Keterangan')
                ->maxLength(500)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('barang.nama')
                    ->label('Barang 1/2 Jadi')
                    ->searchable(),

                Tables\Columns\TextColumn::make('barang.satuan.nama')
                    ->label('Satuan')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('jumlah')
                    ->label('Resep')
                    ->numeric(2)
                    ->sortable()
                    ->formatStateUsing(function ($state) {
                        if ($state === null) {
                            return '-';
                        }

                        $isInt = abs($state - (int) $state) < 0.0000001;

                        return $isInt
                            ? number_format((int) $state, 0, ',', '.')
                            : number_format($state, 2, ',', '.');
                    }),

                Tables\Columns\TextColumn::make('jumlah_aktual')
                    ->label('Produksi')
                    ->numeric(2)
                    ->sortable()
                    ->formatStateUsing(function ($state) {
                        if ($state === null) {
                            return '-';
                        }

                        $isInt = abs($state - (int) $state) < 0.0000001;

                        return $isInt
                            ? number_format((int) $state, 0, ',', '.')
                            : number_format($state, 2, ',', '.');
                    }),

                Tables\Columns\TextColumn::make('selisih')
                    ->label('Selisih')
                    ->numeric(2)
                    ->badge()
                    ->color(fn($state) => $state > 0 ? 'success' : ($state < 0 ? 'danger' : 'gray'))
                    ->formatStateUsing(function ($state) {
                        if ($state === null) {
                            return '-';
                        }

                        $isInt = abs($state - (int) $state) < 0.0000001;

                        return $isInt
                            ? number_format((int) $state, 0, ',', '.')
                            : number_format($state, 2, ',', '.');
                    }),

                Tables\Columns\TextColumn::make('resep_nama')
                    ->label('Resep')
                    ->state(function ($record) {
                        $resep = Resep::where('barang_setengah_jadi_id', $record->barang_setengah_jadi_id)->first();
                        return $resep?->nama ?? '-';
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                // Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('lihat_bahan')
                    ->label('Lihat Bahan')
                    ->icon('heroicon-o-eye')
                    ->modalHeading('Bahan Resep')
                    ->modalContent(function ($record) {
                        $resep = Resep::with('bahanResep.bahan.satuan')
                            ->where('barang_setengah_jadi_id', $record->barang_setengah_jadi_id)
                            ->first();

                        return view('filament.components.resep-bahan-detail', [
                            'resep' => $resep,
                        ]);
                    })
                    ->modalCancelActionLabel('Tutup'),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }
}
