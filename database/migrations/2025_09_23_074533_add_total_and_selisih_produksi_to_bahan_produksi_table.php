<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bahan_produksi', function (Blueprint $table) {
            // setelah jumlah_aktual
            $table->decimal('total_produksi', 15, 2)->default(0)->after('jumlah_aktual');
            $table->decimal('selisih_produksi', 15, 2)->default(0)->after('total_produksi');
        });
    }

    public function down(): void
    {
        Schema::table('bahan_produksi', function (Blueprint $table) {
            $table->dropColumn(['total_produksi', 'selisih_produksi']);
        });
    }
};
