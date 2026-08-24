<?php

namespace App\Jobs\PurchaseRequisitions;

use App\Services\Accurate\AccurateItemUnitCacheSyncService;
use App\Services\PurchaseRequisitions\SmartSync\PurchaseRequisitionSmartSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncPurchaseRequisitionItemUnitsBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900;
    public bool $failOnTimeout = true;

    public function __construct(public string $lockOwner, public ?int $afterItemAccurateId = null)
    {
        $this->onConnection(PurchaseRequisitionSmartSync::QUEUE_CONNECTION);
        $this->onQueue(PurchaseRequisitionSmartSync::QUEUE_NAME);
    }

    public function handle(AccurateItemUnitCacheSyncService $service): void
    {
        if (! PurchaseRequisitionSmartSync::ownsLock($this->lockOwner)) {
            return;
        }

        $result = $service->syncSmartMissingStateBatch(
            PurchaseRequisitionSmartSync::BATCH_SIZE,
            PurchaseRequisitionSmartSync::REQUEST_DELAY_MS,
            $this->afterItemAccurateId,
        );

        if ($result['stage_complete'] ?? false) {
            SyncPurchaseRequisitionPurchaseOrdersBatch::dispatch($this->lockOwner, 1)
                ->delay(now()->addSeconds(PurchaseRequisitionSmartSync::INTER_BATCH_DELAY_SECONDS));

            return;
        }

        self::dispatch($this->lockOwner, $result['next_item_accurate_id'] ?? $this->afterItemAccurateId)
            ->delay(now()->addSeconds(PurchaseRequisitionSmartSync::INTER_BATCH_DELAY_SECONDS));
    }

    public function failed(Throwable $exception): void
    {
        PurchaseRequisitionSmartSync::releaseLock($this->lockOwner);
    }
}
