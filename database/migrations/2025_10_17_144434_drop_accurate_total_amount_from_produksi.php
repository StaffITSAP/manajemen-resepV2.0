<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('produksi', 'accurate_total_amount')) {
            Schema::table('produksi', function (Blueprint $table) {
                $table->dropColumn('accurate_total_amount');
            });
        }
    }

    public function down(): void
    {
        Schema::table('produksi', function (Blueprint $table) {
            $table->decimal('accurate_total_amount', 20, 6)->nullable();
        });
    }
};
