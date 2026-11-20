<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use App\Models\ItemProduksi;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Fieldset;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Actions\ForceDeleteAction;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use App\Filament\Resources\ProduksiResource\RelationManagers\LogPerubahanRelationManager;
use App\Filament\Resources\ProduksiResource\RelationManagers\ItemProduksiRelationManager;
use App\Filament\Resources\ProduksiResource\RelationManagers\BahanDibutuhkanRelationManager;
use App\Filament\Resources\ProduksiResource\Pages\ListProduksis;
use App\Filament\Resources\ProduksiResource\Pages\CreateProduksi;
use App\Filament\Resources\ProduksiResource\Pages\EditProduksi;
use App\Filament\Resources\ProduksiResource\Pages;
use App\Filament\Resources\ProduksiResource\RelationManagers;
use App\Models\Produksi;
use App\Models\MasterBarang;
use App\Models\Resep;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use App\Exports\ProduksiExport;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;

class ProduksiResource extends Resource
{
    protected static ?string $model = Produksi::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cog';
    protected static ?string $navigationLabel = 'Produksi';
    protected static string | \UnitEnum | null $navigationGroup = 'Produksi';
    protected static ?string $pluralModelLabel = 'Produksi';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Produksi')
                    ->schema([
                        TextInput::make('nomor_produksi')
                            ->default('PRD-' . date('Ymd') . '-' . Str::random(4))
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->disabled(fn($context) => $context === 'edit'),

                        DatePicker::make('tanggal')
                            ->required()
                            ->default(now()),

                        Select::make('status')
                            ->required()
                            ->options([
                                'draft'    => 'Draft',
                                'diproses' => 'Diproses',
                                'selesai'  => 'Selesai',
                                'batal'    => 'Batal',
                            ])
                            ->default('draft')
                            ->live(),

                        Textarea::make('catatan')
                            ->maxLength(65535)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Item Produksi & Input Aktual')
                    ->schema([
                        Repeater::make('itemProduksi')
                            ->relationship()
                            ->itemLabel(fn(array $state) => 'Item: ' . (optional(MasterBarang::find($state['barang_setengah_jadi_id'] ?? null))->nama ?? '-'))
                            ->schema([
                                Select::make('barang_setengah_jadi_id')
                                    ->label('Barang 1/2 Jadi')
                                    ->required()
                                    ->options(
                                        MasterBarang::where('jenis', 'setengah_jadi')
                                            ->where('status_aktif', true)
                                            ->pluck('nama', 'id')
                                    )
                                    ->searchable()
                                    ->reactive()
                                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                        if (!$state) {
                                            $set('jumlah', null);
                                            $set('bahanProduksi', []);
                                            return;
                                        }

                                        $resep = Resep::where('barang_setengah_jadi_id', $state)->first();
                                        $jumlahItem = (float) ($resep?->jumlah_barang_setengah_jadi ?? 1);
                                        $set('jumlah', $jumlahItem);

                                        $rows = ItemProduksi::buildBahanFromResep($jumlahItem, (int) $state);
                                        $set('bahanProduksi', $rows);
                                    })
                                    ->disabled(fn($context) => $context === 'edit'),

                                TextInput::make('jumlah')
                                    ->label('Resep')
                                    ->numeric()
                                    ->minValue(0.01)
                                    ->step(0.01)
                                    ->reactive()
                                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                        $barangSJ = $get('barang_setengah_jadi_id');
                                        if (!$barangSJ) return;

                                        $rows = ItemProduksi::buildBahanFromResep((float) $state, (int) $barangSJ);
                                        $set('bahanProduksi', $rows);
                                    })
                                    ->dehydrated(),

                                // TIDAK pakai live/reactive di sini — persis itemProduksi yang kamu mau
                                TextInput::make('jumlah_aktual')
                                    ->label('Produksi')
                                    ->numeric()
                                    ->minValue(0)
                                    ->step(0.01),

                                // Forms\Components\TextInput::make('selisih')
                                //     ->label('Selisih')
                                //     ->numeric()
                                //     ->disabled()
                                //     ->dehydrated(),

                                Placeholder::make('satuan_item')
                                    ->label('Satuan')
                                    ->content(function (Get $get) {
                                        $barangId = $get('barang_setengah_jadi_id');
                                        $barang   = MasterBarang::with('satuan')->find($barangId);
                                        return $barang?->satuan?->nama ?: '-';
                                    }),

                                Textarea::make('keterangan_aktual')
                                    ->label('Keterangan Aktual')
                                    ->maxLength(500)
                                    ->columnSpanFull(),

                                // =========================
                                // NESTED: Bahan Produksi
                                // =========================
                                Fieldset::make('Bahan Produksi (otomatis dari resep)')
                                    ->schema([
                                        Repeater::make('bahanProduksi')
                                            ->relationship('bahanOtomatis')
                                            // ->default([])
                                            ->minItems(0)
                                            ->deletable(true)
                                            ->deleteAction(fn($action) => $action->requiresConfirmation())
                                            ->columns(12)
                                            ->schema([
                                                Select::make('bahan_id')
                                                    ->label('Bahan')
                                                    ->options(
                                                        MasterBarang::where(function ($query) {
                                                            $query->where('jenis', 'bahan')
                                                                ->orWhere('jenis', 'setengah_jadi')
                                                                ->orWhereNull('jenis');
                                                        })->pluck('nama', 'id')
                                                    )
                                                    ->required()
                                                    ->disabled()       // dihasilkan otomatis dari resep
                                                    ->dehydrated(true)
                                                    ->columnSpan(4),

                                                TextInput::make('jumlah')
                                                    ->label('Resep')
                                                    ->numeric()
                                                    ->disabled()
                                                    ->dehydrated()
                                                    ->columnSpan(2),

                                                // ======= TIDAK LIVE / REACTIVE. DIHITUNG OTOMATIS SAAT SIMPAN =======
                                                TextInput::make('jumlah_aktual')
                                                    ->label('Takaran Produksi (otomatis)')
                                                    ->numeric()
                                                    ->disabled()          // user tidak bisa edit
                                                    ->dehydrated(false)   // JANGAN kirim dari form; biarkan model yg hitung & simpan
                                                    ->placeholder('otomatis saat simpan')
                                                    ->columnSpan(2),
                                                // ======= NEW: total_produksi (editable oleh user) =======
                                                TextInput::make('total_produksi')
                                                    ->label('Total Produksi (manual)')
                                                    ->numeric()
                                                    ->default(0)
                                                    ->minValue(0)
                                                    ->step(0.01)
                                                    ->columnSpan(2),
                                                // === KOLUM BARU: SATUAN (BAHAN) ===
                                                Placeholder::make('satuan_bahan')
                                                    ->label('Satuan')
                                                    ->content(function (Get $get) {
                                                        $bahanId = $get('bahan_id');
                                                        $bahan   = MasterBarang::with('satuan')->find($bahanId);
                                                        return $bahan?->satuan?->nama ?: '-';
                                                    })
                                                    ->columnSpan(2),

                                                // ======= NEW: selisih_produksi (editable; default auto) =======
                                                // Forms\Components\TextInput::make('selisih_produksi')
                                                //     ->label('Selisih Produksi')
                                                //     ->helperText('Default: total_produksi - takaran produksi')
                                                //     ->numeric()
                                                //     ->default(0)
                                                //     ->step(0.01)
                                                //     ->columnSpan(2),

                                                // Forms\Components\TextInput::make('selisih')
                                                //     ->label('Selisih')
                                                //     ->numeric()
                                                //     ->disabled()
                                                //     ->dehydrated(false)   // akan dihitung di model juga
                                                //     ->columnSpan(2),

                                                TextInput::make('keterangan_aktual')
                                                    ->label('Keterangan')
                                                    ->maxLength(500)
                                                    ->columnSpanFull(),
                                            ])
                                            ->addable(false)
                                            ->grid(1),
                                    ])
                                    ->columns(1)
                                    ->columnSpanFull(),
                                // === TOGGLE + FIELDSET MANUAL (BARU) — LAYOUT PERSIS SAMA ===
                                Fieldset::make('Bahan Produksi (manual)')
                                    ->schema([
                                        Toggle::make('enable_bahan_tambahan')
                                            ->label('Tambah Bahan?')
                                            ->default(false)
                                            ->reactive()
                                            ->afterStateUpdated(function ($state, Set $set) {
                                                if (! $state) {
                                                    $set('bahanTambahan', []);
                                                }
                                            }),

                                        Repeater::make('bahanTambahan')
                                            ->relationship('bahanTambahan')
                                            ->default([])
                                            ->minItems(0)
                                            ->deletable(true)
                                            ->deleteAction(fn($action) => $action->requiresConfirmation())
                                            ->columns(12)
                                            ->visible(fn(Get $get) => (bool) $get('enable_bahan_tambahan') === true)
                                            ->mutateRelationshipDataBeforeCreateUsing(fn(array $data) => $data + ['is_manual' => true])
                                            ->mutateRelationshipDataBeforeSaveUsing(fn(array $data) => $data + ['is_manual' => true])
                                            ->schema([
                                                Select::make('bahan_id')
                                                    ->label('Bahan')
                                                    ->options(
                                                        MasterBarang::whereIn('jenis', ['bahan', 'setengah_jadi', 'jadi'])
                                                            ->orWhereNull('jenis')
                                                            ->pluck('nama', 'id')
                                                            ->toArray()
                                                    )
                                                    ->required()
                                                    ->reactive()   // ✅ supaya bisa trigger perubahan jumlah
                                                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                                        // kalau jumlah masih kosong/null, isi default = 1
                                                        if (empty($get('jumlah'))) {
                                                            $set('jumlah', 1);
                                                        }
                                                    })
                                                    ->dehydrated(true)
                                                    ->columnSpan(4),

                                                TextInput::make('jumlah')
                                                    ->label('Resep')
                                                    ->numeric()
                                                    ->default(1)   // ✅ default bukan null
                                                    ->required()   // ✅ pastikan tidak null
                                                    ->dehydrated()
                                                    ->columnSpan(2),

                                                TextInput::make('jumlah_aktual')
                                                    ->label('Takaran Produksi (otomatis)')
                                                    ->numeric()
                                                    ->disabled()
                                                    ->dehydrated(false)
                                                    ->placeholder('otomatis saat simpan')
                                                    ->columnSpan(2),

                                                TextInput::make('total_produksi')
                                                    ->label('Total Produksi (manual)')
                                                    ->numeric()
                                                    ->default(0)
                                                    ->minValue(0)
                                                    ->step(0.01)
                                                    ->columnSpan(2),

                                                Placeholder::make('satuan_bahan')
                                                    ->label('Satuan')
                                                    ->content(function (Get $get) {
                                                        $bahanId = $get('bahan_id');
                                                        $bahan   = MasterBarang::with('satuan')->find($bahanId);
                                                        return $bahan?->satuan?->nama ?: '-';
                                                    })
                                                    ->columnSpan(2),

                                                TextInput::make('keterangan_aktual')
                                                    ->label('Keterangan')
                                                    ->maxLength(500)
                                                    ->columnSpanFull(),
                                            ])
                                            ->addActionLabel('Tambah Bahan')
                                            ->grid(1),
                                    ])
                                    ->columns(1)
                                    ->columnSpanFull(),

                            ])
                            ->defaultItems(1)
                            ->createItemButtonLabel('Tambah Item')
                            ->minItems(0)
                            ->deletable(true)
                            ->deleteAction(fn($action) => $action->requiresConfirmation())
                            ->grid(1)
                            ->label('Item Produksi')
                            ->visible(fn(Get $get) => in_array($get('status'), ['diproses', 'selesai'])),
                    ])
                    ->hidden(fn(Get $get) => $get('status') === 'draft' || $get('status') === 'batal'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(function ($record) {
                // jika status selesai dan bukan superadmin, nonaktifkan klik
                if ($record->status === 'selesai' && !auth()->user()?->hasRole('superadmin')) {
                    return null; // 🔒 tidak ada link
                }

                // selain itu arahkan ke halaman edit
                return ProduksiResource::getUrl('edit', ['record' => $record]);
            })
            ->columns([
                TextColumn::make('user.name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('nomor_produksi')
                    ->label('No. Produksi')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('accurate_number')
                    ->searchable()
                    ->label('No. Accurate')
                    ->description(fn(Produksi $record) => ($record->accurate_rollover_number ?? '-')),
                TextColumn::make('tanggal')
                    ->label('Tanggal')
                    ->date('d F Y')
                    ->sortable(),

                TextColumn::make('barang_setengah_jadi_list')
                    ->label('Barang 1/2 Jadi')
                    ->state(fn(Produksi $r) => $r->barang_setengah_jadi_list)
                    ->html()
                    ->formatStateUsing(fn($state) => nl2br(e($state)))
                    ->wrap()
                    ->lineClamp(4),

                TextColumn::make('bahan_list_db')   // <-- accessor baru
                    ->label('Bahan yang Dibutuhkan')
                    ->state(fn(Produksi $r) => $r->bahan_list_db)
                    ->html()
                    ->formatStateUsing(fn($state) => nl2br(e($state)))
                    ->wrap()
                    ->lineClamp(6),

                TextColumn::make('total_rencana_with_unit')
                    ->label('Resep')
                    ->state(fn(Produksi $r) => $r->total_rencana_with_unit)
                    ->html()
                    ->formatStateUsing(fn($state) => nl2br(e($state)))
                    ->wrap()
                    ->sortable(),

                TextColumn::make('total_aktual_with_unit')
                    ->label('Hasil Aktual')
                    ->state(fn(Produksi $r) => $r->total_aktual_with_unit)
                    ->html()
                    ->formatStateUsing(fn($state) => nl2br(e($state)))
                    ->wrap()
                    ->sortable(),
                // Tables\Columns\TextColumn::make('total_selisih')
                //     ->label('Selisih Hasil')
                //     ->state(fn(Produksi $r) => $r->total_selisih)
                //     ->numeric(2)
                //     ->sortable()
                //     ->badge()
                //     ->color(fn($state) => $state > 0 ? 'success' : ($state < 0 ? 'danger' : 'gray'))
                //     ->icon(fn($state) => $state > 0 ? 'heroicon-m-arrow-trending-up'
                //         : ($state < 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-check')),

                // Tables\Columns\TextColumn::make('total_selisih_bahan')
                //     ->label('Total Selisih Bahan')
                //     ->numeric(2)
                //     ->state(fn(\App\Models\Produksi $r) => $r->total_selisih_bahan)
                //     ->badge()
                //     ->color(fn($state) => $state > 0 ? 'success' : ($state < 0 ? 'danger' : 'gray')),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'draft'    => 'gray',
                        'diproses' => 'warning',
                        'selesai'  => 'success',
                        'batal'    => 'danger',
                    }),

                // Tables\Columns\TextColumn::make('user.name')
                //     ->label('Dibuat Oleh')
                //     ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TrashedFilter::make(),
                SelectFilter::make('status')
                    ->options([
                        'draft'    => 'Draft',
                        'diproses' => 'Diproses',
                        'selesai'  => 'Selesai',
                        'batal'    => 'Batal',
                    ]),
                Filter::make('tanggal')
                    ->form([
                        DatePicker::make('dari_tanggal'),
                        DatePicker::make('sampai_tanggal'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['dari_tanggal'],
                                fn(Builder $query, $date): Builder => $query->whereDate('tanggal', '>=', $date),
                            )
                            ->when(
                                $data['sampai_tanggal'],
                                fn(Builder $query, $date): Builder => $query->whereDate('tanggal', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('input_aktual')
                        ->label('Input Aktual')
                        ->icon('heroicon-o-clipboard-document-check')
                        ->url(fn(Produksi $record) => ProduksiResource::getUrl('edit', ['record' => $record]))
                        ->visible(
                            fn(Produksi $record) =>
                            $record->status !== 'draft'
                                && $record->itemProduksi->where('jumlah_aktual', null)->count() > 0
                        ),
                    Action::make('view_detail')
                        ->label('Detail')
                        ->icon('heroicon-o-eye')
                        ->modalHeading('Detail Produksi')
                        ->modalContent(fn(Produksi $record) => view('filament.components.produksi-detail', ['record' => $record]))
                        ->modalCancelActionLabel('Tutup')
                        ->modalSubmitAction(false),
                    Action::make('lihat_histori_log')
                        ->label('Lihat Histori Log')
                        ->icon('heroicon-o-clock')
                        ->modalHeading('Histori Log Produksi')
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Tutup')
                        ->slideOver()
                        ->modalWidth('7xl')
                        ->modalContent(
                            fn(Produksi $record) =>
                            view('filament.components.produksi-log-history', ['produksi' => $record])
                        )
                        ->slideOver()
                        ->modalWidth('screen'),
                    DeleteAction::make()
                        ->requiresConfirmation()
                        ->using(function (Produksi $record) {
                            return DB::transaction(function () use ($record) {
                                // soft-delete anak dulu
                                $record->itemProduksi()->withTrashed()->get()->each(function ($item) {
                                    $item->bahanProduksi()->withTrashed()->delete(); // soft
                                    $item->delete(); // soft
                                });

                                // terakhir: soft-delete parent (Filament panggil ->delete(),
                                // tapi karena kita override via using() kita panggil sendiri)
                                $record->delete();

                                return $record;
                            });
                        }),

                    // === RESTORE dengan cascade manual ===
                    RestoreAction::make()
                        ->using(function (Produksi $record) {
                            return DB::transaction(function () use ($record) {
                                $record->restore(); // parent dulu
                                // lalu anak-anak
                                $record->itemProduksi()->onlyTrashed()->get()->each(function ($item) {
                                    $item->restore();
                                    $item->bahanProduksi()->onlyTrashed()->restore();
                                });
                                return $record;
                            });
                        }),

                    // === FORCE DELETE penuh (hard delete) + DB CASCADE safety ===
                    ForceDeleteAction::make()
                        ->requiresConfirmation()
                        ->using(function (Produksi $record) {
                            return DB::transaction(function () use ($record) {
                                // hapus anak manual (biar aman kalau DB FK belum nempel)
                                $record->itemProduksi()->withTrashed()->get()->each(function ($item) {
                                    $item->bahanProduksi()->withTrashed()->forceDelete();
                                    $item->forceDelete();
                                });

                                // terakhir hapus parent
                                $record->forceDelete();
                                return $record;
                            });
                        }),
                ])
                    ->label('Aksi')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray'),
            ])
            ->recordActionsPosition(RecordActionsPosition::BeforeColumns)
            ->headerActions([
                Action::make('exportExcel')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray')

                    // Cegah tombol tampil untuk yang tak punya izin
                    ->visible(fn() => auth()->user()?->can('export', Produksi::class) ?? false)

                    // Tambahan hard-guard saat dieksekusi (kalau ada yg akses pakai URL)
                    ->authorize(fn() => auth()->user()?->can('export', Produksi::class) ?? false)

                    ->action(function (array $data) {
                        $user = auth()->user();
                        abort_unless($user && $user->can('export', Produksi::class), 403);

                        return Excel::download(
                            new ProduksiExport($data['dari_tanggal'] ?? null, $data['sampai_tanggal'] ?? null),
                            'produksi-export-' . date('Y-m-d') . '.xlsx'
                        );
                    })
                    ->schema([
                        DatePicker::make('dari_tanggal')->label('Dari Tanggal'),
                        DatePicker::make('sampai_tanggal')->label('Sampai Tanggal'),
                    ]),
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
            LogPerubahanRelationManager::class,
            ItemProduksiRelationManager::class,
            BahanDibutuhkanRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListProduksis::route('/'),
            'create' => CreateProduksi::route('/create'),
            'edit'   => EditProduksi::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'user',
                'itemProduksi.barang.satuan',
                'itemProduksi.barang',
                'itemProduksi.bahanProduksi.bahan.satuan',
            ])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
