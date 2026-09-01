<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseRequisitionResource\Pages;
use App\Models\AccurateBranch;
use App\Models\AccurateItem;
use App\Models\AccurateItemUnit;
use App\Models\PurchaseItemLatestPrice;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Services\PurchaseRequisitions\Accurate\PurchaseRequisitionSender;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

class PurchaseRequisitionResource extends Resource
{
    protected static ?string $model = PurchaseRequisition::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?string $navigationLabel = 'Permintaan Barang';
    protected static ?string $navigationGroup = 'Produksi';
    protected static ?string $modelLabel = 'Permintaan Barang';
    protected static ?string $pluralModelLabel = 'Permintaan Barang';
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Informasi Permintaan')
                ->schema([
                    Forms\Components\DatePicker::make('trans_date')
                        ->label('Tanggal')
                        ->default(now())
                        ->required()
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->closeOnDateSelection(),

                    Forms\Components\TextInput::make('requisition_type_display')
                        ->label('Tipe Permintaan')
                        ->default('Beli Barang')
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('description')
                        ->label('Divisi Outlet')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('branch_display')
                        ->label('Cabang')
                        ->default(fn() => self::headOfficeBranchLabel())
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText(fn() => AccurateBranch::query()->where('name', 'Kantor Pusat')->exists()
                            ? null
                            : 'Cabang Kantor Pusat belum tersedia di cache lokal.'),
                ])
                ->columns(2),

            Forms\Components\Section::make('Detail Barang')
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->hiddenLabel()
                        ->schema([
                            Forms\Components\Select::make('accurate_item_id')
                                ->label('Nama Barang')
                                ->placeholder('Pilih barang')
                                ->searchable()
                                ->required()
                                ->getSearchResultsUsing(fn(string $search): array => AccurateItem::query()
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
                                    ->toArray())
                                ->getOptionLabelUsing(fn($value): ?string => self::itemLabel($value))
                                ->live()
                                ->afterStateUpdated(function (Forms\Set $set, ?int $state) {
                                    $set('item_no_display', self::itemNo($state));
                                    $set('item_unit_accurate_id', null);
                                    $set('latest_purchase_unit_price', null);
                                    $set('latest_price_display', null);
                                    $set('total_price', null);
                                    $set('total_price_display', null);
                                    $set('price_status', null);
                                }),

                            Forms\Components\TextInput::make('item_no_display')
                                ->label('Kode Barang')
                                ->disabled()
                                ->dehydrated(false)
                                ->formatStateUsing(fn($state, Forms\Get $get) => self::itemNo($get('accurate_item_id'))),

                            Forms\Components\TextInput::make('quantity')
                                ->label('Kuantitas')
                                ->numeric()
                                ->required()
                                ->minValue(0.000001)
                                ->step('0.000001')
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn(Forms\Set $set, Forms\Get $get) => self::refreshPriceState($set, $get)),

                            Forms\Components\Select::make('item_unit_accurate_id')
                                ->label('Satuan')
                                ->placeholder('Pilih satuan')
                                ->required()
                                ->options(fn(Forms\Get $get): array => self::unitOptions($get('accurate_item_id')))
                                ->searchable()
                                ->live()
                                ->helperText(fn(Forms\Get $get): ?string => self::unitHelperText($get('accurate_item_id')))
                                ->afterStateUpdated(fn(Forms\Set $set, Forms\Get $get) => self::refreshPriceState($set, $get)),

                            Forms\Components\DatePicker::make('required_date')
                                ->label('Tanggal Diminta')
                                ->required()
                                ->native(false)
                                ->displayFormat('d/m/Y')
                                ->closeOnDateSelection(),

                            Forms\Components\Textarea::make('note')
                                ->label('Keterangan')
                                ->maxLength(65535)
                                ->columnSpanFull(),

                            Forms\Components\Hidden::make('latest_purchase_unit_price'),
                            Forms\Components\Hidden::make('total_price'),

                            Forms\Components\TextInput::make('latest_price_display')
                                ->label('Harga Satuan')
                                ->disabled()
                                ->dehydrated(false)
                                ->placeholder('Harga pembelian terakhir belum tersedia.'),

                            Forms\Components\TextInput::make('total_price_display')
                                ->label('Harga Total')
                                ->disabled()
                                ->dehydrated(false),

                            Forms\Components\Placeholder::make('price_status')
                                ->label('')
                                ->content(fn(Forms\Get $get): ?string => self::priceHelperText($get))
                                ->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel('Tambah Barang')
                        ->reorderable(false)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('trans_date')
                    ->label('Tanggal')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                    ->label('Divisi Outlet')
                    ->placeholder('-')
                    ->searchable(),
                Tables\Columns\TextColumn::make('branch_name')
                    ->label('Cabang')
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('request_summary')
                    ->label('Ringkasan Permintaan')
                    ->state(fn(PurchaseRequisition $record): string => self::requestSummary($record))
                    ->html()
                    ->wrap(),
                Tables\Columns\TextColumn::make('estimated_total')
                    ->label('Nilai Estimasi')
                    ->state(fn(PurchaseRequisition $record): float => (float) $record->items->sum('total_price'))
                    ->formatStateUsing(fn(float $state): string => 'Rp. ' . number_format($state, 2, '.', ','))
                    ->alignRight(),
                Tables\Columns\TextColumn::make('status_summary')
                    ->label('Status')
                    ->state(fn(PurchaseRequisition $record): string => self::statusSummary($record))
                    ->html(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Dibuat Oleh')
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat Pada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Lihat')
                    ->extraAttributes(['class' => 'w-20 justify-start text-left']),
                Tables\Actions\Action::make('approve')
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
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->extraAttributes(['class' => 'w-20 justify-start text-left'])
                    ->visible(fn(PurchaseRequisition $record): bool => self::canRejectRecord($record))
                    ->requiresConfirmation()
                    ->modalHeading('Reject Permintaan Barang')
                    ->modalDescription('Permintaan Barang akan dibatalkan secara lokal dan tidak dikirim ke Accurate.')
                    ->modalSubmitActionLabel('Reject')
                    ->modalCancelActionLabel('Batal')
                    ->action(function (PurchaseRequisition $record): void {
                        if (! self::canRejectRecord($record)) {
                            Notification::make()
                                ->danger()
                                ->title('Permintaan Barang tidak dapat di-reject.')
                                ->body('Status atau akses reject tidak memenuhi syarat.')
                                ->send();

                            return;
                        }

                        self::rejectRecord($record);
                    }),
            ])
            ->actionsAlignment('flex-col !items-start !justify-start gap-1')
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
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
                            TextEntry::make('accurate_status')->label('Status Accurate')->placeholder('-')->badge()->color('gray'),
                            TextEntry::make('accurate_number')->label('Nomor Accurate')->placeholder('-'),
                            TextEntry::make('user.name')->label('Dibuat Oleh')->placeholder('-'),
                        ]),
                ]),
            Section::make('Detail Barang')
                ->schema([
                    RepeatableEntry::make('items')
                        ->label('')
                        ->schema([
                            Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                                ->schema([
                                    TextEntry::make('item_name')->label('Barang'),
                                    TextEntry::make('item_no')->label('Kode'),
                                    TextEntry::make('quantity')->label('Qty')->numeric(decimalPlaces: 6),
                                    TextEntry::make('item_unit_name')->label('Satuan')->badge(),
                                    TextEntry::make('required_date')->label('Tanggal Diminta')->date('d/m/Y'),
                                    TextEntry::make('note')->label('Keterangan')->placeholder('-'),
                                    TextEntry::make('latest_purchase_unit_price')->label('Harga Satuan')->money('IDR', true),
                                    TextEntry::make('total_price')->label('Harga Total')->money('IDR', true),
                                    TextEntry::make('source_purchase_order_number')->label('Source PO')->placeholder('-'),
                                    TextEntry::make('source_purchase_order_date')->label('Tanggal PO')->date('d/m/Y')->placeholder('-'),
                                ]),
                        ])
                        ->columnSpanFull(),
                    Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                        ->schema([
                            TextEntry::make('estimated_total')
                                ->label('')
                                ->state(fn(PurchaseRequisition $record): float => (float) $record->items->sum('total_price'))
                                ->formatStateUsing(fn(float $state): string => 'Total Nilai : IDR ' . number_format($state, 2, '.', ','))
                                ->html()
                                ->extraAttributes([
                                    'class' => 'mt-4 border-b border-gray-200 pb-2 pt-4 text-right text-base font-bold',
                                ])
                                ->columnStart(['xl' => 4]),
                        ])
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = static::baseEloquentQuery();
        $user = auth()->user();

        return $user ? $query->visibleTo($user) : $query->whereRaw('0 = 1');
    }

    public static function resolveRecordRouteBinding(int | string $key): ?Model
    {
        return app(static::getModel())
            ->resolveRouteBindingQuery(static::baseEloquentQuery(), $key, static::getRecordRouteKeyName())
            ->first();
    }

    private static function baseEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['approver', 'branch', 'rejecter', 'user', 'items']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseRequisitions::route('/'),
            'create' => Pages\CreatePurchaseRequisition::route('/create'),
            'edit' => Pages\EditPurchaseRequisition::route('/{record}/edit'),
            'view' => Pages\ViewPurchaseRequisition::route('/{record}'),
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

    private static function itemLabel(mixed $value): ?string
    {
        $item = AccurateItem::query()->find($value);

        return $item ? trim(($item->no ? "{$item->no} - " : '') . (string) $item->name) : null;
    }

    private static function itemNo(mixed $accurateItemId): ?string
    {
        return AccurateItem::query()->whereKey($accurateItemId)->value('no');
    }

    private static function unitOptions(mixed $accurateItemId): array
    {
        $item = AccurateItem::query()->find($accurateItemId);

        if (! $item) {
            return [];
        }

        return AccurateItemUnit::query()
            ->where('item_accurate_id', $item->accurate_id)
            ->orderBy('position')
            ->pluck('item_unit_name', 'item_unit_accurate_id')
            ->toArray();
    }

    private static function unitHelperText(mixed $accurateItemId): ?string
    {
        if (blank($accurateItemId)) {
            return null;
        }

        return self::unitOptions($accurateItemId) === []
            ? 'Satuan barang belum tersedia di cache lokal.'
            : null;
    }

    private static function refreshPriceState(Forms\Set $set, Forms\Get $get): void
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

        $latestPrice = PurchaseItemLatestPrice::query()
            ->where('item_accurate_id', $item->accurate_id)
            ->where('item_unit_accurate_id', $unitId)
            ->first();

        if (! $latestPrice) {
            return;
        }

        $unitPrice = (string) $latestPrice->unit_price;
        $totalPrice = is_numeric($quantity) && (float) $quantity > 0
            ? number_format(((float) $quantity) * ((float) $unitPrice), 8, '.', '')
            : null;

        $set('latest_purchase_unit_price', $unitPrice);
        $set('latest_price_display', 'Rp ' . number_format((float) $unitPrice, 0, ',', '.'));
        $set('total_price', $totalPrice);
        $set('total_price_display', $totalPrice === null ? null : 'Rp ' . number_format((float) $totalPrice, 0, ',', '.'));
    }

    private static function priceHelperText(Forms\Get $get): ?string
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
        $visibleItems = $record->items->take(2)->map(function (PurchaseRequisitionItem $item): string {
            $quantity = rtrim(rtrim(number_format((float) $item->quantity, 6, '.', ''), '0'), '.');
            $requiredDate = $item->required_date?->format('d/m/Y');
            $line = e($item->item_name) . ' &mdash; ' . e(trim("{$quantity} {$item->item_unit_name}"));

            return $requiredDate
                ? "{$line}<br><span class=\"text-xs text-gray-500\">Diminta {$requiredDate}</span>"
                : $line;
        });

        if ($visibleItems->isEmpty()) {
            return '-';
        }

        $remaining = $record->items->count() - $visibleItems->count();
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

    private static function statusSummary(PurchaseRequisition $record): string
    {
        $status = self::localStatusLabel($record);
        $syncStatus = self::syncStatusLabel($record);

        return e($status) . '<br><span class="text-xs text-gray-500">' . e($syncStatus) . '</span>';
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
            && blank($record->accurate_id)
            && blank($record->accurate_number);
    }

    private static function rejectRecord(PurchaseRequisition $record): void
    {
        $record->update([
            'status' => 'cancelled',
            'rejected_by' => auth()->id(),
            'rejected_at' => now(),
            'error_message' => null,
        ]);

        Notification::make()
            ->success()
            ->title('Permintaan Barang berhasil di-reject.')
            ->body('Data lokal dibatalkan dan tidak dikirim ke Accurate.')
            ->send();
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
