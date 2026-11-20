<?php

namespace App\Filament\Resources;

use Filament\Tables\Columns\TextColumn;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use App\Filament\Resources\AccurateJobOrderResource\Pages\ListAccurateJobOrders;
use App\Filament\Resources\AccurateJobOrderResource\Pages;
use App\Models\AccurateJobOrder;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AccurateJobOrderResource extends Resource
{
    protected static ?string $model = AccurateJobOrder::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationLabel = 'Job Order Accurate';
    protected static string | \UnitEnum | null $navigationGroup = 'Accurate Online';
    protected static ?string $modelLabel = 'Job Order Accurate';
    protected static ?string $pluralModelLabel = 'Job Order Accurate';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Nomor #')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('trans_date')
                    ->label('Tanggal')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('status_name')
                    ->label('Status')
                    ->badge()
                    ->color(fn($state) => match (strtoupper((string) $state)) {
                        'SELESAI', 'FINISHED' => 'success',
                        'DRAFT', 'PENDING'    => 'warning',
                        default              => 'gray',
                    }),

                TextColumn::make('branch.name')
                    ->label('Cabang')
                    ->badge()
                    ->color('info')
                    ->alignCenter()
                    ->tooltip(fn($record) => $record->branch?->description ?? 'Cabang Accurate')
                    ->sortable(),
                TextColumn::make('warehouse_name')->label('Gudang'),

                // Tables\Columns\TextColumn::make('total_item')
                //     ->label('Total Item')
                //     ->numeric(2)
                //     ->alignRight(),

                TextColumn::make('total_amount')
                    ->label('Alokasi Biaya')
                    ->money('IDR', true),

                TextColumn::make('raw.description')
                    ->label('Keterangan')
                    ->limit(50)
                    ->wrap()
                    ->tooltip(fn($record) => $record->raw['description'] ?? null)
                    ->toggleable(),
            ])
            ->filters([])
            ->defaultSort('number', 'desc')
            ->recordActions([
                Action::make('detail')
                    ->label('Detail')
                    ->icon('heroicon-o-eye')
                    ->color('primary')
                    ->modalHeading(fn($record) => "Detail Job Order: {$record->number}")
                    ->modalWidth('7xl')
                    ->slideOver()
                    ->modalWidth('screen')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->schema(function (Schema $schema) {
                        return $schema->components([
                            Section::make('Ringkasan')
                                ->schema([
                                    Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                                        ->schema([
                                            TextEntry::make('number')->label('Nomor')
                                                ->extraAttributes(['class' => 'whitespace-normal break-words']),
                                            TextEntry::make('trans_date')->label('Tanggal')->date('d/m/Y'),
                                            TextEntry::make('status_name')->label('Status')->badge()
                                                ->color(fn($state) => match (strtoupper((string) $state)) {
                                                    'SELESAI', 'FINISHED' => 'success',
                                                    'DRAFT', 'PENDING'    => 'warning',
                                                    default              => 'gray',
                                                }),
                                            TextEntry::make('branch.name')->label('Cabang')->badge()->color('info'),
                                            TextEntry::make('warehouse_name')->label('Gudang'),
                                            TextEntry::make('rollover_number')->label('Pekerjaan Pesanan'),
                                            // TextEntry::make('total_item')->label('Total Item')->numeric(2)
                                            //     ->extraAttributes(['class' => 'text-right']),
                                            TextEntry::make('total_amount')->label('Alokasi Biaya')->money('IDR', true)
                                                ->extraAttributes(['class' => 'text-right']),
                                        ]),
                                ])
                                ->collapsible(),
                            Section::make('Detail Item')
                                ->schema([
                                    RepeatableEntry::make('items')
                                        ->label('')
                                        ->state(fn(AccurateJobOrder $record) => $record->items)
                                        ->schema([
                                            Grid::make(['default' => 1, 'md' => 2, 'xl' => 6])
                                                ->schema([
                                                    TextEntry::make('item_no')->label('Kode #')->weight('medium')
                                                        ->extraAttributes(['class' => 'whitespace-normal break-words']),
                                                    TextEntry::make('item_name')->label('Nama')->columnSpan(2)
                                                        ->extraAttributes(['class' => 'whitespace-normal break-words']),
                                                    TextEntry::make('unit_name')->label('Satuan')->badge()->color('gray'),
                                                    TextEntry::make('warehouse_name')->label('Gudang')->badge()->color('info'),
                                                    TextEntry::make('quantity')->label('Qty / Produksi')->numeric(2)
                                                        ->extraAttributes(['class' => 'text-right']),
                                                    TextEntry::make('porsi')->label('Porsi')->suffix('%')->badge()
                                                        ->color(fn($record) => (bool) data_get($record->raw, 'item.materialProduced', false)
                                                            ? 'success'
                                                            : 'gray')
                                                        ->extraAttributes(['class' => 'text-right']),
                                                    TextEntry::make('amount')->label('Biaya')->money('IDR', true)
                                                        ->extraAttributes(['class' => 'text-right']),
                                                ]),
                                        ])
                                        ->columnSpanFull()
                                        ->grid(1),
                                ])
                                ->collapsible(),
                            Section::make('Keterangan')
                                ->schema([
                                    TextEntry::make('raw.description')->label('Catatan')->placeholder('-')
                                        ->extraAttributes(['class' => 'whitespace-normal break-words']),
                                ])
                                ->collapsible(),
                        ]);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccurateJobOrders::route('/'),
        ];
    }
}
