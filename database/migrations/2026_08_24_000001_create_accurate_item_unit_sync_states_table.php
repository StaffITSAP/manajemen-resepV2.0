<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accurate_item_unit_sync_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('accurate_item_id')->nullable();
            $table->unsignedBigInteger('item_accurate_id');
            $table->unsignedSmallInteger('unit_count')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique('item_accurate_id', 'aiuss_item_acc_unique');
            $table->index('last_synced_at', 'aiuss_last_synced_idx');
            $table->foreign('accurate_item_id', 'aiuss_ai_fk')
                ->references('id')
                ->on('accurate_items')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accurate_item_unit_sync_states');
    }
};
