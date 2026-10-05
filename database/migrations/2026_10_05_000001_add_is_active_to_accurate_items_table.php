<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('accurate_items', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->index()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('accurate_items', function (Blueprint $table): void {
            $table->dropIndex(['is_active']);
            $table->dropColumn('is_active');
        });
    }
};
