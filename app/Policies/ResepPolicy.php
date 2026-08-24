<?php

namespace App\Policies;

use App\Models\Resep;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ResepPolicy
{
    use HandlesAuthorization;

    // superadmin auto-allow semua ability
    public function before(User $user, string $ability): bool|null
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('view_resep');
    }

    public function view(User $user, Resep $resep): bool
    {
        return $user->hasPermission('view_resep');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('create_resep');
    }

    public function update(User $user, Resep $resep): bool
    {
        return $user->hasPermission('edit_resep');
    }

    public function delete(User $user, Resep $resep): bool
    {
        return $user->hasPermission('delete_resep');
    }

    public function restore(User $user, Resep $resep): bool
    {
        return $user->hasPermission('delete_resep');
    }

    public function forceDelete(User $user, Resep $resep): bool
    {
        return $user->hasPermission('delete_resep');
    }

    /**
     * Ability kustom untuk ekspor.
     * Pakai permission yang sudah ada di DB: 'export_excel'
     * (kalau kamu pisahkan per resource, ganti ke 'export_resep').
     */
    public function export(User $user): bool
    {
        return $user->hasPermission('export_excel');
    }
}
