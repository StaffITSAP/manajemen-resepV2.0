<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // pastikan engine InnoDB (agar FK aktif)
        Schema::table('item_produksi', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->unsignedBigInteger('produksi_id')->change();
        });

        // drop FK lama jika ada
        try {
            Schema::table('item_produksi', function (Blueprint $table) {
                $table->dropForeign(['produksi_id']);
            });
        } catch (\Throwable $e) {}

        // tambah FK baru + CASCADE
        Schema::table('item_produksi', function (Blueprint $table) {
            $table->foreign('produksi_id')
                ->references('id')->on('produksi')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        try {
            Schema::table('item_produksi', function (Blueprint $table) {
                $table->dropForeign(['produksi_id']);
            });
        } catch (\Throwable $e) {}

        Schema::table('item_produksi', function (Blueprint $table) {
            $table->foreign('produksi_id')
                ->references('id')->on('produksi');
        });
    }
};
