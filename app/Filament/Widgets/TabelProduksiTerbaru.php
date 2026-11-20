<?php

namespace App\Filament\Widgets;

use Filament\Tables\Columns\TextColumn;
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
                TextColumn::make('nomor_produksi')
                    ->searchable(),
                    TextColumn::make('accurate_rollover_number')
                    ->searchable()
                    ->label('Penyelesaian Pesanan'),
                TextColumn::make('accurate_number')
                    ->searchable()
                    ->label('Pekerjaan Pesanan'),
                TextColumn::make('tanggal')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('status')
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
                TextColumn::make('user.name')
                    ->label('Dibuat Oleh'),
                TextColumn::make('created_at')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ]);
    }
}
