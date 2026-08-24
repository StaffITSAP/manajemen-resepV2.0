<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Hapus data resep yang tidak valid
        DB::table('resep')->whereNull('barang_setengah_jadi_id')->delete();

        // Ubah kolom menjadi required
        Schema::table('resep', function (Blueprint $table) {
            $table->foreignId('barang_setengah_jadi_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('resep', function (Blueprint $table) {
            $table->foreignId('barang_setengah_jadi_id')->nullable()->change();
        });
    }
};