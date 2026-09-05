<?php

namespace App\Services\PurchaseRequisitions;

use App\Models\PurchaseItemCostValue;
use App\Models\PurchaseItemLatestPrice;
use Illuminate\Support\Facades\Schema;

class PurchaseLatestPriceResolver
{
    public function resolve(int $itemAccurateId, int $unitAccurateId): ?LatestPriceResult
    {
        $piQuery = PurchaseItemLatestPrice::query()
            ->where('item_accurate_id', $itemAccurateId)
            ->where('item_unit_accurate_id', $unitAccurateId);

        if (Schema::hasColumn('purchase_item_latest_prices', 'source_type')) {
            $piQuery->where('source_type', PurchaseItemLatestPrice::SOURCE_TYPE_PI);
        }

        $pi = $piQuery->first();

        if ($pi !== null) {
            return LatestPriceResult::fromPurchaseInvoice($pi);
        }

        if (! Schema::hasTable('purchase_item_cost_values')) {
            return null;
        }

        $costValue = PurchaseItemCostValue::query()
            ->where('item_accurate_id', $itemAccurateId)
            ->where('item_unit_accurate_id', $unitAccurateId)
            ->where('unit_price', '>', 0)
            ->first();

        return $costValue === null ? null : LatestPriceResult::fromCostValue($costValue);
    }
}
