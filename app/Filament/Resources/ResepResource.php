<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ResepResource\Pages;
use App\Filament\Resources\ResepResource\RelationManagers;
use App\Models\Resep;
use App\Models\MasterBarang;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Exports\ResepExport;
use Maatwebsite\Excel\Facades\Excel;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\Column;
use Filament\Tables\Enums\ActionsPosition;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Gate;



class ResepResource extends Resource
{
    protected static ?string $model = Resep::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Produksi';
    protected static ?string $navigationLabel = 'Resep';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informasi Resep')
                    ->schema([
                        Forms\Components\TextInput::make('nama')
                            ->label('Nama Resep')
                            ->required()
                            ->maxLength(255)
                            ->rule(function (?Resep $record) {            // ✅ perbaikan typehint
                                return Rule::unique('resep', 'nama')
                                    ->whereNull('deleted_at')
                                    ->ignore($record?->getKey());
                            })
                            ->validationMessages([
                                'unique'   => 'Nama Resep sudah terdaftar.',
                                'required' => 'Nama Resep wajib diisi.',
                            ])
                            ->validationAttribute('Nama Resep'),

                        Forms\Components\Select::make('barang_setengah_jadi_id')
                            ->label('Menghasilkan Barang')
                            ->required()
                            ->options(
                                MasterBarang::where('jenis', 'setengah_jadi')
                                    ->where('status_aktif', true)
                                    ->orderBy('nama')
                                    ->pluck('nama', 'id')
                            )
                            ->searchable(),

                        Forms\Components\TextInput::make('jumlah_barang_setengah_jadi')
                            ->required()
                            ->numeric()
                            ->default(1)
                            ->minValue(1)
                            ->label('Jumlah Hasil'),

                        Forms\Components\Textarea::make('deskripsi')
                            ->maxLength(65535)
                            ->columnSpanFull()
                            ->label('Deskripsi Resep'),

                        Forms\Components\Textarea::make('cara_pembuatan')
                            ->maxLength(65535)
                            ->columnSpanFull()
                            ->label('Cara Pembuatan'),

                        Forms\Components\Toggle::make('status_aktif')
                            ->required()
                            ->default(true)
                            ->label('Status Aktif'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Bahan-bahan yang Dibutuhkan')
                    ->description('Daftar semua bahan yang diperlukan untuk membuat resep ini')
                    ->schema([
                        Forms\Components\Repeater::make('bahanResep')
                            ->relationship()
                            ->schema([
                                Forms\Components\Select::make('bahan_id')
                                    ->label('Pilih Bahan')
                                    ->searchable()
                                    ->getSearchResultsUsing(
                                        fn(string $search) => MasterBarang::query()
                                            ->whereIn('jenis', ['bahan', 'setengah_jadi', 'jadi'])
                                            ->where('status_aktif', true)
                                            ->where('nama', 'like', "%{$search}%")
                                            ->orderBy('nama')
                                            ->limit(50)
                                            ->pluck('nama', 'id')
                                            ->toArray()
                                    )
                                    ->getOptionLabelUsing(fn($value) => MasterBarang::find($value)?->nama),

                                Forms\Components\TextInput::make('jumlah')
                                    ->required()
                                    ->numeric()
                                    ->minValue(0.01)
                                    ->step(0.01)
                                    ->label('Jumlah Bahan'),

                                Forms\Components\Textarea::make('catatan')
                                    ->maxLength(500)
                                    ->label('Catatan (opsional)')
                                    ->columnSpanFull(),
                            ])
                            ->defaultItems(1)
                            ->createItemButtonLabel('+ Tambah Bahan')
                            ->minItems(1)
                            ->maxItems(50)
                            ->grid(2)
                            ->label('Daftar Bahan'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nama')
                    ->searchable()
                    ->sortable()
                    ->label('Nama Resep')
                    ->description(fn(Resep $record) => 'Hasil: ' . ($record->barangSetengahJadi?->nama ?? '-')),

                Tables\Columns\TextColumn::make('barangSetengahJadi.nama')
                    ->sortable()
                    ->label('Menghasilkan')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('jumlah_hasil_dengan_satuan')
                    ->label('Jumlah Hasil')
                    ->state(fn(Resep $r) => $r->jumlah_hasil_dengan_satuan)
                    ->sortable('jumlah_barang_setengah_jadi')
                    ->description(fn(Resep $record) => 'Deskripsi: ' . ($record->deskripsi ?? '-')),

                Tables\Columns\TextColumn::make('total_bahan')
                    ->label('Jumlah Bahan')
                    ->badge()
                    ->color(fn($state) => $state > 5 ? 'success' : 'primary')
                    ->formatStateUsing(fn($state) => $state . ' bahan'),

                // ⬇️ Ini pakai accessor dari model Resep (BUKAN Produksi)
                Tables\Columns\TextColumn::make('bahan_list')
                    ->label('Bahan yang Dibutuhkan')
                    ->state(fn(Resep $r) => $r->bahan_list)
                    ->html()
                    ->formatStateUsing(fn($state) => nl2br(e($state)))
                    ->wrap()
                    ->lineClamp(6),

                Tables\Columns\IconColumn::make('status_aktif')
                    ->boolean()
                    ->label('Status')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TrashedFilter::make(),
                Tables\Filters\SelectFilter::make('status_aktif')
                    ->options([
                        '1' => 'Aktif',
                        '0' => 'Nonaktif',
                    ])
                    ->label('Status Aktif'),

                Tables\Filters\SelectFilter::make('barang_setengah_jadi_id')
                    ->label('Menghasilkan Barang')
                    ->options(
                        MasterBarang::where('jenis', 'setengah_jadi')
                            ->where('status_aktif', true)
                            ->orderBy('nama')
                            ->pluck('nama', 'id')
                    )
                    ->searchable(),
            ])
            ->headerActions([
                Action::make('exportExcel')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray')

                    // tampilkan tombol hanya untuk user yang boleh export
                    ->visible(fn() => auth()->user()?->can('export', \App\Models\Resep::class) ?? false)

                    // guard tambahan saat eksekusi
                    ->authorize(fn() => auth()->user()?->can('export', \App\Models\Resep::class) ?? false)

                    ->action(function (array $data) {
                        $user = auth()->user();
                        abort_unless($user && $user->can('export', \App\Models\Resep::class), 403);

                        return \Maatwebsite\Excel\Facades\Excel::download(
                            new \App\Exports\ResepExport($data['dari_tanggal'] ?? null, $data['sampai_tanggal'] ?? null),
                            'resep-export-' . date('Y-m-d') . '.xlsx'
                        );
                    })
                    ->form([
                        Forms\Components\DatePicker::make('dari_tanggal')->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('sampai_tanggal')->label('Sampai Tanggal'),
                    ]),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('lihat_bahan')
                        ->label('Lihat Bahan')
                        ->icon('heroicon-o-eye')
                        ->modalHeading('Daftar Bahan Resep')
                        ->modalContent(fn(Resep $record) => view('filament.components.resep-bahan-detail', ['resep' => $record]))
                        ->modalCancelActionLabel('Tutup')
                        ->modalSubmitAction(false)
                        ->slideOver()
                        ->modalWidth('screen'),
                    Tables\Actions\Action::make('lihat_histori_log')
                        ->label('Lihat Histori Log')
                        ->icon('heroicon-o-clock')
                        ->modalHeading('Histori Log Resep')
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Tutup')
                        ->slideOver()
                        ->modalWidth('7xl')
                        ->modalContent(
                            fn(Resep $record) =>
                            view('filament.components.resep-log-history', ['resep' => $record])
                        )
                        ->slideOver()
                        ->modalWidth('screen'),
                    Tables\Actions\DeleteAction::make(),
                    Tables\Actions\RestoreAction::make(),
                    Tables\Actions\ForceDeleteAction::make(),
                ])
            ])
            ->actionsPosition(ActionsPosition::BeforeColumns)
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
            RelationManagers\BahanResepRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListReseps::route('/'),
            'create' => Pages\CreateResep::route('/create'),
            'edit'   => Pages\EditResep::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Eager load agar tabel cepat & tidak N+1
        return parent::getEloquentQuery()
            ->with([
                'barangSetengahJadi.satuan',
                'bahanResep.bahan.satuan',
            ])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    // Biarkan Filament cek ke policy ->viewAny.
    // Kamu boleh hapus method ini sama sekali,
    // atau ubah seperti di bawah agar eksplisit:
    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', static::getModel());
    }

    // (opsional) Jika ingin sembunyikan menu resource sepenuhnya ketika tak boleh viewAny:
    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }
}
