<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('produksi_accurate_docs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('produksi_id')
                ->constrained('produksi')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->enum('doc_type', ['JOB_ORDER', 'ROLL_OVER'])->index(); // JO/RO
            $table->string('doc_number')->nullable()->index();
            $table->unsignedBigInteger('external_id')->nullable()->index();
            $table->date('trans_date')->nullable()->index();

            // default 0 supaya tidak NULL lagi
            $table->decimal('total_amount', 20, 6)->default(0);
            $table->decimal('allocation_amount', 20, 6)->default(0);

            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->string('status')->nullable();

            $table->timestamps();

            $table->unique(['produksi_id', 'doc_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produksi_accurate_docs');
    }
};
