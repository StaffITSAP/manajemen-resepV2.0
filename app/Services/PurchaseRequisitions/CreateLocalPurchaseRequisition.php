<?php

namespace App\Services\PurchaseRequisitions;

use App\Models\AccurateBranch;
use App\Models\AccurateItem;
use App\Models\AccurateItemUnit;
use App\Models\PurchaseItemLatestPrice;
use App\Models\PurchaseRequisition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CreateLocalPurchaseRequisition
{
    public function create(array $data, ?int $userId = null): PurchaseRequisition
    {
        return DB::transaction(function () use ($data, $userId) {
            $branch = $this->resolveHeadOfficeBranch();
            $items = $data['items'] ?? [];

            if (! is_array($items) || $items === []) {
                throw ValidationException::withMessages([
                    'items' => 'Detail barang wajib diisi.',
                ]);
            }

            $requisition = PurchaseRequisition::create([
                'user_id' => $userId,
                'creator_name' => $userId ? User::query()->whereKey($userId)->value('name') : null,
                'trans_date' => $data['trans_date'] ?? now()->toDateString(),
                'requisition_type' => 'PURCHASE',
                'description' => $data['description'] ?? null,
                'accurate_branch_id' => $branch->id,
                'branch_accurate_id' => $branch->accurate_id,
                'branch_name' => $branch->name,
                'status' => 'submitted',
                'sync_status' => 'pending',
                'accurate_status' => null,
                'accurate_id' => null,
                'accurate_number' => null,
                'payload' => null,
                'response' => null,
                'error_message' => null,
                'synced_at' => null,
            ]);

            foreach (array_values($items) as $index => $itemData) {
                $this->createItem($requisition, $itemData, $index);
            }

            return $requisition->load(['branch', 'items']);
        });
    }

    public function resolveHeadOfficeBranch(): AccurateBranch
    {
        $branch = AccurateBranch::query()
            ->where('name', 'Kantor Pusat')
            ->first();

        if (! $branch) {
            throw ValidationException::withMessages([
                'accurate_branch_id' => 'Cabang Kantor Pusat belum tersedia di cache lokal.',
            ]);
        }

        return $branch;
    }

    public function latestPriceFor(int $itemAccurateId, int $unitAccurateId): ?PurchaseItemLatestPrice
    {
        $resolved = app(PurchaseLatestPriceResolver::class)->resolve($itemAccurateId, $unitAccurateId);

        return $resolved?->sourceType === PurchaseItemLatestPrice::SOURCE_TYPE_PI
            ? PurchaseItemLatestPrice::query()
                ->where('item_accurate_id', $itemAccurateId)
                ->where('item_unit_accurate_id', $unitAccurateId)
                ->where('source_type', PurchaseItemLatestPrice::SOURCE_TYPE_PI)
                ->first()
            : null;
    }

    private function createItem(PurchaseRequisition $requisition, array $data, int $index): void
    {
        $prefix = "items.{$index}";
        $accurateItemId = (int) ($data['accurate_item_id'] ?? 0);
        $unitAccurateId = (int) ($data['item_unit_accurate_id'] ?? 0);
        $quantity = (string) ($data['quantity'] ?? '');

        if ($accurateItemId <= 0) {
            throw ValidationException::withMessages([
                "{$prefix}.accurate_item_id" => 'Nama barang wajib dipilih.',
            ]);
        }

        if ($unitAccurateId <= 0) {
            throw ValidationException::withMessages([
                "{$prefix}.item_unit_accurate_id" => 'Satuan barang wajib dipilih dari cache lokal.',
            ]);
        }

        if (! is_numeric($quantity) || (float) $quantity <= 0) {
            throw ValidationException::withMessages([
                "{$prefix}.quantity" => 'Kuantitas harus lebih besar dari 0.',
            ]);
        }

        $item = AccurateItem::query()->find($accurateItemId);
        if (! $item || (int) $item->accurate_id <= 0) {
            throw ValidationException::withMessages([
                "{$prefix}.accurate_item_id" => 'Barang Accurate lokal tidak valid.',
            ]);
        }

        $unit = AccurateItemUnit::query()
            ->where('item_accurate_id', $item->accurate_id)
            ->where('item_unit_accurate_id', $unitAccurateId)
            ->first();

        if (! $unit) {
            throw ValidationException::withMessages([
                "{$prefix}.item_unit_accurate_id" => 'Satuan barang belum tersedia di cache lokal.',
            ]);
        }

        $latestPrice = app(PurchaseLatestPriceResolver::class)->resolve((int) $item->accurate_id, $unitAccurateId);
        if ($latestPrice === null) {
            throw ValidationException::withMessages([
                "{$prefix}.latest_purchase_unit_price" => 'Harga pembelian terakhir belum tersedia.',
            ]);
        }

        $unitPrice = (string) $latestPrice->price;
        $totalPrice = $this->multiplyDecimal($quantity, $unitPrice);

        $snapshot = [
            'accurate_item_id' => $item->id,
            'item_accurate_id' => $item->accurate_id,
            'item_no' => $item->no,
            'item_name' => $item->name,
            'item_unit_accurate_id' => $unit->item_unit_accurate_id,
            'item_unit_name' => $unit->item_unit_name,
            'quantity' => $quantity,
            'required_date' => $data['required_date'] ?? null,
            'note' => $data['note'] ?? null,
            'latest_purchase_unit_price' => $unitPrice,
            'total_price' => $totalPrice,
            'source_purchase_order_accurate_id' => $latestPrice->sourceDocumentAccurateId,
            'source_purchase_order_number' => $latestPrice->sourceDocumentNumber,
            'source_purchase_order_date' => $latestPrice->sourceDocumentDate,
        ];

        foreach ([
            'latest_price_source_type' => $latestPrice->sourceType,
            'source_document_accurate_id' => $latestPrice->sourceDocumentAccurateId,
            'source_document_number' => $latestPrice->sourceDocumentNumber,
            'source_document_date' => $latestPrice->sourceDocumentDate,
            'source_price_synced_at' => $latestPrice->sourcePriceSyncedAt,
        ] as $column => $value) {
            if (Schema::hasColumn('purchase_requisition_items', $column)) {
                $snapshot[$column] = $value;
            }
        }

        $requisition->items()->create($snapshot);
    }

    private function multiplyDecimal(string $quantity, string $unitPrice): string
    {
        if (function_exists('bcmul')) {
            return bcmul($quantity, $unitPrice, 8);
        }

        return number_format(((float) $quantity) * ((float) $unitPrice), 8, '.', '');
    }
}
