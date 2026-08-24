<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('accurate_job_orders', function (Blueprint $t) {
            $t->unsignedBigInteger('branch_id')->nullable()->after('warehouse_name');
            $t->foreign('branch_id')->references('id')->on('accurate_branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accurate_job_orders', function (Blueprint $t) {
            $t->dropForeign(['branch_id']);
            $t->dropColumn('branch_id');
        });
    }
};
