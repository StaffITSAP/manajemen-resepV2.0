<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('synced_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by')->index();
            $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by')->index();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->dropIndex(['approved_at']);
            $table->dropIndex(['rejected_at']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn('rejected_at');
        });
    }
};
