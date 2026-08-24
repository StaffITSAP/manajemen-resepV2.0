<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accurate_job_orders', function (Blueprint $t) {
            $t->id(); // local id
            $t->unsignedBigInteger('accurate_id')->unique(); // id dari Accurate
            $t->string('number')->index()->nullable();        // JC.2025.xx.xxxxx
            $t->date('trans_date')->nullable();
            $t->string('status')->nullable();                 // FINISHED / etc
            $t->string('status_name')->nullable();            // Selesai / etc
            $t->string('rollover_number')->nullable();        // RO.*
            $t->string('warehouse_name')->nullable();         // jika ada ringkas
            $t->decimal('total_item', 18, 6)->default(0);
            $t->decimal('total_amount', 18, 6)->default(0);
            $t->string('job_account_no')->nullable();
            $t->string('difference_account_no')->nullable();
            $t->json('raw')->nullable();                      // full JSON Accurate (detail.do)
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accurate_job_orders');
    }
};
