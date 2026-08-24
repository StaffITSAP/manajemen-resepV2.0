<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ItemAdjustmentUploadResource\Pages;
use App\Models\ItemAdjustmentUpload;
use App\Services\Accurate\ItemAdjustmentImporter;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Filament\Tables\Filters\TrashedFilter;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ItemAdjustmentUploadResource extends Resource
{
    protected static ?string $model = ItemAdjustmentUpload::class;
    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationGroup = 'Accurate Online';
    protected static ?string $navigationLabel = 'Upload Item Adjustment';
    protected static ?string $modelLabel = 'Upload Item Adjustment';

    /**
     * Record boleh diedit kalau:
     * - status BUKAN success
     * - dan accurate_number & accurate_id KOSONG (sudah reset / belum pernah kirim)
     */
    public static function canEditRecord(ItemAdjustmentUpload $record): bool
    {
        return $record->status !== 'success'
            && blank($record->accurate_number)
            && blank($record->accurate_id);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Import Data')
                ->schema([
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Forms\Components\FileUpload::make('path')
                                ->label('File Excel')
                                ->disk('public')
                                ->directory('imports/item_adjustment')
                                ->acceptedFileTypes([
                                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                    'application/vnd.ms-excel',
                                ])
                                ->preserveFilenames()
                                ->getUploadedFileNameForStorageUsing(
                                    function (TemporaryUploadedFile $file): string {
                                        $original  = $file->getClientOriginalName();
                                        $name      = pathinfo($original, PATHINFO_FILENAME);
                                        $extension = $file->getClientOriginalExtension();

                                        $safeName = preg_replace('/[^A-Za-z0-9_\- ]+/', '_', $name);
                                        $safeName = trim(preg_replace('/_+/', '_', $safeName), '_');

                                        if ($safeName === '') {
                                            $safeName = 'item_adjustment';
                                        }

                                        return $safeName . '-' . now()->format('Ymd-His') . '.' . $extension;
                                    }
                                )
                                ->required()
                                ->columnSpanFull()
                                // ✅ ganti file hanya kalau record boleh diedit
                                ->disabled(function (?ItemAdjustmentUpload $record) {
                                    if (! $record) {
                                        return false; // create → bebas
                                    }

                                    return ! static::canEditRecord($record);
                                }),

                            Forms\Components\DatePicker::make('trans_date')
                                ->label('Tanggal Transaksi (transDate)')
                                ->native(false)
                                ->displayFormat('d/m/Y')
                                ->disabled()
                                ->dehydrated(true)
                                ->closeOnDateSelection(),

                            Forms\Components\TextInput::make('description')
                                ->label('Deskripsi (description)')
                                ->maxLength(255)
                                ->disabled()
                                ->dehydrated(true),

                            Forms\Components\TextInput::make('adjustment_account_no')
                                ->label('Adjustment Account No (adjustmentAccountNo)')
                                ->placeholder('misal: 4000.01.01')
                                ->maxLength(50)
                                ->required()
                                // ✅ semua role boleh edit kalau record boleh diedit
                                ->disabled(function (?ItemAdjustmentUpload $record) {
                                    if (! $record) {
                                        return false; // create → bebas
                                    }

                                    return ! static::canEditRecord($record);
                                })
                                ->columnSpanFull(),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Nama')
                    ->searchable(),

                Tables\Columns\TextColumn::make('trans_date')
                    ->date('d/m/Y')
                    ->label('Trans Date')
                    ->searchable(),

                Tables\Columns\TextColumn::make('description')
                    ->limit(40)
                    ->tooltip(fn($state) => $state),

                // ==========================
                // INLINE EDIT (adjustment_account_no)
                // ==========================
                Tables\Columns\TextInputColumn::make('adjustment_account_no')
                    ->label('Adjustment Acc No')
                    ->rules(['required', 'max:50'])
                    // ✅ inline edit hanya kalau record boleh diedit
                    ->disabled(fn(ItemAdjustmentUpload $record) => ! static::canEditRecord($record))
                    ->extraAttributes(['class' => 'font-mono text-xs']),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn($state) => match ($state) {
                        'pending'    => 'warning',
                        'processing' => 'info',
                        'success'    => 'success',
                        'failed'     => 'danger',
                        default      => 'gray',
                    })
                    ->searchable(),

                Tables\Columns\TextColumn::make('accurate_number')
                    ->label('Accurate Number')
                    ->copyable()
                    ->searchable()
                    ->badge()
                    ->color(fn($state) => $state ? 'info' : 'gray'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->formatStateUsing(
                        fn($state) =>
                        Carbon::parse($state)->locale('id')->translatedFormat('d F Y, H:i')
                    ),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),

                    // ✅ tombol Edit (buka halaman edit)
                    Tables\Actions\EditAction::make()
                        ->visible(fn(ItemAdjustmentUpload $record) => static::canEditRecord($record)),

                    Tables\Actions\Action::make('download')
                        ->label('Download File')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->visible(fn(ItemAdjustmentUpload $record) => filled($record->path))
                        ->url(fn(ItemAdjustmentUpload $record) => Storage::disk('public')->url($record->path))
                        ->openUrlInNewTab(),

                    Tables\Actions\Action::make('resetAccurate')
                        ->label('Reset Accurate')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Reset Data Accurate')
                        ->visible(fn() => auth()->user()?->hasRole('superadmin'))
                        ->disabled(
                            fn(ItemAdjustmentUpload $record) =>
                            blank($record->accurate_number) && blank($record->accurate_id) && $record->status === 'pending'
                        )
                        ->tooltip(
                            fn(ItemAdjustmentUpload $record) => (blank($record->accurate_number) && blank($record->accurate_id) && $record->status === 'pending')
                                ? 'Tidak ada data Accurate yang perlu direset.'
                                : 'Kosongkan nomor Accurate & kembalikan status ke pending.'
                        )
                        ->modalDescription(fn(ItemAdjustmentUpload $record) => sprintf(
                            "Tindakan ini akan mengosongkan kolom & set status = pending:\n- Accurate Number: %s\n- Accurate ID: %s\n- Adjustment Acc No: %s\n\nSetelah ini, file & Adjustment Acc No bisa diedit.\nLanjutkan?",
                            $record->accurate_number ?: '—',
                            $record->accurate_id ?: '—',
                            $record->adjustment_account_no ?: '—',
                        ))
                        ->action(function (ItemAdjustmentUpload $record) {
                            DB::transaction(function () use ($record) {
                                $locked = $record->newQuery()
                                    ->whereKey($record->getKey())
                                    ->lockForUpdate()
                                    ->firstOrFail();

                                $locked->forceFill([
                                    'accurate_number'        => null,
                                    'accurate_id'            => null,
                                    'adjustment_account_no'  => null,
                                    'status'                 => 'pending',
                                ])->saveQuietly();
                            });

                            $record->refresh();

                            Notification::make()
                                ->title('Data Accurate direset')
                                ->body('Nomor Accurate, ID, dan Adjustment Acc No dikosongkan. Status balik ke pending. File & adjustment account sekarang bisa diedit.')
                                ->success()
                                ->send();
                        }),

                    Tables\Actions\Action::make('resendAccurate')
                        ->label('Kirim Ulang ke Accurate')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn() => true)
                        ->disabled(
                            fn(ItemAdjustmentUpload $record) =>
                            filled($record->accurate_number) || filled($record->accurate_id)
                        )
                        ->tooltip(
                            fn(ItemAdjustmentUpload $record) => (filled($record->accurate_number) || filled($record->accurate_id))
                                ? 'Tidak bisa kirim ulang: reset dulu Accurate Number/ID & pastikan transaksi di Accurate sudah dihapus.'
                                : null
                        )
                        ->action(function (ItemAdjustmentUpload $record) {
                            if (filled($record->accurate_number) || filled($record->accurate_id)) {
                                Notification::make()
                                    ->title('Tidak bisa kirim ulang')
                                    ->body('Hapus dulu Accurate Number/ID (Reset Accurate) dan pastikan transaksi di Accurate Online sudah dihapus.')
                                    ->danger()
                                    ->send();
                                return;
                            }

                            /** @var \App\Services\Accurate\ItemAdjustmentImporter $importer */
                            $importer = app(ItemAdjustmentImporter::class);
                            $importer->process($record);

                            $fresh = $record->fresh();
                            if ($fresh->status === 'success') {
                                Notification::make()
                                    ->title('Berhasil kirim ulang ke Accurate')
                                    ->body('Nomor: ' . ($fresh->accurate_number ?: '-'))
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Gagal kirim ulang ke Accurate')
                                    ->body($fresh->error_message ?: 'Periksa log')
                                    ->danger()
                                    ->send();
                            }
                        }),

                    Tables\Actions\DeleteAction::make(),
                    Tables\Actions\ForceDeleteAction::make(),
                    Tables\Actions\RestoreAction::make(),
                ]),
            ])
            ->actionsPosition(ActionsPosition::BeforeColumns)
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListItemAdjustmentUploads::route('/'),
            'create' => Pages\CreateItemAdjustmentUpload::route('/create'),
            'view'   => Pages\ViewItemAdjustmentUpload::route('/{record}'),
            // ✅ route edit
            'edit'   => Pages\EditItemAdjustmentUpload::route('/{record}/edit'),
        ];
    }
}
