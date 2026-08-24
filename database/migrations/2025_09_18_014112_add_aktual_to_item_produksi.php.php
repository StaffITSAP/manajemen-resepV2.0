<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_produksi', function (Blueprint $table) {
            $table->decimal('jumlah_aktual', 10, 2)->nullable()->after('jumlah');
            $table->decimal('selisih', 10, 2)->nullable()->after('jumlah_aktual');
            $table->text('keterangan_aktual')->nullable()->after('selisih');
        });
    }

    public function down(): void
    {
        Schema::table('item_produksi', function (Blueprint $table) {
            $table->dropColumn(['jumlah_aktual', 'selisih', 'keterangan_aktual']);
        });
    }
};