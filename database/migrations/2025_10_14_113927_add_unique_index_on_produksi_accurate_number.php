<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produksi', function (Blueprint $table) {
            // Pastikan panjang kolom cukup & nullable
            $table->string('accurate_number', 64)->nullable()->change();

            // Unique index; MySQL mengizinkan banyak NULL, tapi value yang sama tidak boleh.
            $table->unique('accurate_number', 'produksi_accurate_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('produksi', function (Blueprint $table) {
            $table->dropUnique('produksi_accurate_number_unique');
        });
    }
};
