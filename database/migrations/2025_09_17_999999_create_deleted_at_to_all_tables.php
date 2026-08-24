<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tambahkan deleted_at ke semua tabel yang menggunakan soft deletes
        $tables = [
            'master_satuan',
            'master_barang',
            'resep',
            'bahan_resep',
            'produksi',
            'item_produksi',
            'log_perubahan',
            'notifications',
            'users',
            'roles',
            'role_permission',
            'permissions'
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        $tables = [
            'master_satuan',
            'master_barang',
            'resep',
            'bahan_resep',
            'produksi',
            'item_produksi',
            'log_perubahan',
            'notifications',
            'users',
            'roles',
            'role_permission',
            'permissions'
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropSoftDeletes();
                });
            }
        }
    }
};
