<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_requisition_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_requisition_items', 'latest_price_source_type')) {
                $table->string('latest_price_source_type', 20)->nullable()->after('total_price')->index();
            }

            if (! Schema::hasColumn('purchase_requisition_items', 'source_document_accurate_id')) {
                $table->unsignedBigInteger('source_document_accurate_id')->nullable()->after('latest_price_source_type')->index();
            }

            if (! Schema::hasColumn('purchase_requisition_items', 'source_document_number')) {
                $table->string('source_document_number')->nullable()->after('source_document_accurate_id')->index();
            }

            if (! Schema::hasColumn('purchase_requisition_items', 'source_document_date')) {
                $table->date('source_document_date')->nullable()->after('source_document_number')->index();
            }

            if (! Schema::hasColumn('purchase_requisition_items', 'source_price_synced_at')) {
                $table->timestamp('source_price_synced_at')->nullable()->after('source_document_date')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requisition_items', function (Blueprint $table): void {
            $columns = [
                'latest_price_source_type',
                'source_document_accurate_id',
                'source_document_number',
                'source_document_date',
                'source_price_synced_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('purchase_requisition_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
