<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_requisition_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_requisition_id');
            $table->foreign('purchase_requisition_id', 'pr_activity_logs_pr_id_fk')->references('id')->on('purchase_requisitions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->index();
            $table->text('summary')->nullable();
            $table->json('changes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requisition_activity_logs');
    }
};
