<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accurate_item_units', function (Blueprint $table) {
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
            $table->string('item_unit_name');
            $table->unsignedTinyInteger('position');
            $table->string('source');

            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['item_accurate_id', 'item_unit_accurate_id'], 'aiu_item_unit_unique');
            $table->unique(['item_accurate_id', 'position'], 'aiu_item_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accurate_item_units');
    }
};
