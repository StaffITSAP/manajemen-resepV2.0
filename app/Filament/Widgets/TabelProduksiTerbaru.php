<?php

namespace App\Filament\Widgets;

use App\Models\Produksi;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class TabelProduksiTerbaru extends BaseWidget
{
    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Produksi::query()->latest()->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('nomor_produksi')
                    ->searchable(),
                    Tables\Columns\TextColumn::make('accurate_rollover_number')
                    ->searchable()
                    ->label('Penyelesaian Pesanan'),
                Tables\Columns\TextColumn::make('accurate_number')
                    ->searchable()
                    ->label('Pekerjaan Pesanan'),
                Tables\Columns\TextColumn::make('tanggal')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'draft' => 'gray',
                        'diproses' => 'warning',
                        'selesai' => 'success',
                        'batal' => 'danger',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'draft' => 'Draft',
                        'diproses' => 'Diproses',
                        'selesai' => 'Selesai',
                        'batal' => 'Batal',
                    }),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Dibuat Oleh'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ]);
    }
}
