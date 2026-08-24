<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bahan_produksi', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->unsignedBigInteger('item_produksi_id')->change();
        });

        try {
            Schema::table('bahan_produksi', function (Blueprint $table) {
                $table->dropForeign(['item_produksi_id']);
            });
        } catch (\Throwable $e) {}

        Schema::table('bahan_produksi', function (Blueprint $table) {
            $table->foreign('item_produksi_id')
                ->references('id')->on('item_produksi')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        try {
            Schema::table('bahan_produksi', function (Blueprint $table) {
                $table->dropForeign(['item_produksi_id']);
            });
        } catch (\Throwable $e) {}

        Schema::table('bahan_produksi', function (Blueprint $table) {
            $table->foreign('item_produksi_id')
                ->references('id')->on('item_produksi');
        });
    }
};
