<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Pastikan kolom sudah benar tipenya
        Schema::table('item_produksi', function (Blueprint $table) {
            // kalau sudah unsignedBigInteger abaikan (operation is idempotent)
            $table->unsignedBigInteger('produksi_id')->change();
        });

        // Drop FK lama (jika ada) lalu buat ulang dengan CASCADE
        // Nama default FK biasanya: item_produksi_produksi_id_foreign
        try {
            Schema::table('item_produksi', function (Blueprint $table) {
                $table->dropForeign(['produksi_id']);
            });
        } catch (\Throwable $e) {
            // FK mungkin belum ada / nama beda — abaikan
        }

        // Buat FK baru dengan CASCADE
        Schema::table('item_produksi', function (Blueprint $table) {
            $table
                ->foreign('produksi_id')
                ->references('id')
                ->on('produksi')
                ->onUpdate('cascade')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        // Kembalikan ke kondisi tanpa CASCADE (drop FK baru)
        try {
            Schema::table('item_produksi', function (Blueprint $table) {
                $table->dropForeign(['produksi_id']);
            });
        } catch (\Throwable $e) {}

        // (opsional) buat FK tanpa cascade — atau biarkan tanpa FK
        Schema::table('item_produksi', function (Blueprint $table) {
            $table
                ->foreign('produksi_id')
                ->references('id')
                ->on('produksi');
        });
    }
};
