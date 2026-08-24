<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bahan_resep', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resep_id')->constrained('resep')->onDelete('cascade');
            $table->foreignId('bahan_id')->constrained('master_barang');
            $table->decimal('jumlah', 10, 2);
            $table->text('catatan')->nullable();
            $table->timestamps();
            
            $table->unique(['resep_id', 'bahan_id']);
            $table->index(['bahan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bahan_resep');
    }
};