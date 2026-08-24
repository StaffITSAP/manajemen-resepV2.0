<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accurate_branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('accurate_id')->unique(); // id dari Accurate
            $t->string('name')->nullable();
            $t->string('description')->nullable();
            $t->string('location_code')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accurate_branches');
    }
};
