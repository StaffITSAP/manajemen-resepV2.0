<?php

namespace App\Services\PurchaseRequisitions;

use App\Models\PurchaseItemCostValue;
use App\Models\PurchaseItemLatestPrice;
use Illuminate\Support\Facades\Schema;

class PurchaseLatestPriceResolver
{
    public function resolve(int $itemAccurateId, int $unitAccurateId): ?LatestPriceResult
    {
        $now = now();
        $cutoff = $now->copy()->subYear();

        $piQuery = PurchaseItemLatestPrice::query()
            ->where('item_accurate_id', $itemAccurateId)
            ->where('item_unit_accurate_id', $unitAccurateId);

        if (Schema::hasColumn('purchase_item_latest_prices', 'source_type')) {
            $piQuery->where('source_type', PurchaseItemLatestPrice::SOURCE_TYPE_PI);
        }

        $pi = $piQuery
            ->whereNotNull('purchase_order_date')
            ->where('purchase_order_date', '>=', $cutoff->toDateString())
            ->where('purchase_order_date', '<=', $now->toDateString())
            ->where('unit_price', '>', 0)
            ->orderByDesc('purchase_order_date')
            ->orderByDesc('purchase_order_accurate_id')
            ->first();

        if ($pi !== null) {
            return LatestPriceResult::fromPurchaseInvoice($pi);
        }

        if (! Schema::hasTable('purchase_item_cost_values')) {
            return LatestPriceResult::zero();
        }

        $costValue = PurchaseItemCostValue::query()
            ->where('item_accurate_id', $itemAccurateId)
            ->where('item_unit_accurate_id', $unitAccurateId)
            ->where('unit_price', '>', 0)
            ->first();

        return $costValue === null ? LatestPriceResult::zero() : LatestPriceResult::fromCostValue($costValue);
    }
}
