<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_barang', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->foreignId('satuan_id')->constrained('master_satuan');
            $table->enum('jenis', ['bahan', 'setengah_jadi','jadi']);
            $table->decimal('stok', 10, 2)->default(0);
            $table->decimal('stok_minimal', 10, 2)->default(0);
            $table->text('deskripsi')->nullable();
            $table->boolean('status_aktif')->default(true);
            $table->timestamps();
            
            $table->index(['jenis', 'status_aktif']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_barang');
    }
};