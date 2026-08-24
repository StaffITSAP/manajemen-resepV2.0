<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_requisition_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_requisition_id')
                ->constrained('purchase_requisitions')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('accurate_item_id')
                ->nullable()
                ->constrained('accurate_items')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->unsignedBigInteger('item_accurate_id')->index();
            $table->string('item_no')->index();
            $table->string('item_name');

            $table->unsignedBigInteger('item_unit_accurate_id')->index();
            $table->string('item_unit_name');

            $table->decimal('quantity', 24, 6)->default(0);
            $table->date('required_date')->index();
            $table->text('note')->nullable();

            $table->decimal('latest_purchase_unit_price', 24, 8)->default(0);
            $table->decimal('total_price', 24, 8)->default(0);

            $table->unsignedBigInteger('source_purchase_order_accurate_id')->nullable()->index('pri_src_po_acc_id_idx');
            $table->string('source_purchase_order_number')->nullable()->index();
            $table->date('source_purchase_order_date')->nullable()->index();

            $table->timestamps();

            $table->index(['item_accurate_id', 'item_unit_accurate_id'], 'pr_items_item_unit_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requisition_items');
    }
};
