<?php

use App\Models\PurchaseItemLatestPrice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_item_latest_prices', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_item_latest_prices', 'source_type')) {
                $table->string('source_type', 20)->nullable()->after('purchase_order_detail_id')->index();
            }
        });

        DB::table('purchase_item_latest_prices')
            ->whereNull('source_type')
            ->where('purchase_order_number', 'like', 'PI.%')
            ->update(['source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PI]);

        DB::table('purchase_item_latest_prices')
            ->whereNull('source_type')
            ->where('purchase_order_number', 'like', 'PO.%')
            ->update(['source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PO]);
    }

    public function down(): void
    {
        Schema::table('purchase_item_latest_prices', function (Blueprint $table): void {
            if (Schema::hasColumn('purchase_item_latest_prices', 'source_type')) {
                $table->dropColumn('source_type');
            }
        });
    }
};
