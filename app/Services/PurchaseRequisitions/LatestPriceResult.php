<?php

namespace App\Services\PurchaseRequisitions;

use App\Models\PurchaseItemCostValue;
use App\Models\PurchaseItemLatestPrice;
use Carbon\CarbonInterface;

class LatestPriceResult
{
    public function __construct(
        public readonly string $price,
        public readonly string $sourceType,
        public readonly ?int $sourceDocumentAccurateId = null,
        public readonly ?string $sourceDocumentNumber = null,
        public readonly CarbonInterface|string|null $sourceDocumentDate = null,
        public readonly CarbonInterface|string|null $sourcePriceSyncedAt = null,
    ) {}

    public static function fromPurchaseInvoice(PurchaseItemLatestPrice $price): self
    {
        return new self(
            price: (string) $price->unit_price,
            sourceType: PurchaseItemLatestPrice::SOURCE_TYPE_PI,
            sourceDocumentAccurateId: $price->purchase_order_accurate_id === null ? null : (int) $price->purchase_order_accurate_id,
            sourceDocumentNumber: $price->purchase_order_number,
            sourceDocumentDate: $price->purchase_order_date,
            sourcePriceSyncedAt: $price->synced_at,
        );
    }

    public static function fromCostValue(PurchaseItemCostValue $costValue): self
    {
        return new self(
            price: (string) $costValue->unit_price,
            sourceType: PurchaseItemLatestPrice::SOURCE_TYPE_COST_VALUE,
            sourcePriceSyncedAt: $costValue->synced_at,
        );
    }
}
