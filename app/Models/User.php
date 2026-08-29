<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasFactory, SoftDeletes, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'username',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role', 'user_id', 'role_id');
    }
    // Nama role utama (pertama) untuk tampilan tabel
    public function getRoleNameAttribute(): ?string
    {
        return $this->roles()->value('name');
    }

    public function hasRole($roles): bool
    {
        if (is_string($roles)) {
            return $this->role === $roles || $this->roles->contains('name', $roles);
        }

        if (is_array($roles)) {
            foreach ($roles as $role) {
                if ($this->role === $role || $this->roles->contains('name', $role)) {
                    return true;
                }
            }
            return false;
        }

        return $roles->contains('name', $this->roles->pluck('name'));
    }

    public function hasPermission($permission): bool
    {
        foreach ($this->roles as $role) {
            if ($role->permissions->contains('name', $permission)) {
                return true;
            }
        }
        return false;
    }
    protected static function booted(): void
    {
        // Auto-isi username saat INSERT jika tidak diisi dari form
        static::creating(function (User $user) {
            if (blank($user->username)) {
                $user->username = static::makeUniqueUsername($user->name, $user->email);
            }
        });

        // Opsional: rapikan username saat UPDATE jika diubah manual (hapus spasi dll)
        static::saving(function (User $user) {
            if (! blank($user->username)) {
                $user->username = static::sanitizeUsername($user->username);

                // Pastikan unik case-insensitive kalau berubah
                if ($user->isDirty('username')) {
                    $base = $user->username;
                    $candidate = $base;
                    $i = 1;

                    while (
                        static::whereRaw('LOWER(username) = ?', [strtolower($candidate)])
                        ->where('id', '!=', $user->id ?? 0)
                        ->exists()
                    ) {
                        $candidate = Str::limit($base, 45, '') . $i; // jaga <= 50 char
                        $i++;
                    }

                    $user->username = $candidate;
                }
            }
        });
    }

    /** Buat username unik dari name/email */
    protected static function makeUniqueUsername(?string $name, ?string $email): string
    {
        // base dari name -> slug underscore; fallback dari email (sebelum @); terakhir 'user'
        $base = trim((string) $name) !== ''
            ? static::sanitizeUsername(Str::slug($name, '_'))
            : static::sanitizeUsername((string) Str::before((string) $email, '@'));

        $base = $base !== '' ? $base : 'user';

        $candidate = Str::limit($base, 50, '');
        $i = 1;

        // cek unik case-insensitive
        while (static::whereRaw('LOWER(username) = ?', [strtolower($candidate)])->exists()) {
            $candidate = Str::limit($base, 45, '') . $i; // sisakan slot suffix
            $i++;
        }

        return $candidate;
    }

    /** Bersihkan karakter tak valid, sisakan alpha-num + _ - . */
    protected static function sanitizeUsername(string $value): string
    {
        $value = trim($value);
        // ganti spasi menjadi underscore
        $value = preg_replace('/\s+/', '_', $value);
        // buang karakter selain [A-Za-z0-9_.-]
        $value = preg_replace('/[^A-Za-z0-9_.-]/', '', $value) ?? '';
        // maksimal 50 char
        return Str::limit($value, 50, '');
    }
}
