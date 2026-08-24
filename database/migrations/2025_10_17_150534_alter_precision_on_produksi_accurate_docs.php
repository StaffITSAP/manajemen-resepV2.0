<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('produksi_accurate_docs', function (Blueprint $table) {
            // presisi besar, default 0 (bukan null)
            $table->decimal('total_amount', 24, 8)->default(0)->nullable(false)->change();
            $table->decimal('allocation_amount', 24, 8)->default(0)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('produksi_accurate_docs', function (Blueprint $table) {
            $table->decimal('total_amount', 20, 4)->nullable()->change();
            $table->decimal('allocation_amount', 20, 4)->nullable()->change();
        });
    }
};
