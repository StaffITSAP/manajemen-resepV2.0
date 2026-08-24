<?php

namespace App\Policies;

use App\Models\MasterSatuan;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class MasterSatuanPolicy
{
    use HandlesAuthorization;

    /**
     * Superadmin boleh semua.
     */
    public function before(User $user, string $ability): bool|null
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    /**
     * Lihat daftar Master Satuan.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('view_master_satuan');
    }

    /**
     * Lihat detail Master Satuan.
     */
    public function view(User $user, MasterSatuan $masterSatuan): bool
    {
        return $user->hasPermission('view_master_satuan');
    }

    /**
     * Tambah Master Satuan.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('create_master_satuan');
    }

    /**
     * Edit Master Satuan.
     */
    public function update(User $user, MasterSatuan $masterSatuan): bool
    {
        return $user->hasPermission('edit_master_satuan');
    }

    /**
     * Soft delete Master Satuan.
     */
    public function delete(User $user, MasterSatuan $masterSatuan): bool
    {
        return $user->hasPermission('delete_master_satuan');
    }

    /**
     * Restore Master Satuan yang terhapus.
     */
    public function restore(User $user, MasterSatuan $masterSatuan): bool
    {
        return $user->hasPermission('delete_master_satuan');
    }

    /**
     * Hapus permanen Master Satuan.
     */
    public function forceDelete(User $user, MasterSatuan $masterSatuan): bool
    {
        return $user->hasPermission('delete_master_satuan');
    }
}
