<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages;
use App\Models\Role;
use App\Models\User;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string | \BackedEnum | null $navigationIcon  = 'heroicon-o-user-circle';
    protected static ?string $navigationLabel = 'Users';
    protected static string | \UnitEnum | null $navigationGroup = 'Settings';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi User')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama')
                            ->required()
                            ->maxLength(100)
                            ->live(onBlur: true),

                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(191)
                            ->unique(ignoreRecord: true),

                        // Username
                        TextInput::make('username')
                            ->label('Username')
                            ->required()
                            ->alphaDash()
                            ->maxLength(50)
                            ->helperText('Huruf, angka, dash (-), underscore (_), titik (.)')
                            ->dehydrateStateUsing(fn(?string $state) => self::sanitizeUsername((string) $state))
                            ->unique(ignoreRecord: true),

                        // ===== FIELD ROLE (JANGAN di-dehydrated(false) !!!) =====
                        Select::make('role_id')
                            ->label('Role')
                            ->options(fn() => Role::orderBy('id')->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->afterStateHydrated(function (Set $set, ?User $record) {
                                // Saat edit, isi select dari relasi pivot
                                if ($record) {
                                    $set('role_id', $record->roles()->value('roles.id'));
                                }
                            }),

                        TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->rule(Password::default())
                            ->required(fn(string $operation) => $operation === 'create')
                            ->dehydrated(fn($state) => filled($state))
                            ->dehydrateStateUsing(fn(?string $state) => filled($state) ? bcrypt($state) : null)
                            ->same('password_confirmation'),

                        TextInput::make('password_confirmation')
                            ->label('Konfirmasi Password')
                            ->password()
                            ->revealable()
                            ->required(fn(string $operation) => $operation === 'create')
                            ->dehydrated(false),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('username')->label('Username')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable()->sortable(),
                TextColumn::make('role')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'superadmin' => 'danger',
                        'owner' => 'warning',
                        'staff' => 'info',
                        'kitchen' => 'success',
                        'spv' => 'primary',
                        default => 'gray',
                    })
                    ->label('Role Utama'),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->requiresConfirmation(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit'   => EditUser::route('/{record}/edit'),
        ];
    }

    /** Sanitizer username */
    public static function sanitizeUsername(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', '_', $value);
        $value = preg_replace('/[^A-Za-z0-9_.-]/', '', $value) ?? '';
        return Str::limit($value, 50, '');
    }

    /** Generate username unik bila diperlukan */
    public static function makeUniqueUsername(string $base): string
    {
        $base = self::sanitizeUsername($base) ?: 'user';
        $candidate = Str::limit($base, 50, '');
        $i = 1;

        while (User::whereRaw('LOWER(username) = ?', [strtolower($candidate)])->exists()) {
            $candidate = Str::limit($base, 45, '') . $i;
            $i++;
        }

        return $candidate;
    }

    // Batasi akses menu (opsional sesuai punyamu)
    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()->hasRole('superadmin');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->hasRole('superadmin');
    }
}
