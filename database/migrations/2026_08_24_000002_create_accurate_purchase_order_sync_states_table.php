<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accurate_purchase_order_sync_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_order_accurate_id');
            $table->string('purchase_order_number')->nullable();
            $table->date('purchase_order_date')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique('purchase_order_accurate_id', 'aposs_po_acc_unique');
            $table->index('purchase_order_date', 'aposs_po_date_idx');
            $table->index('last_synced_at', 'aposs_last_synced_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accurate_purchase_order_sync_states');
    }
};
