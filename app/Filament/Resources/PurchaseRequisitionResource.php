<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Actions\ViewAction;
use Filament\Actions\Action;
use Filament\Schemas\Components\Grid;
use App\Filament\Resources\PurchaseRequisitionResource\Pages\ListPurchaseRequisitions;
use App\Filament\Resources\PurchaseRequisitionResource\Pages\CreatePurchaseRequisition;
use App\Filament\Resources\PurchaseRequisitionResource\Pages\EditPurchaseRequisition;
use App\Filament\Resources\PurchaseRequisitionResource\Pages\ViewPurchaseRequisition;
use App\Filament\Resources\PurchaseRequisitionResource\Pages;
use App\Models\AccurateBranch;
use App\Models\AccurateItem;
use App\Models\AccurateItemUnit;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Services\PurchaseRequisitions\Accurate\PurchaseRequisitionSender;
use App\Services\PurchaseRequisitions\PurchaseLatestPriceResolver;
use App\Services\PurchaseRequisitions\UpdatePurchaseRequisitionReceiptStatus;
use App\Support\PurchaseRequisitionFormatter;
use Filament\Forms;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use RuntimeException;
use Throwable;

class PurchaseRequisitionResource extends Resource
{
    protected static ?string $model = PurchaseRequisition::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?string $navigationLabel = 'Permintaan Barang';
    protected static string | \UnitEnum | null $navigationGroup = 'Produksi';
    protected static ?string $modelLabel = 'Permintaan Barang';
    protected static ?string $pluralModelLabel = 'Permintaan Barang';
    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Informasi Permintaan')
                ->schema([
                    DatePicker::make('trans_date')
                        ->label('Tanggal')
                        ->default(now())
                        ->required()
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->closeOnDateSelection(),

                    TextInput::make('requisition_type_display')
                        ->label('Tipe Permintaan')
                        ->default('Beli Barang')
                        ->disabled()
                        ->dehydrated(false),

                    TextInput::make('description')
                        ->label('Divisi Outlet')
                        ->maxLength(255),

                    TextInput::make('branch_display')
                        ->label('Cabang')
                        ->default(fn() => self::headOfficeBranchLabel())
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText(fn() => AccurateBranch::query()->where('name', 'Kantor Pusat')->exists()
                            ? null
                            : 'Cabang Kantor Pusat belum tersedia di cache lokal.'),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make('Detail Barang')
                ->schema([
                    Repeater::make('items')
                        ->hiddenLabel()
                        ->schema([
                            Select::make('accurate_item_id')
                                ->label('Nama Barang')
                                ->placeholder('Pilih barang')
                                ->searchable()
                                ->required()
                                ->getSearchResultsUsing(fn(string $search): array => self::activeItemSearchResults($search))
                                ->getOptionLabelUsing(fn($value, Get $get): ?string => self::itemLabelFromState(
                                    $value,
                                    $get('accurate_item_label_display'),
                                ))
                                ->live()
                                ->partiallyRenderComponentsAfterStateUpdated([
                                    'item_no_display',
                                    'item_unit_accurate_id',
                                    'latest_price_display',
                                    'total_price_display',
                                    'price_status',
                                ])
                                ->afterStateUpdated(function (Set $set, ?int $state) {
                                    $item = filled($state) ? AccurateItem::query()->find($state) : null;
                                    $set('item_no_display', $item?->no);
                                    $set('accurate_item_label_display', $item
                                        ? trim(($item->no ? "{$item->no} - " : '') . (string) $item->name)
                                        : null);
                                    $unitOptions = $item ? self::unitOptions($state) : [];
                                    $set('unit_options', $unitOptions);
                                    $set('unit_options_available', $unitOptions !== []);
                                    $set('item_unit_accurate_id', null);
                                    $set('latest_purchase_unit_price', null);
                                    $set('latest_price_display', null);
                                    $set('total_price', null);
                                    $set('total_price_display', null);
                                    $set('price_status', null);
                                }),

                            TextInput::make('item_no_display')
                                ->label('Kode Barang')
                                ->disabled()
                                ->dehydrated(false),

                            TextInput::make('quantity')
                                ->label('Kuantitas')
                                ->type('text')
                                ->inputMode('decimal')
                                ->rule('numeric')
                                ->required()
                                ->minValue(0.000001)
                                ->live(onBlur: true)
                                ->partiallyRenderComponentsAfterStateUpdated(['total_price_display'])
                                ->afterStateUpdated(fn(Set $set, Get $get) => self::refreshTotalPriceState($set, $get)),

                            Select::make('item_unit_accurate_id')
                                ->label('Satuan')
                                ->placeholder('Pilih satuan')
                                ->required()
                                ->options(fn(Get $get): array => is_array($get('unit_options'))
                                    ? $get('unit_options')
                                    : self::unitOptions($get('accurate_item_id')))
                                ->searchable()
                                ->live()
                                ->partiallyRenderComponentsAfterStateUpdated([
                                    'latest_price_display',
                                    'total_price_display',
                                    'price_status',
                                ])
                                ->helperText(fn(Get $get): ?string => self::unitHelperText(
                                    $get('accurate_item_id'),
                                    $get('unit_options_available'),
                                ))
                                ->afterStateUpdated(fn(Set $set, Get $get) => self::refreshPriceState($set, $get)),

                            DatePicker::make('required_date')
                                ->label('Tanggal Diminta')
                                ->default(fn(Get $get) => $get('../../trans_date'))
                                ->required()
                                ->native(false)
                                ->displayFormat('d/m/Y')
                                ->closeOnDateSelection(),

                            Textarea::make('note')
                                ->label('Keterangan')
                                ->maxLength(65535)
                                ->columnSpanFull(),

                            Hidden::make('latest_purchase_unit_price'),
                            Hidden::make('total_price'),
                            Hidden::make('accurate_item_label_display')->dehydrated(false),
                            Hidden::make('unit_options')->dehydrated(false),
                            Hidden::make('unit_options_available')->dehydrated(false),

                            TextInput::make('latest_price_display')
                                ->label('Harga Satuan')
                                ->disabled()
                                ->dehydrated(false)
                                ->placeholder('Harga pembelian terakhir belum tersedia.'),

                            TextInput::make('total_price_display')
                                ->label('Harga Total')
                                ->disabled()
                                ->dehydrated(false),

                            Placeholder::make('price_status')
                                ->label('')
                                ->content(fn(Get $get): ?string => self::priceHelperText($get))
                                ->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel('Tambah Barang')
                        ->reorderable(false)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('trans_date')
                    ->label('Tanggal')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Divisi Outlet')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('branch_name')
                    ->label('Cabang')
                    ->badge()
                    ->color('info'),
                TextColumn::make('request_summary')
                    ->label('Ringkasan Permintaan')
                    ->state(fn(PurchaseRequisition $record): string => self::requestSummary($record))
                    ->html()
                    ->wrap(),
                TextColumn::make('estimated_total')
                    ->label('Nilai Estimasi')
                    ->state(fn(PurchaseRequisition $record): float => (float) ($record->items_total_price ?? 0))
                    ->formatStateUsing(fn(float $state): string => PurchaseRequisitionFormatter::rupiah($state))
                    ->alignRight(),
                TextColumn::make('status_summary')
                    ->label('Status')
                    ->state(fn(PurchaseRequisition $record): string => self::statusSummary($record))
                    ->html(),
                ViewColumn::make('receipt_status')
                    ->label(new HtmlString('<span class="sr-only">Status Penerimaan</span><span aria-hidden="true" class="block leading-tight">Status</span><span aria-hidden="true" class="block leading-tight">Penerimaan</span>'))
                    ->view('filament.tables.columns.purchase-requisition-receipt-status'),
                TextColumn::make('user.name')
                    ->label('Dibuat Oleh')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Dibuat Pada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Lihat')
                    ->extraAttributes(['class' => 'w-20 justify-start text-left']),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->extraAttributes(['class' => 'w-20 justify-start text-left'])
                    ->visible(fn(PurchaseRequisition $record): bool => self::canApproveRecord($record))
                    ->requiresConfirmation()
                    ->modalHeading('Approve Permintaan Barang')
                    ->modalDescription('Permintaan Barang akan dikirim ke Accurate sebagai DRAFT.')
                    ->modalSubmitActionLabel('Approve')
                    ->modalCancelActionLabel('Batal')
                    ->action(function (PurchaseRequisition $record): void {
                        if (! self::canApproveRecord($record)) {
                            Notification::make()
                                ->danger()
                                ->title('Permintaan Barang tidak dapat di-approve.')
                                ->body('Status atau akses approval tidak memenuhi syarat.')
                                ->send();

                            return;
                        }

                        self::sendApprovedRecordToAccurate($record);
                    }),
                Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->extraAttributes(['class' => 'w-20 justify-start text-left'])
                    ->visible(fn(PurchaseRequisition $record): bool => self::canRejectRecord($record))
                    ->modalHeading('Tolak Permintaan Barang')
                    ->modalDescription('Anda akan menolak permintaan barang ini. Silakan isi alasan penolakan untuk melanjutkan.')
                    ->modalSubmitActionLabel('Tolak')
                    ->modalCancelActionLabel('Batal')
                    ->schema([
                        Textarea::make('rejection_reason')
                            ->label('Alasan Penolakan')
                            ->placeholder('Masukkan alasan penolakan...')
                            ->required()
                            ->maxLength(65535)
                            ->rule('not_regex:/^\s*$/')
                            ->validationMessages([
                                'not_regex' => 'Alasan Penolakan wajib diisi.',
                            ]),
                    ])
                    ->action(function (PurchaseRequisition $record, array $data): void {
                        try {
                            self::rejectRecord($record, (string) ($data['rejection_reason'] ?? ''));
                        } catch (RuntimeException) {
                            Notification::make()
                                ->danger()
                                ->title('Permintaan Barang tidak dapat ditolak.')
                                ->body('Status atau akses reject sudah berubah sehingga aksi tidak dapat dilanjutkan.')
                                ->send();

                            return;
                        }
                    }),
                Action::make('confirmReceipt')
                    ->label('Diterima')
                    ->extraAttributes(['class' => 'hidden'])
                    ->disabled(fn(PurchaseRequisition $record): bool => ! self::canRequesterConfirmReceipt($record))
                    ->requiresConfirmation()
                    ->modalHeading('Konfirmasi Penerimaan Barang')
                    ->modalDescription('Apakah Anda yakin barang pada permintaan ini sudah diterima?')
                    ->modalSubmitActionLabel('Ya, Sudah Diterima')
                    ->modalCancelActionLabel('Batal')
                    ->action(function (PurchaseRequisition $record): void {
                        $user = auth()->user();

                        if (! $user) {
                            Notification::make()
                                ->danger()
                                ->title('Status penerimaan tidak dapat diperbarui.')
                                ->body('Sesi pengguna tidak ditemukan.')
                                ->send();

                            return;
                        }

                        try {
                            app(UpdatePurchaseRequisitionReceiptStatus::class)->confirmReceived($record, $user);
                        } catch (RuntimeException) {
                            Notification::make()
                                ->danger()
                                ->title('Status penerimaan tidak dapat diperbarui.')
                                ->body('Status atau akses penerimaan sudah berubah.')
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Status penerimaan diperbarui.')
                            ->body('Barang ditandai sudah diterima.')
                            ->send();
                    }),
                Action::make('revertReceipt')
                    ->label('Batalkan Penerimaan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->extraAttributes(['class' => 'w-32 justify-start text-left'])
                    ->visible(fn(PurchaseRequisition $record): bool => self::canSuperAdminRevertReceipt($record))
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan Penerimaan Barang')
                    ->modalDescription('Konfirmasi penerimaan akan dibatalkan dan requester dapat mengonfirmasi ulang.')
                    ->modalSubmitActionLabel('Batalkan Penerimaan')
                    ->modalCancelActionLabel('Batal')
                    ->action(function (PurchaseRequisition $record): void {
                        $user = auth()->user();

                        if (! $user) {
                            Notification::make()
                                ->danger()
                                ->title('Penerimaan barang tidak dapat dibatalkan.')
                                ->body('Sesi pengguna tidak ditemukan.')
                                ->send();

                            return;
                        }

                        try {
                            app(UpdatePurchaseRequisitionReceiptStatus::class)->revertReceived($record, $user);
                        } catch (RuntimeException) {
                            Notification::make()
                                ->danger()
                                ->title('Penerimaan barang tidak dapat dibatalkan.')
                                ->body('Status penerimaan sudah berubah.')
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Penerimaan barang dibatalkan.')
                            ->body('Requester dapat mengonfirmasi penerimaan kembali.')
                            ->send();
                    }),
            ])
            ->recordActionsAlignment('flex-col !items-start !justify-start gap-1')
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Informasi Permintaan')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                        ->schema([
                            TextEntry::make('trans_date')->label('Tanggal')->date('d/m/Y'),
                            TextEntry::make('requisition_type')->label('Tipe Permintaan')->formatStateUsing(fn() => 'Beli Barang'),
                            TextEntry::make('description')->label('Divisi Outlet')->placeholder('-'),
                            TextEntry::make('branch_name')->label('Cabang')->badge()->color('info'),
                            TextEntry::make('approval_status')->label('Status Lokal')->state(fn(PurchaseRequisition $record): string => self::localStatusLabel($record))->badge()->color(fn(PurchaseRequisition $record): string => self::localStatusColor($record)),
                            TextEntry::make('sync_status')->label('Status Sinkronisasi')->formatStateUsing(fn(PurchaseRequisition $record): string => self::syncStatusLabel($record))->badge()->color(fn(PurchaseRequisition $record): string => self::syncStatusColor($record)),
                            TextEntry::make('receipt_status')->label('Status Penerimaan')->state(fn(PurchaseRequisition $record): string => self::receiptStatusLabel($record))->badge()->color(fn(PurchaseRequisition $record): string => self::receiptStatusColor($record))->visible(fn(PurchaseRequisition $record): bool => $record->isReceiptEligible()),
                            TextEntry::make('received_at')->label('Diterima Pada')->dateTime('d/m/Y H:i')->placeholder('-')->visible(fn(PurchaseRequisition $record): bool => $record->receiptStatusOrDefault() === PurchaseRequisition::RECEIPT_STATUS_RECEIVED),
                            TextEntry::make('accurate_status')->label('Status Accurate')->placeholder('-')->badge()->color('gray'),
                            TextEntry::make('accurate_number')->label('Nomor Accurate')->placeholder('-'),
                            TextEntry::make('user.name')->label('Dibuat Oleh')->placeholder('-'),
                        ]),
                ])
                ->columnSpanFull(),
            Section::make('Detail Barang')
                ->schema([
                    RepeatableEntry::make('items')
                        ->label('')
                        ->schema([
                            Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                                ->schema([
                                    TextEntry::make('item_name')->label('Barang'),
                                    TextEntry::make('item_no')->label('Kode'),
                                    TextEntry::make('quantity')
                                        ->label('Qty')
                                        ->formatStateUsing(fn($state): string => PurchaseRequisitionFormatter::quantity($state)),
                                    TextEntry::make('item_unit_name')->label('Satuan')->badge(),
                                    TextEntry::make('required_date')->label('Tanggal Diminta')->date('d/m/Y'),
                                    TextEntry::make('note')->label('Keterangan')->placeholder('-'),
                                    TextEntry::make('latest_purchase_unit_price')
                                        ->label('Harga Satuan')
                                        ->formatStateUsing(fn($state): string => PurchaseRequisitionFormatter::rupiah($state)),
                                    TextEntry::make('total_price')
                                        ->label('Harga Total')
                                        ->formatStateUsing(fn($state): string => PurchaseRequisitionFormatter::rupiah($state)),
                                    TextEntry::make('latest_price_source_type')->label('Sumber Harga')->placeholder('-'),
                                    TextEntry::make('source_document_number')->label('Dokumen Sumber')->placeholder('-'),
                                    TextEntry::make('source_document_date')->label('Tanggal Dokumen')->date('d/m/Y')->placeholder('-'),
                                ]),
                        ])
                        ->columnSpanFull(),
                    Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                        ->schema([
                            TextEntry::make('estimated_total')
                                ->label('')
                                ->state(fn(PurchaseRequisition $record): float => (float) $record->items->sum('total_price'))
                                ->formatStateUsing(fn(float $state): string => 'Total Nilai : ' . PurchaseRequisitionFormatter::rupiah($state))
                                ->html()
                                ->extraAttributes([
                                    'class' => 'mt-4 border-b border-gray-200 pb-2 pt-4 text-right text-base font-bold',
                                ])
                                ->columnStart(['xl' => 4]),
                        ])
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            Section::make('Penolakan')
                ->visible(fn(PurchaseRequisition $record): bool => $record->status === 'cancelled' || filled($record->rejected_at))
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                        ->schema([
                            TextEntry::make('rejection_status')->label('Status')->state(fn() => 'Ditolak')->badge()->color('danger'),
                            TextEntry::make('rejecter.name')->label('Ditolak Oleh')->placeholder('-'),
                            TextEntry::make('rejected_at')->label('Tanggal Ditolak')->dateTime('d/m/Y H:i')->placeholder('-'),
                            TextEntry::make('rejection_reason')->label('Alasan Penolakan')->placeholder('-')->columnSpanFull(),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['approver', 'branch', 'rejecter', 'user', 'summaryItems'])
            ->withCount('items')
            ->withSum('items as items_total_price', 'total_price');
        $user = auth()->user();

        return $user ? $query->visibleTo($user) : $query->whereRaw('0 = 1');
    }

    public static function resolveRecordRouteBinding(int | string $key, ?Closure $modifyQuery = null): ?Model
    {
        $query = static::baseEloquentQuery();

        if ($modifyQuery) {
            $query = $modifyQuery($query) ?? $query;
        }

        return app(static::getModel())
            ->resolveRouteBindingQuery($query, $key, static::getRecordRouteKeyName())
            ->first();
    }

    private static function baseEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['approver', 'branch', 'rejecter', 'user', 'items']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseRequisitions::route('/'),
            'create' => CreatePurchaseRequisition::route('/create'),
            'edit' => EditPurchaseRequisition::route('/{record}/edit'),
            'view' => ViewPurchaseRequisition::route('/{record}'),
        ];
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    private static function headOfficeBranchLabel(): string
    {
        $branch = AccurateBranch::query()->where('name', 'Kantor Pusat')->first();

        return $branch ? $branch->name : 'Kantor Pusat belum tersedia';
    }

    private static function activeItemSearchResults(string $search): array
    {
        return AccurateItem::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('no', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn(AccurateItem $item) => [
                $item->id => trim(($item->no ? "{$item->no} - " : '') . (string) $item->name),
            ])
            ->toArray();
    }

    private static function itemLabel(mixed $value): ?string
    {
        $item = AccurateItem::query()->find($value);

        return $item ? trim(($item->no ? "{$item->no} - " : '') . (string) $item->name) : null;
    }

    private static function itemLabelFromState(mixed $value, mixed $label): ?string
    {
        return filled($label) ? (string) $label : self::itemLabel($value);
    }

    private static function unitOptions(mixed $accurateItemId): array
    {
        if (blank($accurateItemId)) {
            return [];
        }

        return AccurateItemUnit::query()
            ->whereIn('item_accurate_id', AccurateItem::query()->select('accurate_id')->whereKey($accurateItemId))
            ->orderBy('position')
            ->pluck('item_unit_name', 'item_unit_accurate_id')
            ->toArray();
    }

    private static function unitHelperText(mixed $accurateItemId, mixed $hasUnitOptions): ?string
    {
        if (blank($accurateItemId)) {
            return null;
        }

        $hasUnits = $hasUnitOptions ?? (self::unitOptions($accurateItemId) !== []);

        return ! $hasUnits
            ? 'Satuan barang belum tersedia di cache lokal.'
            : null;
    }

    private static function refreshPriceState(Set $set, Get $get): void
    {
        $item = AccurateItem::query()->find($get('accurate_item_id'));
        $unitId = (int) ($get('item_unit_accurate_id') ?? 0);
        $quantity = (string) ($get('quantity') ?? '');

        $set('latest_purchase_unit_price', null);
        $set('latest_price_display', null);
        $set('total_price', null);
        $set('total_price_display', null);

        if (! $item || $unitId <= 0) {
            return;
        }

        $latestPrice = app(PurchaseLatestPriceResolver::class)->resolve((int) $item->accurate_id, $unitId);

        if ($latestPrice === null) {
            return;
        }

        $unitPrice = (string) $latestPrice->price;
        $totalPrice = is_numeric($quantity) && (float) $quantity > 0
            ? self::multiplyDecimal($quantity, $unitPrice)
            : null;

        $set('latest_purchase_unit_price', $unitPrice);
        $set('latest_price_display', PurchaseRequisitionFormatter::rupiah($unitPrice));
        $set('total_price', $totalPrice);
        $set('total_price_display', $totalPrice === null ? null : PurchaseRequisitionFormatter::rupiah($totalPrice));
    }

    private static function refreshTotalPriceState(Set $set, Get $get): void
    {
        $quantity = (string) ($get('quantity') ?? '');
        $unitPrice = (string) ($get('latest_purchase_unit_price') ?? '');
        $hasSelectedItemAndUnit = filled($get('accurate_item_id')) && filled($get('item_unit_accurate_id'));

        $totalPrice = $hasSelectedItemAndUnit
            && is_numeric($quantity)
            && (float) $quantity > 0
            && is_numeric($unitPrice)
            ? self::multiplyDecimal($quantity, $unitPrice)
            : null;

        $set('total_price', $totalPrice);
        $set('total_price_display', $totalPrice === null
            ? null
            : PurchaseRequisitionFormatter::rupiah($totalPrice));
    }

    private static function multiplyDecimal(string $quantity, string $unitPrice): string
    {
        if (function_exists('bcmul')) {
            return bcmul($quantity, $unitPrice, 8);
        }

        return number_format(((float) $quantity) * ((float) $unitPrice), 8, '.', '');
    }

    private static function priceHelperText(Get $get): ?string
    {
        if (blank($get('accurate_item_id')) || blank($get('item_unit_accurate_id'))) {
            return null;
        }

        return blank($get('latest_purchase_unit_price'))
            ? 'Harga pembelian terakhir belum tersedia.'
            : null;
    }

    private static function requestSummary(PurchaseRequisition $record): string
    {
        $items = $record->relationLoaded('summaryItems')
            ? $record->summaryItems
            : $record->items->take(2);

        $visibleItems = $items->map(function (PurchaseRequisitionItem $item): string {
            $quantity = PurchaseRequisitionFormatter::quantity($item->quantity);
            $requiredDate = $item->required_date?->format('d/m/Y');
            $line = e($item->item_name) . ' &mdash; ' . e(trim("{$quantity} {$item->item_unit_name}"));

            return $requiredDate
                ? "{$line}<br><span class=\"text-xs text-gray-500\">Diminta {$requiredDate}</span>"
                : $line;
        });

        if ($visibleItems->isEmpty()) {
            return '-';
        }

        $totalItems = $record->relationLoaded('summaryItems')
            ? (int) ($record->items_count ?? $record->summaryItems->count())
            : $record->items->count();
        $remaining = $totalItems - $visibleItems->count();
        if ($remaining > 0) {
            $visibleItems->push('<span class="text-xs text-gray-500">+ ' . $remaining . ' barang lainnya</span>');
        }

        return $visibleItems->implode('<br>');
    }

    public static function localStatusLabel(PurchaseRequisition $record): string
    {
        if ($record->sync_status === 'synced') {
            $actorName = self::approvalActorName($record);

            return filled($actorName) ? 'Disetujui oleh ' . $actorName : 'Disetujui';
        }

        return match ($record->status) {
            'draft' => 'Draft Lokal',
            'submitted' => 'Menunggu Approval',
            'cancelled' => filled($actorName = self::rejectionActorName($record)) ? 'Ditolak oleh ' . $actorName : 'Ditolak',
            default => filled($record->status) ? (string) $record->status : '-',
        };
    }

    public static function localStatusColor(PurchaseRequisition $record): string
    {
        if ($record->sync_status === 'synced') {
            return 'success';
        }

        return match ($record->status) {
            'draft' => 'warning',
            'submitted' => 'warning',
            'cancelled' => 'danger',
            default => 'gray',
        };
    }

    public static function syncStatusLabel(PurchaseRequisition $record): string
    {
        return match ($record->sync_status) {
            'pending' => 'Belum Dikirim ke Accurate',
            'processing' => 'Diproses',
            'synced' => 'Terkirim ke Accurate',
            'failed' => self::isAmbiguousSyncResult($record) ? 'Perlu Pemeriksaan' : 'Gagal Dikirim ke Accurate',
            default => filled($record->sync_status) ? (string) $record->sync_status : '-',
        };
    }

    public static function syncStatusColor(PurchaseRequisition $record): string
    {
        return match ($record->sync_status) {
            'pending' => 'gray',
            'processing' => 'info',
            'synced' => 'success',
            'failed' => self::isAmbiguousSyncResult($record) ? 'warning' : 'danger',
            default => 'gray',
        };
    }

    public static function receiptStatusLabel(PurchaseRequisition $record): string
    {
        return match ($record->receiptStatusOrDefault()) {
            PurchaseRequisition::RECEIPT_STATUS_RECEIVED => 'Diterima',
            default => 'Belum Diterima',
        };
    }

    public static function receiptStatusColor(PurchaseRequisition $record): string
    {
        return match ($record->receiptStatusOrDefault()) {
            PurchaseRequisition::RECEIPT_STATUS_RECEIVED => 'success',
            default => 'warning',
        };
    }

    private static function statusSummary(PurchaseRequisition $record): string
    {
        $status = self::localStatusLabel($record);
        $syncStatus = self::syncStatusLabel($record);
        $dotColor = match (true) {
            $record->sync_status === 'synced' => '#16a34a',
            $record->status === 'cancelled' => '#dc2626',
            $record->status === 'submitted' => '#f59e0b',
            default => null,
        };

        $statusLine = $dotColor === null
            ? e($status)
            : '<span style="display:inline-flex;align-items:center;gap:0.375rem;"><span aria-hidden="true" style="display:inline-block;width:0.5rem;height:0.5rem;border-radius:9999px;background-color:' . $dotColor . ';flex-shrink:0;"></span><span>' . e($status) . '</span></span>';

        return $statusLine . '<br><span class="text-xs text-gray-500">' . e($syncStatus) . '</span>';
    }

    private static function approvalActorName(PurchaseRequisition $record): string
    {
        return $record->approver?->name ?: '';
    }

    private static function rejectionActorName(PurchaseRequisition $record): string
    {
        return $record->rejecter?->name ?: '';
    }

    private static function isAmbiguousSyncResult(PurchaseRequisition $record): bool
    {
        return str_contains((string) $record->error_message, 'AMBIGUOUS_REVIEW_REQUIRED');
    }

    private static function canApproveRecord(PurchaseRequisition $record): bool
    {
        return auth()->user()?->can('approve', $record) === true
            && $record->status === 'submitted'
            && $record->sync_status === 'pending'
            && blank($record->accurate_id)
            && blank($record->accurate_number);
    }

    private static function canRejectRecord(PurchaseRequisition $record): bool
    {
        return auth()->user()?->can('reject', $record) === true
            && $record->status === 'submitted'
            && $record->sync_status === 'pending'
            && blank($record->approved_at)
            && blank($record->rejected_at)
            && blank($record->accurate_id)
            && blank($record->accurate_number);
    }

    private static function canRequesterConfirmReceipt(PurchaseRequisition $record): bool
    {
        return auth()->user()?->can('confirmReceipt', $record) === true
            && $record->isReceiptEligible()
            && $record->receiptStatusOrDefault() === PurchaseRequisition::RECEIPT_STATUS_PENDING;
    }

    private static function canSuperAdminRevertReceipt(PurchaseRequisition $record): bool
    {
        return auth()->user()?->can('revertReceipt', $record) === true
            && $record->isReceiptEligible()
            && $record->receiptStatusOrDefault() === PurchaseRequisition::RECEIPT_STATUS_RECEIVED;
    }

    public static function rejectRecord(PurchaseRequisition $record, string $reason): PurchaseRequisition
    {
        $trimmedReason = trim($reason);

        if ($trimmedReason === '') {
            throw new RuntimeException('Rejection reason is required.');
        }

        $updated = DB::transaction(function () use ($record, $trimmedReason): PurchaseRequisition {
            $locked = PurchaseRequisition::query()->lockForUpdate()->findOrFail($record->getKey());

            if (! self::canRejectRecord($locked)) {
                throw new RuntimeException('Purchase requisition cannot be rejected.');
            }

            $previousStatus = $locked->status;

            $locked->update([
                'status' => 'cancelled',
                'rejected_by' => auth()->id(),
                'rejected_at' => now(),
                'rejection_reason' => $trimmedReason,
                'error_message' => null,
            ]);

            $locked->activityLogs()->create([
                'user_id' => auth()->id(),
                'action' => 'reject',
                'summary' => 'Permintaan Barang Ditolak',
                'changes' => [
                    'previous_status' => $previousStatus,
                    'new_status' => 'cancelled',
                    'rejection_reason' => $trimmedReason,
                ],
            ]);

            return $locked->fresh(['items', 'rejecter', 'activityLogs']) ?? $locked;
        });

        Notification::make()
            ->success()
            ->title('Permintaan Barang berhasil ditolak.')
            ->body('Data lokal dibatalkan dan tidak dikirim ke Accurate.')
            ->send();

        return $updated;
    }

    private static function sendApprovedRecordToAccurate(PurchaseRequisition $record): void
    {
        try {
            /** @var PurchaseRequisition $updated */
            $updated = app(PurchaseRequisitionSender::class)->sendDraft($record);
        } catch (Throwable $exception) {
            Log::error('Purchase Requisition list approval send failed unexpectedly.', [
                'purchase_requisition_id' => $record->id,
                'exception' => $exception,
            ]);

            Notification::make()
                ->danger()
                ->title('Permintaan Barang belum berhasil dikirim ke Accurate.')
                ->body('Silakan tinjau kembali status pengiriman sebelum mencoba lagi.')
                ->send();

            return;
        }

        if (str_contains((string) $updated->error_message, 'AMBIGUOUS_REVIEW_REQUIRED')) {
            Notification::make()
                ->warning()
                ->title('Status pengiriman ke Accurate perlu diperiksa.')
                ->body('Jangan kirim ulang sebelum memastikan dokumen di Accurate.')
                ->send();

            return;
        }

        if ($updated->sync_status === 'synced') {
            $updated->update([
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            Notification::make()
                ->success()
                ->title('Permintaan Barang berhasil di-approve dan dikirim ke Accurate.')
                ->body("Nomor Accurate: {$updated->accurate_number}\nStatus Accurate: {$updated->accurate_status}")
                ->send();

            return;
        }

        Notification::make()
            ->danger()
            ->title('Permintaan Barang belum berhasil dikirim ke Accurate.')
            ->body('Data lokal tetap tersimpan. Silakan tinjau status pengiriman sebelum mencoba lagi.')
            ->send();
    }
}
