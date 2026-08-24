<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_requisitions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->date('trans_date')->index();
            $table->string('requisition_type')->default('PURCHASE')->index();
            $table->string('description')->nullable();

            $table->foreignId('accurate_branch_id')
                ->nullable()
                ->constrained('accurate_branches')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->unsignedBigInteger('branch_accurate_id')->nullable()->index();
            $table->string('branch_name')->nullable();

            $table->enum('status', ['draft', 'submitted', 'cancelled'])->default('draft')->index();
            $table->enum('sync_status', ['pending', 'processing', 'synced', 'failed'])->default('pending')->index();
            $table->string('accurate_status')->nullable()->index();
            $table->unsignedBigInteger('accurate_id')->nullable()->index();
            $table->string('accurate_number')->nullable()->index();

            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requisitions');
    }
};
