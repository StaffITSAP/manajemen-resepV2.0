<?php

namespace App\Services\PurchaseRequisitions;

use App\Models\PurchaseRequisition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UpdatePurchaseRequisitionReceiptStatus
{
    public function confirmReceived(PurchaseRequisition $record, User $actor): PurchaseRequisition
    {
        return DB::transaction(function () use ($record, $actor): PurchaseRequisition {
            $locked = PurchaseRequisition::query()->lockForUpdate()->findOrFail($record->getKey());

            if (! $this->canConfirm($locked, $actor)) {
                throw new RuntimeException('Purchase requisition receipt cannot be confirmed.');
            }

            $previousStatus = $locked->receiptStatusOrDefault();
            $receivedAt = now();

            $locked->update([
                'receipt_status' => PurchaseRequisition::RECEIPT_STATUS_RECEIVED,
                'received_at' => $receivedAt,
            ]);

            $locked->activityLogs()->create([
                'user_id' => $actor->id,
                'action' => 'Konfirmasi Penerimaan Barang',
                'summary' => 'Requester mengonfirmasi barang sudah diterima.',
                'changes' => [
                    'previous_receipt_status' => $previousStatus,
                    'new_receipt_status' => PurchaseRequisition::RECEIPT_STATUS_RECEIVED,
                    'actor_id' => $actor->id,
                    'acted_at' => $receivedAt->toDateTimeString(),
                ],
            ]);

            return $locked->fresh(['items', 'activityLogs']) ?? $locked;
        });
    }

    public function revertReceived(PurchaseRequisition $record, User $actor): PurchaseRequisition
    {
        return DB::transaction(function () use ($record, $actor): PurchaseRequisition {
            $locked = PurchaseRequisition::query()->lockForUpdate()->findOrFail($record->getKey());

            if (! $this->canRevert($locked, $actor)) {
                throw new RuntimeException('Purchase requisition receipt cannot be reverted.');
            }

            $previousStatus = $locked->receiptStatusOrDefault();
            $actedAt = now();

            $locked->update([
                'receipt_status' => PurchaseRequisition::RECEIPT_STATUS_PENDING,
                'received_at' => null,
            ]);

            $locked->activityLogs()->create([
                'user_id' => $actor->id,
                'action' => 'Batalkan Penerimaan Barang',
                'summary' => 'Super Admin membatalkan konfirmasi penerimaan barang.',
                'changes' => [
                    'previous_receipt_status' => $previousStatus,
                    'new_receipt_status' => PurchaseRequisition::RECEIPT_STATUS_PENDING,
                    'actor_id' => $actor->id,
                    'acted_at' => $actedAt->toDateTimeString(),
                ],
            ]);

            return $locked->fresh(['items', 'activityLogs']) ?? $locked;
        });
    }

    private function canConfirm(PurchaseRequisition $record, User $actor): bool
    {
        return $actor->can('confirmReceipt', $record)
            && ! $actor->hasRole('superadmin')
            && filled($record->user_id)
            && (int) $record->user_id === (int) $actor->id
            && $record->isReceiptEligible()
            && $record->receiptStatusOrDefault() === PurchaseRequisition::RECEIPT_STATUS_PENDING;
    }

    private function canRevert(PurchaseRequisition $record, User $actor): bool
    {
        return $actor->can('revertReceipt', $record)
            && $actor->hasRole('superadmin')
            && $record->isReceiptEligible()
            && $record->receiptStatusOrDefault() === PurchaseRequisition::RECEIPT_STATUS_RECEIVED;
    }
}
