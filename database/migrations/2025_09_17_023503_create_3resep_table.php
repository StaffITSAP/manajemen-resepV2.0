<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resep', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->foreignId('barang_setengah_jadi_id')->constrained('master_barang');
            $table->integer('jumlah_barang_setengah_jadi');
            $table->text('deskripsi')->nullable();
            $table->text('cara_pembuatan')->nullable();
            $table->boolean('status_aktif')->default(true);
            $table->timestamps();
            
            $table->index(['barang_setengah_jadi_id', 'status_aktif']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resep');
    }
};