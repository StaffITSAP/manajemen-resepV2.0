<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('item_adjustment_uploads', function (Blueprint $table) {
            $table->string('adjustment_account_no', 50)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('item_adjustment_uploads', function (Blueprint $table) {
            $table->dropColumn('adjustment_account_no');
        });
    }
};
