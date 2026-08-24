<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('produksi', function (Blueprint $table) {
            // simpan totalAmount dari job-order Accurate
            $table->decimal('accurate_total_amount', 20, 2)->nullable()->after('accurate_number');
        });
    }

    public function down(): void
    {
        Schema::table('produksi', function (Blueprint $table) {
            $table->dropColumn('accurate_total_amount');
        });
    }
};
