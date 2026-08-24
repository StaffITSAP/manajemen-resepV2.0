<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('produksi_logs', function (Blueprint $table) {
            $table->id();

            // Penanda subjek log
            $table->unsignedBigInteger('produksi_id')->nullable()->index();
            $table->unsignedBigInteger('item_produksi_id')->nullable()->index();

            // General
            $table->string('model_type');   // App\Models\Produksi / App\Models\ItemProduksi / App\Models\BahanProduksi
            $table->unsignedBigInteger('model_id');
            $table->string('action', 20);   // created, viewed, updated, deleted, restored

            // Siapa
            $table->unsignedBigInteger('user_id')->nullable()->index();

            // Ringkasan & diff lengkap
            $table->string('summary')->nullable();   // ringkas "Ubah: jumlah, keterangan_aktual"
            $table->json('changes_old')->nullable(); // nilai sebelum (full / partial)
            $table->json('changes_new')->nullable(); // nilai sesudah (full / partial)

            $table->timestamps();
            $table->softDeletes();

            $table->index(['model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produksi_logs');
    }
};
