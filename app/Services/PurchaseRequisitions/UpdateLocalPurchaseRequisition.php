<?php

namespace App\Services\PurchaseRequisitions;

use App\Models\AccurateItem;
use App\Models\AccurateItemUnit;
use App\Models\PurchaseRequisition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class UpdateLocalPurchaseRequisition
{
    public function update(PurchaseRequisition $record, array $data, ?int $userId = null): PurchaseRequisition
    {
        return DB::transaction(function () use ($record, $data, $userId): PurchaseRequisition {
            /** @var PurchaseRequisition $locked */
            $locked = PurchaseRequisition::query()
                ->with('items')
                ->whereKey($record->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isPendingApprovalEditable()) {
                throw ValidationException::withMessages([
                    'record' => 'Permintaan Barang sudah diproses oleh approver dan tidak dapat diedit.',
                ]);
            }

            $before = $this->snapshot($locked);
            $items = $data['items'] ?? [];

            if (! is_array($items) || $items === []) {
                throw ValidationException::withMessages([
                    'items' => 'Detail barang wajib diisi.',
                ]);
            }

            $locked->update([
                'trans_date' => $data['trans_date'] ?? $locked->trans_date,
                'description' => $data['description'] ?? null,
                'status' => 'submitted',
                'approved_by' => null,
                'approved_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'accurate_status' => null,
                'accurate_id' => null,
                'accurate_number' => null,
                'payload' => null,
                'response' => null,
                'error_message' => null,
                'synced_at' => null,
            ]);

            $locked->items()->delete();

            foreach (array_values($items) as $index => $itemData) {
                $this->createItem($locked, $itemData, $index);
            }

            $locked = $locked->fresh(['items']) ?? $locked;
            $after = $this->snapshot($locked);
            $summary = $this->summarizeChanges($before, $after);

            $locked->activityLogs()->create([
                'user_id' => $userId,
                'action' => 'Edit Permintaan Barang',
                'summary' => $summary,
                'changes' => [
                    'before' => $before,
                    'after' => $after,
                    'summary' => $summary,
                ],
            ]);

            return $locked->load(['branch', 'items']);
        });
    }

    private function createItem(PurchaseRequisition $requisition, array $data, int $index): void
    {
        $prefix = "items.{$index}";
        $accurateItemId = (int) ($data['accurate_item_id'] ?? 0);
        $unitAccurateId = (int) ($data['item_unit_accurate_id'] ?? 0);
        $quantity = (string) ($data['quantity'] ?? '');

        if ($accurateItemId <= 0) {
            throw ValidationException::withMessages(["{$prefix}.accurate_item_id" => 'Nama barang wajib dipilih.']);
        }

        if ($unitAccurateId <= 0) {
            throw ValidationException::withMessages(["{$prefix}.item_unit_accurate_id" => 'Satuan barang wajib dipilih dari cache lokal.']);
        }

        if (! is_numeric($quantity) || (float) $quantity <= 0) {
            throw ValidationException::withMessages(["{$prefix}.quantity" => 'Kuantitas harus lebih besar dari 0.']);
        }

        $item = AccurateItem::query()->find($accurateItemId);
        if (! $item || (int) $item->accurate_id <= 0) {
            throw ValidationException::withMessages(["{$prefix}.accurate_item_id" => 'Barang Accurate lokal tidak valid.']);
        }

        $unit = AccurateItemUnit::query()
            ->where('item_accurate_id', $item->accurate_id)
            ->where('item_unit_accurate_id', $unitAccurateId)
            ->first();

        if (! $unit) {
            throw ValidationException::withMessages(["{$prefix}.item_unit_accurate_id" => 'Satuan barang belum tersedia di cache lokal.']);
        }

        $latestPrice = app(PurchaseLatestPriceResolver::class)->resolve((int) $item->accurate_id, $unitAccurateId);

        if ($latestPrice === null) {
            throw ValidationException::withMessages(["{$prefix}.latest_purchase_unit_price" => 'Harga pembelian terakhir belum tersedia.']);
        }

        $unitPrice = (string) $latestPrice->price;

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
            'total_price' => $this->multiplyDecimal($quantity, $unitPrice),
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

    private function snapshot(PurchaseRequisition $record): array
    {
        return [
            'id' => $record->id,
            'trans_date' => $record->trans_date?->toDateString(),
            'description' => $record->description,
            'items' => $record->items->map(fn($item): array => [
                'item_no' => $item->item_no,
                'item_name' => $item->item_name,
                'item_unit_accurate_id' => (int) $item->item_unit_accurate_id,
                'item_unit_name' => $item->item_unit_name,
                'quantity' => (string) $item->quantity,
                'required_date' => $item->required_date?->toDateString(),
                'note' => $item->note,
                'latest_purchase_unit_price' => (string) $item->latest_purchase_unit_price,
                'source_purchase_order_number' => $item->source_purchase_order_number,
            ])->values()->all(),
        ];
    }

    private function summarizeChanges(array $before, array $after): string
    {
        $changes = [];

        if (($before['trans_date'] ?? null) !== ($after['trans_date'] ?? null)) {
            $changes[] = "Tanggal: {$before['trans_date']} -> {$after['trans_date']}";
        }

        if (($before['description'] ?? null) !== ($after['description'] ?? null)) {
            $changes[] = "Divisi Outlet: " . (($before['description'] ?? '-') ?: '-') . ' -> ' . (($after['description'] ?? '-') ?: '-');
        }

        $beforeItems = $before['items'] ?? [];
        $afterItems = $after['items'] ?? [];
        $max = max(count($beforeItems), count($afterItems));

        for ($index = 0; $index < $max; $index++) {
            $old = $beforeItems[$index] ?? null;
            $new = $afterItems[$index] ?? null;

            if (! $old && $new) {
                $changes[] = 'Tambah item: ' . $this->itemLabel($new);
                continue;
            }

            if ($old && ! $new) {
                $changes[] = 'Hapus item: ' . $this->itemLabel($old);
                continue;
            }

            if (! $old || ! $new) {
                continue;
            }

            if (($old['item_no'] ?? null) !== ($new['item_no'] ?? null)) {
                $changes[] = 'Ganti item: ' . $this->itemLabel($old) . ' -> ' . $this->itemLabel($new);
            }

            foreach ([
                'quantity' => 'Qty',
                'item_unit_name' => 'Satuan',
                'required_date' => 'Tanggal Diminta',
                'note' => 'Catatan',
            ] as $field => $label) {
                if (($old[$field] ?? null) !== ($new[$field] ?? null)) {
                    $changes[] = "{$label} {$new['item_name']}: " . (($old[$field] ?? '-') ?: '-') . ' -> ' . (($new[$field] ?? '-') ?: '-');
                }
            }
        }

        return $changes === [] ? 'Tidak ada perubahan terdeteksi.' : implode("\n", $changes);
    }

    private function itemLabel(array $item): string
    {
        return trim(($item['item_no'] ?? '') . ' ' . ($item['item_name'] ?? ''));
    }

    private function multiplyDecimal(string $quantity, string $unitPrice): string
    {
        if (function_exists('bcmul')) {
            return bcmul($quantity, $unitPrice, 8);
        }

        return number_format(((float) $quantity) * ((float) $unitPrice), 8, '.', '');
    }
}
