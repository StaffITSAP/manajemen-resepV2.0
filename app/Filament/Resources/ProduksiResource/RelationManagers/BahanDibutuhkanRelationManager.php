<?php

namespace App\Filament\Resources\ProduksiResource\RelationManagers;

use App\Models\ItemProduksi;
use App\Models\Resep;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Resources\RelationManagers\RelationManager;

class BahanDibutuhkanRelationManager extends RelationManager
{
    protected static string $relationship = 'itemProduksi';
    protected static ?string $title = 'Bahan yang Dibutuhkan';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Daftar semua bahan yang dibutuhkan untuk produksi ini')
            ->columns([
                Tables\Columns\TextColumn::make('nama_bahan')
                    ->label('Nama Bahan')
                    ->searchable(),

                Tables\Columns\TextColumn::make('jumlah')
                    ->label('Jumlah Total')
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

                Tables\Columns\TextColumn::make('satuan')
                    ->label('Satuan'),

                Tables\Columns\TextColumn::make('resep_nama')
                    ->label('Resep')
                    ->state(function ($record) {
                        // Mengambil nama resep berdasarkan barang_setengah_jadi_id
                        $resep = Resep::where('barang_setengah_jadi_id', $record->barang_setengah_jadi_id)->first();
                        return $resep?->nama ?? '-';
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([]) // read-only
            ->actions([])       // read-only
            ->bulkActions([])   // read-only
            ->modifyQueryUsing(function (Builder $query) {
                $ownerId = $this->ownerRecord->id;

                $agg = ItemProduksi::query()
                    ->from('item_produksi') // tanpa alias agar soft delete tetap aktif
                    ->selectRaw("
                        -- ID sintetis untuk Filament
                        MD5(CONCAT(mb.nama, '|', COALESCE(ms.nama, ''))) AS id,

                        item_produksi.barang_setengah_jadi_id,
                        mb.nama AS nama_bahan,
                        COALESCE(ms.nama, '-') AS satuan,
                        SUM(
                            br.jumlah * (item_produksi.jumlah / NULLIF(r.jumlah_barang_setengah_jadi, 0))
                        ) AS jumlah
                    ")
                    ->join('resep as r', 'r.barang_setengah_jadi_id', '=', 'item_produksi.barang_setengah_jadi_id')
                    ->join('bahan_resep as br', 'br.resep_id', '=', 'r.id')
                    ->join('master_barang as mb', 'mb.id', '=', 'br.bahan_id')
                    ->leftJoin('master_satuan as ms', 'ms.id', '=', 'mb.satuan_id')
                    ->where('item_produksi.produksi_id', $ownerId)
                    ->groupBy(
                        'item_produksi.barang_setengah_jadi_id',
                        'mb.nama',
                        'ms.nama'
                    )
                    ->orderBy('mb.nama');

                // Ganti query bawaan dengan query agregat
                $query->setQuery($agg->getQuery());
            });
    }
}
