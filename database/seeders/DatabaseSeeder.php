<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            UserSeeder::class,
            MasterSatuanSeeder::class,
            PermissionLogPerubahanSeeder::class,
            PermissionLaporanPemakaianBahanSeeder::class,
            MasterBarangSeeder::class,
            ResepSeeder::class,
            ProduksiSeeder::class,
        ]);
    }
}