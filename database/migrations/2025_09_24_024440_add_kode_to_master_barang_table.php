<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_barang', function (Blueprint $table) {
            // Tambah kolom kode (boleh huruf/angka/tanda spesial), default '0' agar data lama aman
            // Panjang 100 cukup fleksibel; sesuaikan bila perlu.
            $table->string('kode', 100)->default('0')->after('nama');
            // Optional: index untuk pencarian cepat
            $table->index('kode', 'idx_master_barang_kode');
        });

        // Backfill data lama -> pastikan semua baris lama bernilai '0'
        DB::table('master_barang')
            ->whereNull('kode')
            ->orWhere('kode', '')
            ->update(['kode' => '0']);
    }

    public function down(): void
    {
        Schema::table('master_barang', function (Blueprint $table) {
            $table->dropIndex('idx_master_barang_kode');
            $table->dropColumn('kode');
        });
    }
};
