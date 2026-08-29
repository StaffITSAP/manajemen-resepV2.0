<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseRequisitionLogResource\Pages;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class PurchaseRequisitionLogResource extends Resource
{
    protected static ?string $model = PurchaseRequisition::class;

    protected static ?string $slug = 'purchase-requisition-logs';
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Log Permintaan Barang';
    protected static ?string $navigationGroup = 'Laporan';
    protected static ?string $modelLabel = 'Log Permintaan Barang';
    protected static ?string $pluralModelLabel = 'Log Permintaan Barang';
    protected static ?int $navigationSort = 4;

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user?->hasRole('superadmin') === true
            || $user?->hasPermission('view_purchase_requisition_log') === true;
    }

    public static function canViewAny(): bool
    {
        return self::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat Pada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('creator_display')
                    ->label('Dibuat Oleh')
                    ->state(fn(PurchaseRequisition $record): string => self::creatorName($record))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $query) use ($search): void {
                            $query->where('creator_name', 'like', "%{$search}%")
                                ->orWhereHas('user', fn(Builder $userQuery) => $userQuery->where('name', 'like', "%{$search}%"));
                        });
                    }),
                Tables\Columns\TextColumn::make('accurate_number')
                    ->label('Nomor Draft Accurate')
                    ->placeholder('-')
                    ->searchable(),
                Tables\Columns\TextColumn::make('trans_date')
                    ->label('Tanggal Permintaan')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('branch_name')
                    ->label('Cabang')
                    ->placeholder('-')
                    ->badge()
                    ->color('info')
                    ->searchable(),
                Tables\Columns\TextColumn::make('description')
                    ->label('Divisi Outlet')
                    ->placeholder('-')
                    ->searchable(),
                Tables\Columns\TextColumn::make('item_summary')
                    ->label('Ringkasan Barang')
                    ->state(fn(PurchaseRequisition $record): string => self::itemSummary($record))
                    ->html()
                    ->wrap(),
                Tables\Columns\TextColumn::make('items_count')
                    ->label('Jumlah Item')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('items_total_price')
                    ->label('Total Referensi')
                    ->formatStateUsing(fn($state): string => self::rupiah($state))
                    ->alignRight()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn(?string $state): string => self::localStatusLabel($state))
                    ->badge()
                    ->color(fn(?string $state): string => self::localStatusColor($state)),
                Tables\Columns\TextColumn::make('accurate_status')
                    ->label('Status Accurate')
                    ->placeholder('-')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('send_result')
                    ->label('Hasil Kirim')
                    ->state(fn(PurchaseRequisition $record): string => self::sendResultLabel($record))
                    ->badge()
                    ->color(fn(PurchaseRequisition $record): string => self::sendResultColor($record)),
                Tables\Columns\TextColumn::make('last_edit_log')
                    ->label('Aktivitas Edit')
                    ->state(fn(PurchaseRequisition $record): string => self::lastEditSummary($record))
                    ->html()
                    ->wrap(),
            ])
            ->actions([
                Tables\Actions\Action::make('detail')
                    ->label('Detail')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn(PurchaseRequisition $record): string => self::detailModalHeading($record))
                    ->modalContent(fn(PurchaseRequisition $record) => view('filament.components.purchase-requisition-log-detail', [
                        'record' => $record->loadMissing(['user', 'items']),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalWidth('7xl'),
                Tables\Actions\Action::make('editHistory')
                    ->label('Riwayat Edit')
                    ->icon('heroicon-o-clock')
                    ->visible(fn(PurchaseRequisition $record): bool => $record->activityLogs->isNotEmpty())
                    ->modalHeading(fn(PurchaseRequisition $record): string => 'Riwayat Edit PR #' . $record->id)
                    ->modalContent(fn(PurchaseRequisition $record) => view('filament.components.purchase-requisition-edit-history', [
                        'record' => $record->loadMissing(['activityLogs.user']),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalWidth('4xl'),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user', 'items'])
            ->with(['activityLogs' => fn($query) => $query->latest()->with('user')])
            ->withCount('items')
            ->withSum('items as items_total_price', 'total_price');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseRequisitionLogs::route('/'),
        ];
    }

    public static function creatorName(PurchaseRequisition $record): string
    {
        return $record->creator_name ?: ($record->user?->name ?: '-');
    }

    public static function localStatusLabel(?string $state): string
    {
        return match ($state) {
            'draft' => 'Draft Lokal',
            'submitted' => 'Submitted',
            'cancelled' => 'Dibatalkan',
            default => filled($state) ? (string) $state : '-',
        };
    }

    public static function localStatusColor(?string $state): string
    {
        return match ($state) {
            'draft' => 'warning',
            'submitted' => 'success',
            'cancelled' => 'danger',
            default => 'gray',
        };
    }

    public static function sendResultLabel(PurchaseRequisition $record): string
    {
        return match ($record->sync_status) {
            'synced' => 'Berhasil',
            'failed' => self::isAmbiguous($record) ? 'Perlu Review' : 'Gagal',
            'processing' => 'Diproses',
            'pending' => 'Belum Dikirim',
            default => filled($record->sync_status) ? (string) $record->sync_status : '-',
        };
    }

    public static function sendResultColor(PurchaseRequisition $record): string
    {
        return match ($record->sync_status) {
            'synced' => 'success',
            'failed' => self::isAmbiguous($record) ? 'warning' : 'danger',
            'processing' => 'info',
            'pending' => 'gray',
            default => 'gray',
        };
    }

    public static function itemSummary(PurchaseRequisition $record): string
    {
        $visibleItems = $record->items->take(2)->map(function (PurchaseRequisitionItem $item): string {
            $quantity = rtrim(rtrim(number_format((float) $item->quantity, 6, '.', ''), '0'), '.');

            return e($item->item_name) . ' ' . e(trim("{$quantity} {$item->item_unit_name}"));
        });

        if ($visibleItems->isEmpty()) {
            return '-';
        }

        $remaining = $record->items->count() - $visibleItems->count();
        if ($remaining > 0) {
            $visibleItems->push('<span class="text-xs text-gray-500">+' . $remaining . ' barang lainnya</span>');
        }

        return $visibleItems->implode('<br>');
    }

    public static function detailModalHeading(PurchaseRequisition $record): string
    {
        return filled($record->accurate_number)
            ? 'Detail Permintaan Barang - ' . $record->accurate_number
            : 'Detail Permintaan Barang';
    }

    private static function isAmbiguous(PurchaseRequisition $record): bool
    {
        return str_contains((string) $record->error_message, 'AMBIGUOUS_REVIEW_REQUIRED');
    }

    private static function rupiah(mixed $value): string
    {
        return 'Rp ' . number_format((float) $value, 0, ',', '.');
    }

    public static function lastEditSummary(PurchaseRequisition $record): string
    {
        $log = $record->activityLogs->first();

        if (! $log) {
            return '-';
        }

        $actor = $log->user?->name ?: '-';
        $time = $log->created_at?->format('d/m/Y H:i') ?: '-';

        return e($log->action) . '<br><span class="text-xs text-gray-500">' . e("{$actor} - {$time}") . '</span>';
    }
}
