<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pr_smart_sync_cv_states', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 20)->default('idle')->index();
            $table->unsignedBigInteger('current_item_accurate_id')->nullable()->index();
            $table->timestamp('initialized_at')->nullable();
            $table->timestamp('last_full_started_at')->nullable();
            $table->timestamp('last_full_completed_at')->nullable();
            $table->unsignedInteger('failures')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pr_smart_sync_cv_states');
    }
};
