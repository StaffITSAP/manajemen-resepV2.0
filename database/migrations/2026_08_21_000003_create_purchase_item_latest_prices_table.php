<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_item_latest_prices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('accurate_item_id')
                ->nullable()
                ->constrained('accurate_items')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->unsignedBigInteger('item_accurate_id');
            $table->string('item_no')->nullable()->index();
            $table->string('item_name')->nullable();

            $table->unsignedBigInteger('item_unit_accurate_id');
            $table->string('item_unit_name')->nullable();
            $table->decimal('unit_price', 24, 8)->default(0);

            $table->unsignedBigInteger('purchase_order_accurate_id')->index();
            $table->string('purchase_order_number')->nullable()->index();
            $table->date('purchase_order_date')->nullable()->index();
            $table->unsignedBigInteger('purchase_order_detail_id')->nullable()->index();

            $table->timestamp('source_updated_at')->nullable()->index();
            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['item_accurate_id', 'item_unit_accurate_id'], 'purchase_latest_item_unit_unique');
            $table->index(['purchase_order_date', 'purchase_order_accurate_id'], 'purchase_latest_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_item_latest_prices');
    }
};
