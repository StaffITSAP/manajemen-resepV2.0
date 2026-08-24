<?php

namespace App\Policies;

use App\Models\Produksi;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProduksiPolicy
{
    use HandlesAuthorization;

    /**
     * superadmin auto-allow semua ability.
     */
    public function before(User $user, string $ability): bool|null
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('view_produksi');
    }

    public function view(User $user, Produksi $produksi): bool
    {
        return $user->hasPermission('view_produksi');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('create_produksi');
    }

    public function update(User $user, Produksi $produksi): bool
    {
        return $user->hasPermission('edit_produksi');
    }

    public function delete(User $user, Produksi $produksi): bool
    {
        return $user->hasPermission('delete_produksi');
    }

    public function restore(User $user, Produksi $produksi): bool
    {
        return $user->hasPermission('delete_produksi');
    }

    public function forceDelete(User $user, Produksi $produksi): bool
    {
        return $user->hasPermission('delete_produksi');
    }

    /**
     * Ability kustom untuk ekspor.
     * Gunakan permission yang sudah ada di DB: 'export_excel'
     * (atau ganti ke 'export_produksi' kalau kamu pisahkan per resource).
     */
    public function export(User $user): bool
    {
        return $user->hasPermission('export_excel');
    }
    /**
     * ===== Ability khusus untuk LAPORAN PEMAKAIAN BAHAN =====
     */
    public function viewReportBahan(User $user): bool
    {
        return $user->hasPermission('view_laporan_pemakaian_bahan');
    }

    public function exportReportBahan(User $user): bool
    {
        return $user->hasPermission('export_laporan_pemakaian_bahan');
    }
}
