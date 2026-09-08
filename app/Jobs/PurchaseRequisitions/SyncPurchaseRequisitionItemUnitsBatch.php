<?php

namespace App\Jobs\PurchaseRequisitions;

use App\Models\PurchaseRequisitionSmartSyncCostValueState;
use App\Services\Accurate\AccurateItemUnitCacheSyncService;
use App\Services\PurchaseRequisitions\SmartSync\PurchaseRequisitionSmartSync;
use App\Models\PurchaseInvoiceLatestPriceMigrationState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SyncPurchaseRequisitionItemUnitsBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900;
    public bool $failOnTimeout = true;

    public function __construct(
        public string $lockOwner,
        public ?int $afterItemAccurateId = null,
        public string $mode = 'auto',
    )
    {
        $this->onConnection(PurchaseRequisitionSmartSync::QUEUE_CONNECTION);
        $this->onQueue(PurchaseRequisitionSmartSync::QUEUE_NAME);
    }

    public function handle(AccurateItemUnitCacheSyncService $service): void
    {
        if (! PurchaseRequisitionSmartSync::ownsLock($this->lockOwner)) {
            return;
        }

        $mode = $this->mode === 'auto' ? $this->resolveMode() : $this->mode;

        if ($mode === 'skip') {
            $this->dispatchPurchaseInvoiceStage();
            return;
        }

        if ($mode === 'full') {
            $state = $this->costValueState();
            $startingNewSweep = $state->current_item_accurate_id === null && $state->status !== 'running';
            $stateUpdate = [
                'status' => 'running',
                'last_full_started_at' => $state->last_full_started_at ?? now(),
                'last_error' => null,
            ];
            if ($startingNewSweep) {
                $stateUpdate['failures'] = 0;
            }
            $state->update($stateUpdate);
            $state = $state->fresh();

            $result = $service->syncSmartFullCostValueBatch(
                PurchaseRequisitionSmartSync::BATCH_SIZE,
                PurchaseRequisitionSmartSync::detailSleepMs(),
                $state->current_item_accurate_id,
            );

            $state->update([
                'current_item_accurate_id' => $result['next_item_accurate_id'] ?? $state->current_item_accurate_id,
                'failures' => $state->failures + (int) ($result['failures'] ?? 0),
                'last_error' => $result['message'] ?? null,
            ]);
            $state = $state->fresh();

            if ($result['stage_complete'] ?? false) {
                $state->update([
                    'status' => 'completed',
                    'current_item_accurate_id' => null,
                    'initialized_at' => $state->initialized_at ?? now(),
                    'last_full_completed_at' => now(),
                    'last_full_started_at' => null,
                    'last_error' => $this->completedLastError((int) $state->failures),
                ]);
                $this->dispatchPurchaseInvoiceStage();
                return;
            }

            self::dispatch($this->lockOwner, $result['next_item_accurate_id'] ?? $state->current_item_accurate_id, 'full')
                ->delay(now()->addSeconds(PurchaseRequisitionSmartSync::interBatchDelaySeconds()));

            return;
        }

        $result = $service->syncSmartMissingStateBatch(
            PurchaseRequisitionSmartSync::BATCH_SIZE,
            PurchaseRequisitionSmartSync::detailSleepMs(),
            $this->afterItemAccurateId,
        );

        if ($result['stage_complete'] ?? false) {
            $this->dispatchPurchaseInvoiceStage();
            return;
        }

        self::dispatch($this->lockOwner, $result['next_item_accurate_id'] ?? $this->afterItemAccurateId, 'missing')
            ->delay(now()->addSeconds(PurchaseRequisitionSmartSync::interBatchDelaySeconds()));
    }

    public function failed(Throwable $exception): void
    {
        if (Schema::hasTable('pr_smart_sync_cv_states')) {
            PurchaseRequisitionSmartSyncCostValueState::query()
                ->where('status', 'running')
                ->update([
                    'status' => 'failed',
                    'last_error' => $exception->getMessage(),
                ]);
        }

        PurchaseRequisitionSmartSync::releaseLock($this->lockOwner);
    }

    private function resolveMode(): string
    {
        if (! Schema::hasTable('pr_smart_sync_cv_states')) {
            return 'missing';
        }

        $state = $this->costValueState();
        if ($state->initialized_at === null || $state->status === 'running') {
            return 'full';
        }

        $lastCompleted = $state->last_full_completed_at;
        if ($lastCompleted === null) {
            return 'full';
        }

        $hours = max(1, (int) config('accurate.purchase_requisition_cost_value_full_refresh_hours', 24));

        return $lastCompleted->lte(now()->subHours($hours)) ? 'full' : 'skip';
    }

    private function costValueState(): PurchaseRequisitionSmartSyncCostValueState
    {
        return PurchaseRequisitionSmartSyncCostValueState::query()->firstOrCreate(
            ['id' => 1],
            [
                'status' => 'idle',
                'current_item_accurate_id' => null,
                'failures' => 0,
                'last_error' => null,
            ],
        );
    }

    private function dispatchPurchaseInvoiceStage(): void
    {
        $state = PurchaseInvoiceLatestPriceMigrationState::query()->latest('id')->first();
        $page = $state && blank($state->completed_at) ? (int) $state->current_page : (int) ($state->incremental_page ?? 1);
        SyncPurchaseRequisitionPurchaseOrdersBatch::dispatch($this->lockOwner, max(1, $page))
            ->delay(now()->addSeconds(PurchaseRequisitionSmartSync::interBatchDelaySeconds()));
    }

    private function completedLastError(int $failures): ?string
    {
        return $failures > 0
            ? "Completed with {$failures} item failure(s). See application log for item details."
            : null;
    }
}
