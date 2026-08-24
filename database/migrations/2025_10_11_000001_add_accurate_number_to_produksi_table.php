<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('produksi', function (Blueprint $table) {
            // taruh setelah status biar rapi, boleh disesuaikan
            $table->string('accurate_number', 255)->nullable()->after('nomor_produksi')->index();
        });
    }

    public function down(): void
    {
        Schema::table('produksi', function (Blueprint $table) {
            $table->dropColumn('accurate_number');
        });
    }
};
