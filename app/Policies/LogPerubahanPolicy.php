<?php

namespace App\Policies;

use App\Models\LogPerubahan;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class LogPerubahanPolicy
{
    use HandlesAuthorization;

    /**
     * Superadmin auto-allow semua ability.
     */
    public function before(User $user, string $ability): bool|null
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    // ===== READ-ONLY (default) =====
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('view_log_perubahan');
    }

    public function view(User $user, LogPerubahan $log): bool
    {
        return $user->hasPermission('view_log_perubahan');
    }

    // Tidak ada create/update utk log
    public function create(User $user): bool { return false; }
    public function update(User $user, LogPerubahan $log): bool { return false; }

    // Hapus hanya jika punya permission khusus (opsional)
    public function delete(User $user, LogPerubahan $log): bool
    {
        return $user->hasPermission('delete_log_perubahan');
    }

    public function restore(User $user, LogPerubahan $log): bool
    {
        return $user->hasPermission('delete_log_perubahan');
    }

    public function forceDelete(User $user, LogPerubahan $log): bool
    {
        return $user->hasPermission('delete_log_perubahan');
    }
}
