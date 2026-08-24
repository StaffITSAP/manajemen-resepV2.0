<?php

namespace App\Policies;

use App\Models\MasterBarang;
use App\Models\User;

class MasterBarangPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('view_master_barang');
    }

    public function view(User $user, MasterBarang $masterBarang): bool
    {
        return $user->hasPermission('view_master_barang');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('create_master_barang');
    }

    public function update(User $user, MasterBarang $masterBarang): bool
    {
        return $user->hasPermission('edit_master_barang');
    }

    public function delete(User $user, MasterBarang $masterBarang): bool
    {
        return $user->hasPermission('delete_master_barang');
    }

    public function restore(User $user, MasterBarang $masterBarang): bool
    {
        return $user->hasPermission('delete_master_barang');
    }

    public function forceDelete(User $user, MasterBarang $masterBarang): bool
    {
        return $user->hasPermission('delete_master_barang');
    }
}
