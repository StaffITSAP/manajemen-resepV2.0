<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->enum('receipt_status', ['pending', 'received'])
                ->default('pending')
                ->after('rejected_at')
                ->index();
            $table->timestamp('received_at')
                ->nullable()
                ->after('receipt_status')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->dropIndex(['receipt_status']);
            $table->dropIndex(['received_at']);
            $table->dropColumn(['receipt_status', 'received_at']);
        });
    }
};
