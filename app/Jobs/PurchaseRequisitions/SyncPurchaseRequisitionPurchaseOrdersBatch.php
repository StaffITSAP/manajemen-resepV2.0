<?php

namespace App\Jobs\PurchaseRequisitions;

use App\Services\Accurate\PurchaseOrderLatestPriceSyncService;
use App\Services\PurchaseRequisitions\SmartSync\PurchaseRequisitionSmartSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncPurchaseRequisitionPurchaseOrdersBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900;
    public bool $failOnTimeout = true;

    public function __construct(
        public string $lockOwner,
        public int $page = 1,
        public array $attemptedPurchaseOrderIds = [],
    )
    {
        $this->onConnection(PurchaseRequisitionSmartSync::QUEUE_CONNECTION);
        $this->onQueue(PurchaseRequisitionSmartSync::QUEUE_NAME);
    }

    public function handle(PurchaseOrderLatestPriceSyncService $service): void
    {
        if (! PurchaseRequisitionSmartSync::ownsLock($this->lockOwner)) {
            return;
        }

        $result = $service->syncSmartUnprocessedPurchaseOrderBatch(
            $this->page,
            100,
            PurchaseRequisitionSmartSync::BATCH_SIZE,
            PurchaseRequisitionSmartSync::REQUEST_DELAY_MS,
            $this->attemptedPurchaseOrderIds,
        );

        if (($result['stage_complete'] ?? false) || ($result['ok'] ?? true) === false) {
            PurchaseRequisitionSmartSync::releaseLock($this->lockOwner);
            return;
        }

        $nextPage = (int) ($result['next_page'] ?? $this->page);

        SyncPurchaseRequisitionPurchaseOrdersBatch::dispatch(
            $this->lockOwner,
            $nextPage,
            $nextPage === $this->page ? ($result['attempted_purchase_order_ids'] ?? []) : [],
        )->delay(now()->addSeconds(PurchaseRequisitionSmartSync::INTER_BATCH_DELAY_SECONDS));
    }

    public function failed(Throwable $exception): void
    {
        PurchaseRequisitionSmartSync::releaseLock($this->lockOwner);
    }
}
