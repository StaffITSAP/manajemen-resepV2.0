<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('item_produksi_hasil', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_produksi_id')->constrained('item_produksi')->cascadeOnDelete();
            $table->string('nama_barang');
            $table->string('satuan')->nullable();
            $table->decimal('jumlah_total', 15, 3)->nullable();
            $table->text('keterangan')->nullable(); // contoh: "Base Tahu Bakso Semarang (40 porsi)"
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_produksi_hasil');
    }
};
