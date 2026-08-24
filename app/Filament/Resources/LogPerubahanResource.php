<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LogPerubahanResource\Pages;
use App\Models\LogPerubahan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class LogPerubahanResource extends Resource
{
    protected static ?string $model = LogPerubahan::class;

    protected static ?string $navigationIcon  = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Laporan';
    protected static ?string $modelLabel      = 'Log Perubahan';
    protected static ?int    $navigationSort  = 3;

    // Menu hanya tampil jika user boleh melihat log
    public static function shouldRegisterNavigation(): bool
    {
        return Gate::allows('viewAny', LogPerubahan::class);
    }

    // Tidak dipakai (read-only), tapi biarkan ada.
    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('model_type')->disabled(),
            Forms\Components\TextInput::make('model_id')->disabled(),
            Forms\Components\TextInput::make('aksi')->disabled(),
            Forms\Components\Textarea::make('keterangan')->disabled()->columnSpanFull(),
            Forms\Components\Textarea::make('data_lama')->disabled()->columnSpanFull(),
            Forms\Components\Textarea::make('data_baru')->disabled()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('model_type')
                    ->label('Model')
                    ->formatStateUsing(fn($state) => class_basename($state))
                    ->searchable(),
                Tables\Columns\TextColumn::make('model_id')
                    ->label('ID Model')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->sortable(),
                Tables\Columns\TextColumn::make('aksi')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'create' => 'success',
                        'update' => 'warning',
                        'delete' => 'danger',
                        default  => 'gray',
                    })
                    ->searchable(),
                Tables\Columns\TextColumn::make('keterangan')
                    ->limit(80)
                    ->tooltip(fn($state) => $state),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('model_type')
                    ->options([
                        'App\Models\Resep'    => 'Resep',
                        'App\Models\Produksi' => 'Produksi',
                    ]),
                Tables\Filters\SelectFilter::make('aksi')
                    ->options([
                        'create' => 'Create',
                        'update' => 'Update',
                        'delete' => 'Delete',
                    ]),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('dari_tanggal'),
                        Forms\Components\DatePicker::make('sampai_tanggal'),
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
            ->actions([
                // hanya View
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                // Hanya tampil jika user boleh hapus log
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn() => Gate::allows('delete', LogPerubahan::query()->first() ?? new LogPerubahan())),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLogPerubahans::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user']); // untuk hindari N+1
    }
}
