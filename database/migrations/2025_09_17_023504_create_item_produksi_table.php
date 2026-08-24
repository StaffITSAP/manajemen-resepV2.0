<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_produksi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produksi_id')->constrained('produksi')->onDelete('cascade');
            $table->foreignId('barang_setengah_jadi_id')->constrained('master_barang');
            $table->integer('jumlah');
            $table->timestamps();
            
            $table->index(['produksi_id', 'barang_setengah_jadi_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_produksi');
    }
};