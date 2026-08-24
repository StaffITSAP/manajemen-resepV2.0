<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionLogPerubahanSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Permission baru untuk Log Perubahan
        $rows = [
            ['name' => 'view_log_perubahan',   'description' => 'View Log Perubahan'],
            ['name' => 'delete_log_perubahan', 'description' => 'Delete Log Perubahan'],
        ];

        // Insert permission bila belum ada
        foreach ($rows as $row) {
            $exists = DB::table('permissions')->where('name', $row['name'])->exists();
            if (! $exists) {
                DB::table('permissions')->insert([
                    'name'        => $row['name'],
                    'description' => $row['description'],
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        }

        // Ambil id permission
        $permIds = DB::table('permissions')
            ->whereIn('name', array_column($rows, 'name'))
            ->pluck('id', 'name');

        // Role yang akan diberi izin
        // superadmin: view + delete
        // owner: view saja
        // staff: tidak dapat izin (ubah sesuai kebutuhan)
        $map = [
            'superadmin' => ['view_log_perubahan', 'delete_log_perubahan'],
            'owner'      => ['view_log_perubahan'],
            // 'staff'   => ['view_log_perubahan'],
        ];

        $roles = DB::table('roles')
            ->whereIn('name', array_keys($map))
            ->pluck('id', 'name');

        foreach ($map as $roleName => $perms) {
            $roleId = $roles[$roleName] ?? null;
            if (! $roleId) continue;

            foreach ($perms as $pname) {
                $pid = $permIds[$pname] ?? null;
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
