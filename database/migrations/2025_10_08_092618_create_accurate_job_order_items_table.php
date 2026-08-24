<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accurate_job_order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('job_order_id')->constrained('accurate_job_orders')->cascadeOnDelete();
            $t->unsignedBigInteger('accurate_detail_id')->index(); // id detail Accurate (detailItem.id)
            $t->unsignedBigInteger('item_id')->nullable();         // Accurate itemId
            $t->string('item_no')->nullable();
            $t->string('item_name')->nullable();
            $t->string('unit_name')->nullable();                   // ml / grm / dll
            $t->string('warehouse_name')->nullable();
            $t->decimal('quantity', 24, 6)->default(0);
            $t->decimal('amount', 24, 6)->default(0);
            $t->json('raw')->nullable();
            $t->timestamps();

            $t->unique(['job_order_id', 'accurate_detail_id']); // cegah duplikat
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accurate_job_order_items');
    }
};
