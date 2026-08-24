<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('resep_logs', function (Blueprint $table) {
            $table->id();

            // Penanda subjek log
            $table->unsignedBigInteger('resep_id')->nullable()->index();
            $table->unsignedBigInteger('bahan_resep_id')->nullable()->index();

            // General
            $table->string('model_type');   // App\Models\Resep / App\Models\BahanResep
            $table->unsignedBigInteger('model_id');
            $table->string('action', 20);   // created, viewed, updated, deleted, restored

            // Siapa
            $table->unsignedBigInteger('user_id')->nullable()->index();

            // Ringkasan & diff lengkap
            $table->string('summary')->nullable();   // ringkas "Ubah: nama, jumlah_barang_setengah_jadi"
            $table->json('changes_old')->nullable(); // nilai sebelum (full / partial)
            $table->json('changes_new')->nullable(); // nilai sesudah (full / partial)

            $table->timestamps();
            $table->softDeletes();

            $table->index(['model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resep_logs');
    }
};
