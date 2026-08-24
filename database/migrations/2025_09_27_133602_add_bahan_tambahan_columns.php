<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('item_produksi', function (Blueprint $table) {
            $table->boolean('enable_bahan_tambahan')->default(false)->after('selisih');
        });

        Schema::table('bahan_produksi', function (Blueprint $table) {
            $table->boolean('is_manual')->default(false)->after('item_produksi_id');
        });
    }

    public function down(): void
    {
        Schema::table('item_produksi', function (Blueprint $table) {
            $table->dropColumn('enable_bahan_tambahan');
        });

        Schema::table('bahan_produksi', function (Blueprint $table) {
            $table->dropColumn('is_manual');
        });
    }
};
