<?php

namespace Tests\Feature;

use App\Jobs\PurchaseRequisitions\SyncPurchaseRequisitionPurchaseOrdersBatch;
use App\Models\PurchaseInvoiceLatestPriceMigrationState;
use App\Services\Accurate\AccurateClient;
use App\Services\Accurate\PurchaseInvoiceLatestPriceSyncService;
use App\Services\PurchaseRequisitions\SmartSync\PurchaseRequisitionSmartSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseInvoiceFirstMigrationFailureResumeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('purchase_invoice_latest_price_migration_states');
        Schema::create('purchase_invoice_latest_price_migration_states', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('not_completed')->index();
            $table->string('run_id')->nullable()->unique();
            $table->unsignedInteger('current_page')->default(1);
            $table->unsignedInteger('current_row_index')->default(0);
            $table->unsignedInteger('incremental_page')->default(1);
            $table->unsignedInteger('incremental_row_index')->default(0);
            $table->json('candidates')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Cache::lock(PurchaseRequisitionSmartSync::LOCK_KEY, 1)->forceRelease();
        Cache::forget(PurchaseRequisitionSmartSync::STATUS_KEY);
        Schema::dropIfExists('purchase_invoice_latest_price_migration_states');

        parent::tearDown();
    }

    public function test_failure_in_middle_of_batch_preserves_state_and_retry_cursor(): void
    {
        Queue::fake();
        $state = $this->runningState(3, 50, $this->candidateSet(1, 2));
        $beforeFailure = $state->fresh()->only(['status', 'current_page', 'current_row_index']);
        $candidateCountBefore = count($state->candidates);
        $owner = $this->ownSmartSyncLock();
        $service = new ReconcileSpyPurchaseInvoiceLatestPriceSyncService(
            new FailureResumeFakeAccurateClient(failDetailId: 253),
        );

        (new SyncPurchaseRequisitionPurchaseOrdersBatch($owner, 3))->handle($service);

        $state = $state->fresh();
        $afterFailure = $state->only(['status', 'current_page', 'current_row_index']);

        $this->assertSame(['status' => 'running', 'current_page' => 3, 'current_row_index' => 50], $beforeFailure);
        $this->assertSame('failed', $state->status);
        $this->assertSame(3, $state->current_page);
        $this->assertSame(52, $state->current_row_index);
        $this->assertNotSame('completed', $state->status);
        $this->assertNull($state->completed_at);
        $this->assertSame('Gagal mengambil detail Purchase Invoice.', $state->error_message);
        $this->assertCount($candidateCountBefore + 2, $state->candidates);
        $this->assertSame([251, 252, 253], $service->client->detailIds);
        $this->assertSame(1, $service->client->listCalls);
        $this->assertSame(0, $service->reconcileCalls);
        $this->assertSame(['status' => 'failed', 'current_page' => 3, 'current_row_index' => 52], $afterFailure);
        Queue::assertNothingPushed();
    }

    public function test_retry_from_failed_state_resumes_available_progress_without_resetting(): void
    {
        Queue::fake();
        $failedState = $this->runningState(3, 50, $this->candidateSet(1, 2));
        $owner = $this->ownSmartSyncLock();
        $failingService = new ReconcileSpyPurchaseInvoiceLatestPriceSyncService(
            new FailureResumeFakeAccurateClient(failDetailId: 253),
        );

        (new SyncPurchaseRequisitionPurchaseOrdersBatch($owner, 3))->handle($failingService);

        $afterFailure = $failedState->fresh();
        $candidateCountAfterFailure = count($afterFailure->candidates);
        $retryStart = app(PurchaseRequisitionSmartSync::class)->start();
        $stateAtRetry = $failedState->fresh();
        $retryService = new ReconcileSpyPurchaseInvoiceLatestPriceSyncService(new FailureResumeFakeAccurateClient());

        (new SyncPurchaseRequisitionPurchaseOrdersBatch($retryStart['lock_owner'], $stateAtRetry->current_page))->handle($retryService);

        $afterRetry = $failedState->fresh();

        $this->assertSame('failed', $afterFailure->status);
        $this->assertSame(3, $afterFailure->current_page);
        $this->assertSame(52, $afterFailure->current_row_index);
        $this->assertSame('running', $stateAtRetry->status);
        $this->assertSame(3, $stateAtRetry->current_page);
        $this->assertSame(52, $stateAtRetry->current_row_index);
        $this->assertCount($candidateCountAfterFailure, $stateAtRetry->candidates);
        $this->assertSame(253, $retryService->client->detailIds[0]);
        $this->assertSame(3, $retryService->client->requestedPages[0]);
        $this->assertSame(4, $afterRetry->current_page);
        $this->assertSame(0, $afterRetry->current_row_index);
        $this->assertGreaterThan($candidateCountAfterFailure, count($afterRetry->candidates));
        $this->assertSame(0, $retryService->reconcileCalls);
        Queue::assertPushed(\App\Jobs\PurchaseRequisitions\SyncPurchaseRequisitionItemUnitsBatch::class);
    }

    public function test_candidate_preservation_when_following_batch_fails(): void
    {
        Queue::fake();
        $existingCandidates = $this->candidateSet(1, 2);
        $state = $this->runningState(3, 50, $existingCandidates);
        $owner = $this->ownSmartSyncLock();
        $service = new ReconcileSpyPurchaseInvoiceLatestPriceSyncService(
            new FailureResumeFakeAccurateClient(failDetailId: 253),
        );

        (new SyncPurchaseRequisitionPurchaseOrdersBatch($owner, 3))->handle($service);

        $candidates = $state->fresh()->candidates;

        $this->assertCount(4, $candidates);
        $this->assertSame(1, $candidates[0]['purchase_order_accurate_id']);
        $this->assertSame(2, $candidates[1]['purchase_order_accurate_id']);
        $this->assertSame(251, $candidates[2]['purchase_order_accurate_id']);
        $this->assertSame(252, $candidates[3]['purchase_order_accurate_id']);
        $this->assertSame(0, $service->reconcileCalls);
        Queue::assertNothingPushed();
    }

    private function runningState(int $page, int $rowIndex, array $candidates): PurchaseInvoiceLatestPriceMigrationState
    {
        return PurchaseInvoiceLatestPriceMigrationState::query()->create([
            'status' => 'running',
            'run_id' => 'first-pi-failure-resume',
            'current_page' => $page,
            'current_row_index' => $rowIndex,
            'incremental_page' => 1,
            'incremental_row_index' => 0,
            'candidates' => $candidates,
        ]);
    }

    private function candidateSet(int $from, int $to): array
    {
        return array_map(fn (int $id): array => [
            'item_accurate_id' => 5000 + $id,
            'item_unit_accurate_id' => 51,
            'purchase_order_accurate_id' => $id,
            'purchase_order_date' => '2026-08-20',
            'purchase_order_detail_id' => 10000 + $id,
        ], range($from, $to));
    }

    private function ownSmartSyncLock(): string
    {
        $lock = Cache::lock(PurchaseRequisitionSmartSync::LOCK_KEY, PurchaseRequisitionSmartSync::LOCK_TTL_SECONDS);
        $lock->get();
        $owner = $lock->owner();
        PurchaseRequisitionSmartSync::markRunning($owner);

        return $owner;
    }
}

final class ReconcileSpyPurchaseInvoiceLatestPriceSyncService extends PurchaseInvoiceLatestPriceSyncService
{
    public int $reconcileCalls = 0;

    public function __construct(public FailureResumeFakeAccurateClient $client)
    {
        parent::__construct($client, fn (): null => null);
    }

    public function reconcile(array $dataset): array
    {
        $this->reconcileCalls++;

        return ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'legacy_deleted' => 0];
    }
}

final class FailureResumeFakeAccurateClient extends AccurateClient
{
    public array $detailIds = [];
    public array $requestedPages = [];
    public int $listCalls = 0;

    public function __construct(private ?int $failDetailId = null) {}

    public function listPurchaseInvoices(array $params = []): array
    {
        $page = (int) ($params['sp.page'] ?? 1);
        $this->requestedPages[] = $page;
        $this->listCalls++;
        $start = (($page - 1) * 100) + 1;

        return ['ok' => true, 'body' => ['d' => array_map(
            fn (int $id): array => [
                'id' => $id,
                'number' => "PI.$id",
                'transDate' => '2026-08-20',
                'approvalStatus' => 'APPROVED',
            ],
            range($start, $start + 99),
        )]];
    }

    public function detailPurchaseInvoice(int|string $id): array
    {
        $id = (int) $id;
        $this->detailIds[] = $id;

        if ($this->failDetailId === $id) {
            return ['ok' => false, 'body' => ['message' => 'detail failed']];
        }

        return ['ok' => true, 'body' => ['d' => [
            'id' => $id,
            'number' => "PI.$id",
            'transDate' => '2026-08-20',
            'approvalStatus' => 'APPROVED',
            'detailItem' => [[
                'id' => 10000 + $id,
                'item' => ['id' => 5000 + $id, 'no' => "ITEM-$id", 'name' => "Item $id"],
                'itemUnit' => ['id' => 51, 'name' => 'grm'],
                'unitPrice' => (string) (100 + $id),
            ]],
        ]]];
    }
}
