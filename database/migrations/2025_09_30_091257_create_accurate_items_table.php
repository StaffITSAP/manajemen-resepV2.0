<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accurate_items', function (Blueprint $t) {
            $t->id(); // local id
            $t->unsignedBigInteger('accurate_id')->index(); // id dari Accurate
            $t->string('no')->nullable()->index();
            $t->string('name')->nullable()->index();
            $t->json('raw')->nullable(); // simpan payload full bila perlu
            $t->timestamps();

            $t->unique('accurate_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accurate_items');
    }
};
