<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_invoice_latest_price_migration_states', function (Blueprint $table): void {
            $table->date('incremental_run_upper_trans_date')->nullable()->after('incremental_row_index');
            $table->date('incremental_completed_upper_trans_date')->nullable()->after('incremental_run_upper_trans_date');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_latest_price_migration_states', function (Blueprint $table): void {
            $table->dropColumn([
                'incremental_run_upper_trans_date',
                'incremental_completed_upper_trans_date',
            ]);
        });
    }
};
