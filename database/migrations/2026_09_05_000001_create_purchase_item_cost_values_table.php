<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_item_cost_values', function (Blueprint $table): void {
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
            $table->unsignedTinyInteger('unit_position');

            $table->decimal('unit_price', 24, 8);
            $table->decimal('balance_unit_cost', 24, 8)->nullable();
            $table->decimal('ratio', 24, 12)->nullable();
            $table->decimal('balance_total_cost', 24, 8)->nullable();
            $table->string('source_hash')->nullable();
            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['item_accurate_id', 'item_unit_accurate_id'], 'picv_item_unit_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_item_cost_values');
    }
};
