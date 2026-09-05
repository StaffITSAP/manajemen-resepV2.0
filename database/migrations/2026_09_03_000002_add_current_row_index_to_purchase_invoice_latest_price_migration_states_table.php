<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_invoice_latest_price_migration_states', function (Blueprint $table) {
            $table->unsignedInteger('current_row_index')->default(0)->after('current_page');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_latest_price_migration_states', function (Blueprint $table) {
            $table->dropColumn('current_row_index');
        });
    }
};
