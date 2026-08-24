<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bahan_produksi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_produksi_id')->constrained('item_produksi')->cascadeOnDelete();
            $table->foreignId('bahan_id')->constrained('master_barang'); // bahan (ingredient)
            $table->decimal('jumlah', 18, 2)->default(0);         // rencana kebutuhan
            $table->decimal('jumlah_aktual', 18, 2)->nullable();  // input aktual
            $table->decimal('selisih', 18, 2)->default(0);        // auto: jumlah - jumlah_aktual
            $table->string('keterangan_aktual', 500)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bahan_produksi');
    }
};
