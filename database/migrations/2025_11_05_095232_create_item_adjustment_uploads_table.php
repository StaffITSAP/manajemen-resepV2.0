<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('item_adjustment_uploads', function (Blueprint $table) {
            $table->id();
            $table->string('original_name')->nullable();
            $table->string('path')->nullable();                 // storage path file excel
            $table->date('trans_date')->nullable();             // dari form (fallback jika tidak ada di excel)
            $table->string('description')->nullable();          // dari form (fallback jika tidak ada di excel)
            $table->json('payload')->nullable();                // payload yang dikirim ke Accurate
            $table->json('response')->nullable();               // response Accurate mentah
            $table->string('accurate_number')->nullable();      // nomor dari Accurate (hasil sukses)
            $table->string('accurate_id')->nullable();          // id dari Accurate jika tersedia
            $table->enum('status', ['pending', 'processing', 'success', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_adjustment_uploads');
    }
};
