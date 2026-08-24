<?php

namespace App\Services\PurchaseRequisitions\Accurate;

use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use Illuminate\Support\Carbon;

class PurchaseRequisitionPayloadBuilder
{
    /**
     * @return array{
     *     transDate:string,
     *     branchId:int,
     *     description:string|null,
     *     requisitionType:string,
     *     saveAsStatusType:string,
     *     detailItem:array<int,array{itemNo:string,requiredDate:string,itemUnitName:string,quantity:float|int|string,unitPrice:int}>
     * }
     */
    public function build(PurchaseRequisition|int $requisition): array
    {
        $record = $requisition instanceof PurchaseRequisition
            ? $requisition
            : PurchaseRequisition::query()->with('items')->find($requisition);

        if (! $record) {
            throw new PurchaseRequisitionPayloadValidationException('Permintaan Barang tidak ditemukan.');
        }

        $record->loadMissing('items');
        $this->validateHeader($record);

        return [
            'transDate' => $this->formatDate($record->trans_date),
            'branchId' => (int) $record->branch_accurate_id,
            'description' => $record->description,
            'requisitionType' => 'PURCHASE',
            'saveAsStatusType' => 'DRAFT',
            'detailItem' => $record->items->values()->map(fn(PurchaseRequisitionItem $item): array => [
                'itemNo' => (string) $this->requireFilled($item->item_no, 'Kode barang detail wajib tersedia.'),
                'requiredDate' => $this->formatDate($this->requireFilled($item->required_date, 'Tanggal diminta detail wajib tersedia.')),
                'itemUnitName' => (string) $this->requireFilled($item->item_unit_name, 'Satuan detail wajib tersedia.'),
                'quantity' => $this->validQuantity($item->quantity),
                'unitPrice' => 0,
            ])->all(),
        ];
    }

    private function validateHeader(PurchaseRequisition $record): void
    {
        if ($record->requisition_type !== 'PURCHASE') {
            throw new PurchaseRequisitionPayloadValidationException('Tipe Permintaan Barang harus PURCHASE.');
        }

        $this->requireFilled($record->branch_accurate_id, 'Remote ID cabang Accurate wajib tersedia.');
        $this->requireFilled($record->trans_date, 'Tanggal transaksi wajib tersedia.');

        if ($record->items->isEmpty()) {
            throw new PurchaseRequisitionPayloadValidationException('Minimal satu detail barang wajib tersedia.');
        }
    }

    private function requireFilled(mixed $value, string $message): mixed
    {
        if (blank($value)) {
            throw new PurchaseRequisitionPayloadValidationException($message);
        }

        return $value;
    }

    private function validQuantity(mixed $quantity): float|int|string
    {
        $this->requireFilled($quantity, 'Quantity detail wajib lebih dari 0.');

        if ((float) $quantity <= 0) {
            throw new PurchaseRequisitionPayloadValidationException('Quantity detail wajib lebih dari 0.');
        }

        return $quantity;
    }

    private function formatDate(mixed $date): string
    {
        return $date instanceof Carbon
            ? $date->format('d/m/Y')
            : Carbon::parse($date)->format('d/m/Y');
    }
}
