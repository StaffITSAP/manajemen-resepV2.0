<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produksi', function (Blueprint $table) {
            $table->id();
            $table->string('nomor_produksi')->unique();
            $table->date('tanggal');
            $table->enum('status', ['draft', 'diproses', 'selesai', 'batal'])->default('draft');
            $table->text('catatan')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();
            
            $table->index(['tanggal', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produksi');
    }
};