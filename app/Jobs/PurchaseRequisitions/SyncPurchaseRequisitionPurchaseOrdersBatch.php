<?php

namespace App\Jobs\PurchaseRequisitions;

use App\Services\Accurate\PurchaseInvoiceLatestPriceSyncService;
use App\Services\PurchaseRequisitions\SmartSync\PurchaseRequisitionSmartSync;
use App\Models\PurchaseInvoiceLatestPriceMigrationState;
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
        public array $attemptedPurchaseInvoiceIds = [],
        public string $scanMode = PurchaseInvoiceLatestPriceSyncService::SCAN_MODE_QUICK,
    )
    {
        $this->onConnection(PurchaseRequisitionSmartSync::QUEUE_CONNECTION);
        $this->onQueue(PurchaseRequisitionSmartSync::QUEUE_NAME);
    }

    public function handle(PurchaseInvoiceLatestPriceSyncService $service): void
    {
        if (! PurchaseRequisitionSmartSync::ownsLock($this->lockOwner)) {
            return;
        }

        $migrationState = $service instanceof PurchaseInvoiceLatestPriceSyncService
            ? PurchaseInvoiceLatestPriceMigrationState::query()->where('status', 'running')->whereNull('completed_at')->latest('id')->first()
            : null;
        $incrementalState = $service instanceof PurchaseInvoiceLatestPriceSyncService && ! $migrationState
            ? PurchaseInvoiceLatestPriceMigrationState::query()->whereNotNull('completed_at')->latest('id')->first()
            : null;
        if ($incrementalState && blank($incrementalState->incremental_run_upper_trans_date)) {
            try {
                $boundary = $service->firstPurchaseInvoiceTransDate(100);
            } catch (Throwable $exception) {
                $incrementalState->update([
                    'status' => 'incremental_failed',
                    'incremental_run_upper_trans_date' => null,
                    'error_message' => $exception->getMessage(),
                ]);
                PurchaseRequisitionSmartSync::releaseLock($this->lockOwner);
                return;
            }
            if (! ($boundary['ok'] ?? false)) {
                $incrementalState->update(['status' => 'incremental_failed', 'error_message' => $boundary['message'] ?? 'Invoice incremental boundary capture failed.']);
                PurchaseRequisitionSmartSync::releaseLock($this->lockOwner);
                return;
            }
            if (blank($boundary['trans_date'] ?? null)) {
                $incrementalState->update(['status' => 'completed', 'incremental_page' => 1, 'incremental_row_index' => 0, 'error_message' => null]);
                PurchaseRequisitionSmartSync::releaseLock($this->lockOwner);
                return;
            }
            $incrementalState->update([
                'status' => 'incremental_running',
                'incremental_run_upper_trans_date' => $boundary['trans_date'],
                'incremental_page' => 1,
                'incremental_row_index' => 0,
                'error_message' => null,
            ]);
            $incrementalState = $incrementalState->fresh();
        }
        $syncPage = $migrationState ? $this->page : (int) ($incrementalState?->incremental_page ?? $this->page);
        $startRowIndex = $migrationState?->current_row_index ?? $incrementalState?->incremental_row_index ?? 0;
        $result = $service->syncSmartUnprocessedPurchaseInvoiceBatch(
            $syncPage,
            100,
            PurchaseRequisitionSmartSync::BATCH_SIZE,
            PurchaseRequisitionSmartSync::REQUEST_DELAY_MS,
            $this->attemptedPurchaseInvoiceIds,
            $this->scanMode,
            $migrationState !== null,
            $startRowIndex,
            $incrementalState?->incremental_run_upper_trans_date?->toDateString(),
            $incrementalState?->incremental_completed_upper_trans_date?->toDateString(),
        );

        if (! $migrationState) {
            if ($incrementalState) {
                if (($result['ok'] ?? true) === false) {
                    $incrementalState->update(['status' => 'incremental_failed', 'error_message' => $result['message'] ?? 'Invoice incremental sync failed.']);
                } else {
                    $incrementalComplete = (bool) ($result['stage_complete'] ?? false);
                    $incrementalState->update([
                        'status' => $incrementalComplete ? 'completed' : 'incremental_running',
                        'incremental_page' => $result['page_complete'] ? $syncPage + 1 : $syncPage,
                        'incremental_row_index' => $result['next_row_index'] ?? 0,
                        'incremental_completed_upper_trans_date' => $incrementalComplete ? $incrementalState->incremental_run_upper_trans_date : $incrementalState->incremental_completed_upper_trans_date,
                        'incremental_run_upper_trans_date' => $incrementalComplete ? null : $incrementalState->incremental_run_upper_trans_date,
                        'error_message' => null,
                    ]);
                }
            }
        }
        else { $state = $migrationState;
            $merged = $state->candidates ?? [];
            foreach (($result['candidates'] ?? []) as $candidate) $merged[] = $candidate;
            $state->update(['current_page' => $result['page_complete'] ? $this->page + 1 : $this->page, 'current_row_index' => $result['next_row_index'] ?? 0, 'candidates' => $merged]);
            if (($result['stage_complete'] ?? false) && ($result['ok'] ?? true)) {
                $service->reconcile($merged);
                $state->update(['status' => 'completed', 'completed_at' => now(), 'candidates' => null]);
            } elseif (($result['ok'] ?? true) === false) {
                $state->update(['status' => 'failed', 'error_message' => $result['message'] ?? 'Invoice sync failed.']);
            }
            }
        if (($result['stage_complete'] ?? false) || ($result['ok'] ?? true) === false) {
            PurchaseRequisitionSmartSync::releaseLock($this->lockOwner);
            return;
        }

        $nextPage = (int) ($result['next_page'] ?? $syncPage);

        SyncPurchaseRequisitionPurchaseOrdersBatch::dispatch(
            $this->lockOwner,
            $nextPage,
            $nextPage === $syncPage ? ($result['attempted_purchase_invoice_ids'] ?? []) : [],
            $this->scanMode,
        )->delay(now()->addSeconds(PurchaseRequisitionSmartSync::INTER_BATCH_DELAY_SECONDS));
    }

    public function failed(Throwable $exception): void
    {
        PurchaseRequisitionSmartSync::releaseLock($this->lockOwner);
    }
}
