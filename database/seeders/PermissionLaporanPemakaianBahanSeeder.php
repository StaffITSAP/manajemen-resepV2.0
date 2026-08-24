<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionLaporanPemakaianBahanSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Permission baru
        $items = [
            [
                'name'        => 'view_laporan_pemakaian_bahan',
                'description' => 'View Laporan Pemakaian Bahan',
            ],
            [
                'name'        => 'export_laporan_pemakaian_bahan',
                'description' => 'Export Laporan Pemakaian Bahan',
            ],
        ];

        // Insert permissions jika belum ada (tanpa guard_name)
        foreach ($items as $item) {
            $exists = DB::table('permissions')->where('name', $item['name'])->exists();
            if (! $exists) {
                DB::table('permissions')->insert([
                    'name'        => $item['name'],
                    'description' => $item['description'],
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        }

        // Ambil id permission yang baru / sudah ada
        $permIds = DB::table('permissions')
            ->whereIn('name', array_column($items, 'name'))
            ->pluck('id', 'name');

        // Role target (menurut tabel roles kamu)
        $roleNames = ['superadmin', 'owner'/*, 'staff'*/]; // aktifkan 'staff' kalau boleh view saja
        $roles = DB::table('roles')->whereIn('name', $roleNames)->pluck('id', 'name');

        // Mapping role -> permission
        $map = [
            'superadmin' => ['view_laporan_pemakaian_bahan', 'export_laporan_pemakaian_bahan'],
            'owner'      => ['view_laporan_pemakaian_bahan', 'export_laporan_pemakaian_bahan'],
            // 'staff'    => ['view_laporan_pemakaian_bahan'],
        ];

        // Attach ke pivot role_permission bila belum ada
        foreach ($map as $roleName => $perms) {
            $roleId = $roles[$roleName] ?? null;
            if (! $roleId) continue;

            foreach ($perms as $p) {
                $pid = $permIds[$p] ?? null;
                if (! $pid) continue;

                $exists = DB::table('role_permission')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $pid)
                    ->exists();

                if (! $exists) {
                    DB::table('role_permission')->insert([
                        'role_id'       => $roleId,
                        'permission_id' => $pid,
                    ]);
                }
            }
        }
    }
}
