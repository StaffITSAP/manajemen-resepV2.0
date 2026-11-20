<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Repeater;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use App\Filament\Resources\ResepResource\RelationManagers\BahanResepRelationManager;
use App\Filament\Resources\ResepResource\Pages\ListReseps;
use App\Filament\Resources\ResepResource\Pages\CreateResep;
use App\Filament\Resources\ResepResource\Pages\EditResep;
use App\Filament\Resources\ResepResource\Pages;
use App\Filament\Resources\ResepResource\RelationManagers;
use App\Models\Resep;
use App\Models\MasterBarang;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Exports\ResepExport;
use Maatwebsite\Excel\Facades\Excel;
use Filament\Tables\Columns\Column;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Gate;



class ResepResource extends Resource
{
    protected static ?string $model = Resep::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-text';
    protected static string | \UnitEnum | null $navigationGroup = 'Produksi';
    protected static ?string $navigationLabel = 'Resep';
    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Resep')
                    ->schema([
                        TextInput::make('nama')
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

                        Select::make('barang_setengah_jadi_id')
                            ->label('Menghasilkan Barang')
                            ->required()
                            ->options(
                                MasterBarang::where('jenis', 'setengah_jadi')
                                    ->where('status_aktif', true)
                                    ->orderBy('nama')
                                    ->pluck('nama', 'id')
                            )
                            ->searchable(),

                        TextInput::make('jumlah_barang_setengah_jadi')
                            ->required()
                            ->numeric()
                            ->default(1)
                            ->minValue(1)
                            ->label('Jumlah Hasil'),

                        Textarea::make('deskripsi')
                            ->maxLength(65535)
                            ->columnSpanFull()
                            ->label('Deskripsi Resep'),

                        Textarea::make('cara_pembuatan')
                            ->maxLength(65535)
                            ->columnSpanFull()
                            ->label('Cara Pembuatan'),

                        Toggle::make('status_aktif')
                            ->required()
                            ->default(true)
                            ->label('Status Aktif'),
                    ])
                    ->columns(2),

                Section::make('Bahan-bahan yang Dibutuhkan')
                    ->description('Daftar semua bahan yang diperlukan untuk membuat resep ini')
                    ->schema([
                        Repeater::make('bahanResep')
                            ->relationship()
                            ->schema([
                                Select::make('bahan_id')
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

                                TextInput::make('jumlah')
                                    ->required()
                                    ->numeric()
                                    ->minValue(0.01)
                                    ->step(0.01)
                                    ->label('Jumlah Bahan'),

                                Textarea::make('catatan')
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
                TextColumn::make('nama')
                    ->searchable()
                    ->sortable()
                    ->label('Nama Resep')
                    ->description(fn(Resep $record) => 'Hasil: ' . ($record->barangSetengahJadi?->nama ?? '-')),

                TextColumn::make('barangSetengahJadi.nama')
                    ->sortable()
                    ->label('Menghasilkan')
                    ->toggleable(),

                TextColumn::make('jumlah_hasil_dengan_satuan')
                    ->label('Jumlah Hasil')
                    ->state(fn(Resep $r) => $r->jumlah_hasil_dengan_satuan)
                    ->sortable('jumlah_barang_setengah_jadi')
                    ->description(fn(Resep $record) => 'Deskripsi: ' . ($record->deskripsi ?? '-')),

                TextColumn::make('total_bahan')
                    ->label('Jumlah Bahan')
                    ->badge()
                    ->color(fn($state) => $state > 5 ? 'success' : 'primary')
                    ->formatStateUsing(fn($state) => $state . ' bahan'),

                // ⬇️ Ini pakai accessor dari model Resep (BUKAN Produksi)
                TextColumn::make('bahan_list')
                    ->label('Bahan yang Dibutuhkan')
                    ->state(fn(Resep $r) => $r->bahan_list)
                    ->html()
                    ->formatStateUsing(fn($state) => nl2br(e($state)))
                    ->wrap()
                    ->lineClamp(6),

                IconColumn::make('status_aktif')
                    ->boolean()
                    ->label('Status')
                    ->toggleable(),

                TextColumn::make('created_at')
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
                    ->label('Status Aktif'),

                SelectFilter::make('barang_setengah_jadi_id')
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
                    ->visible(fn() => auth()->user()?->can('export', Resep::class) ?? false)

                    // guard tambahan saat eksekusi
                    ->authorize(fn() => auth()->user()?->can('export', Resep::class) ?? false)

                    ->action(function (array $data) {
                        $user = auth()->user();
                        abort_unless($user && $user->can('export', Resep::class), 403);

                        return Excel::download(
                            new ResepExport($data['dari_tanggal'] ?? null, $data['sampai_tanggal'] ?? null),
                            'resep-export-' . date('Y-m-d') . '.xlsx'
                        );
                    })
                    ->schema([
                        DatePicker::make('dari_tanggal')->label('Dari Tanggal'),
                        DatePicker::make('sampai_tanggal')->label('Sampai Tanggal'),
                    ]),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('lihat_bahan')
                        ->label('Lihat Bahan')
                        ->icon('heroicon-o-eye')
                        ->modalHeading('Daftar Bahan Resep')
                        ->modalContent(fn(Resep $record) => view('filament.components.resep-bahan-detail', ['resep' => $record]))
                        ->modalCancelActionLabel('Tutup')
                        ->modalSubmitAction(false)
                        ->slideOver()
                        ->modalWidth('screen'),
                    Action::make('lihat_histori_log')
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
                    DeleteAction::make(),
                    RestoreAction::make(),
                    ForceDeleteAction::make(),
                ])
            ])
            ->recordActionsPosition(RecordActionsPosition::BeforeColumns)
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
            BahanResepRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListReseps::route('/'),
            'create' => CreateResep::route('/create'),
            'edit'   => EditResep::route('/{record}'),
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
