<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Filament\Actions\ViewAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\LogPerubahanResource\Pages\ListLogPerubahans;
use App\Filament\Resources\LogPerubahanResource\Pages;
use App\Models\LogPerubahan;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class LogPerubahanResource extends Resource
{
    protected static ?string $model = LogPerubahan::class;

    protected static string | \BackedEnum | null $navigationIcon  = 'heroicon-o-document-text';
    protected static string | \UnitEnum | null $navigationGroup = 'Laporan';
    protected static ?string $modelLabel      = 'Log Perubahan';
    protected static ?int    $navigationSort  = 3;

    // Menu hanya tampil jika user boleh melihat log
    public static function shouldRegisterNavigation(): bool
    {
        return Gate::allows('viewAny', LogPerubahan::class);
    }

    // Tidak dipakai (read-only), tapi biarkan ada.
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('model_type')->disabled(),
            TextInput::make('model_id')->disabled(),
            TextInput::make('aksi')->disabled(),
            Textarea::make('keterangan')->disabled()->columnSpanFull(),
            Textarea::make('data_lama')->disabled()->columnSpanFull(),
            Textarea::make('data_baru')->disabled()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('model_type')
                    ->label('Model')
                    ->formatStateUsing(fn($state) => class_basename($state))
                    ->searchable(),
                TextColumn::make('model_id')
                    ->label('ID Model')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->sortable(),
                TextColumn::make('aksi')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'create' => 'success',
                        'update' => 'warning',
                        'delete' => 'danger',
                        default  => 'gray',
                    })
                    ->searchable(),
                TextColumn::make('keterangan')
                    ->limit(80)
                    ->tooltip(fn($state) => $state),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('model_type')
                    ->options([
                        'App\Models\Resep'    => 'Resep',
                        'App\Models\Produksi' => 'Produksi',
                    ]),
                SelectFilter::make('aksi')
                    ->options([
                        'create' => 'Create',
                        'update' => 'Update',
                        'delete' => 'Delete',
                    ]),
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('dari_tanggal'),
                        DatePicker::make('sampai_tanggal'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['dari_tanggal'] ?? null,
                                fn(Builder $q, $date) => $q->whereDate('created_at', '>=', $date)
                            )
                            ->when(
                                $data['sampai_tanggal'] ?? null,
                                fn(Builder $q, $date) => $q->whereDate('created_at', '<=', $date)
                            );
                    }),
            ])
            ->recordActions([
                // hanya View
                ViewAction::make(),
            ])
            ->toolbarActions([
                // Hanya tampil jika user boleh hapus log
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn() => Gate::allows('delete', LogPerubahan::query()->first() ?? new LogPerubahan())),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLogPerubahans::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user']); // untuk hindari N+1
    }
}
