<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('produksi', function (Blueprint $table) {
            $table->string('accurate_rollover_number')->nullable()->after('accurate_number');
        });
    }

    public function down(): void
    {
        Schema::table('produksi', function (Blueprint $table) {
            $table->dropColumn('accurate_rollover_number');
        });
    }
};
