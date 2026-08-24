<?php

namespace Tests\Feature;

use App\Jobs\PurchaseRequisitions\SyncPurchaseRequisitionItemUnitsBatch;
use App\Jobs\PurchaseRequisitions\SyncPurchaseRequisitionPurchaseOrdersBatch;
use App\Models\AccurateItem;
use App\Models\AccurateItemUnitSyncState;
use App\Models\AccuratePurchaseOrderSyncState;
use App\Services\Accurate\AccurateClient;
use App\Services\Accurate\AccurateItemUnitCacheSyncService;
use App\Services\Accurate\AccurateItemUnitService;
use App\Services\Accurate\PurchaseOrderLatestPriceSyncService;
use App\Services\PurchaseRequisitions\SmartSync\PurchaseRequisitionSmartSync;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PurchaseRequisitionSmartSyncEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('accurate_purchase_order_sync_states');
        Schema::dropIfExists('purchase_item_latest_prices');
        Schema::dropIfExists('accurate_item_unit_sync_states');
        Schema::dropIfExists('accurate_item_units');
        Schema::dropIfExists('accurate_items');

        Schema::create('accurate_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('accurate_id')->unique();
            $table->string('no')->nullable();
            $table->string('name')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('accurate_item_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accurate_item_id')->nullable()->constrained('accurate_items')->nullOnDelete();
            $table->unsignedBigInteger('item_accurate_id');
            $table->string('item_no')->nullable();
            $table->string('item_name')->nullable();
            $table->unsignedBigInteger('item_unit_accurate_id');
            $table->string('item_unit_name');
            $table->unsignedTinyInteger('position');
            $table->string('source');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['item_accurate_id', 'item_unit_accurate_id'], 'test_aiu_engine_item_unit_unique');
            $table->unique(['item_accurate_id', 'position'], 'test_aiu_engine_position_unique');
        });

        Schema::create('accurate_item_unit_sync_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accurate_item_id')->nullable()->constrained('accurate_items')->nullOnDelete();
            $table->unsignedBigInteger('item_accurate_id')->unique();
            $table->unsignedSmallInteger('unit_count')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_item_latest_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accurate_item_id')->nullable()->constrained('accurate_items')->nullOnDelete();
            $table->unsignedBigInteger('item_accurate_id');
            $table->string('item_no')->nullable();
            $table->string('item_name')->nullable();
            $table->unsignedBigInteger('item_unit_accurate_id');
            $table->string('item_unit_name')->nullable();
            $table->decimal('unit_price', 24, 8)->default(0);
            $table->unsignedBigInteger('purchase_order_accurate_id');
            $table->string('purchase_order_number')->nullable();
            $table->date('purchase_order_date')->nullable();
            $table->unsignedBigInteger('purchase_order_detail_id')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['item_accurate_id', 'item_unit_accurate_id'], 'test_latest_engine_item_unit_unique');
        });

        Schema::create('accurate_purchase_order_sync_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_order_accurate_id')->unique();
            $table->string('purchase_order_number')->nullable();
            $table->date('purchase_order_date')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Cache::lock(PurchaseRequisitionSmartSync::LOCK_KEY, 1)->forceRelease();

        Schema::dropIfExists('accurate_purchase_order_sync_states');
        Schema::dropIfExists('purchase_item_latest_prices');
        Schema::dropIfExists('accurate_item_unit_sync_states');
        Schema::dropIfExists('accurate_item_units');
        Schema::dropIfExists('accurate_items');

        parent::tearDown();
    }

    public function test_start_dispatches_first_job_and_second_start_does_not_duplicate_workflow(): void
    {
        $this->assertInstanceOf(LockProvider::class, Cache::getStore());
        Queue::fake();

        $service = app(PurchaseRequisitionSmartSync::class);
        $first = $service->start();
        $second = $service->start();

        $this->assertSame('started', $first['status']);
        $this->assertSame('already_running', $second['status']);
        Queue::assertPushed(SyncPurchaseRequisitionItemUnitsBatch::class, 1);
        Queue::assertPushed(SyncPurchaseRequisitionItemUnitsBatch::class, function ($job): bool {
            return $this->hasSmartSyncQueueIsolation($job);
        });
    }

    public function test_smart_sync_queue_runtime_configuration_is_dedicated_and_safe(): void
    {
        $itemJob = new SyncPurchaseRequisitionItemUnitsBatch('owner-1');
        $poJob = new SyncPurchaseRequisitionPurchaseOrdersBatch('owner-1');

        $this->assertSame('sync', config('queue.default'));
        $this->assertSame(90, config('queue.connections.database.retry_after'));
        $this->assertSame('database', config('queue.connections.database.driver'));
        $this->assertSame(env('DB_QUEUE_CONNECTION'), config('queue.connections.database.connection'));
        $this->assertSame(env('DB_QUEUE_TABLE', 'jobs'), config('queue.connections.database.table'));
        $this->assertSame(env('DB_QUEUE', 'default'), config('queue.connections.database.queue'));
        $this->assertFalse(config('queue.connections.database.after_commit'));

        $this->assertSame('purchase_requisition_sync', PurchaseRequisitionSmartSync::QUEUE_CONNECTION);
        $this->assertSame('database', config('queue.connections.purchase_requisition_sync.driver'));
        $this->assertSame(env('DB_QUEUE_CONNECTION'), config('queue.connections.purchase_requisition_sync.connection'));
        $this->assertSame(env('DB_QUEUE_TABLE', 'jobs'), config('queue.connections.purchase_requisition_sync.table'));
        $this->assertSame(PurchaseRequisitionSmartSync::QUEUE_NAME, config('queue.connections.purchase_requisition_sync.queue'));
        $this->assertSame(1200, config('queue.connections.purchase_requisition_sync.retry_after'));
        $this->assertFalse(config('queue.connections.purchase_requisition_sync.after_commit'));

        foreach ([$itemJob, $poJob] as $job) {
            $this->assertSame(PurchaseRequisitionSmartSync::QUEUE_CONNECTION, $job->connection);
            $this->assertSame(PurchaseRequisitionSmartSync::QUEUE_NAME, $job->queue);
            $this->assertSame(1, $job->tries);
            $this->assertSame(900, $job->timeout);
            $this->assertTrue($job->failOnTimeout);
            $this->assertGreaterThan($job->timeout, config('queue.connections.purchase_requisition_sync.retry_after'));
        }
    }

    public function test_start_releases_lock_when_initial_dispatch_fails(): void
    {
        $this->assertInstanceOf(LockProvider::class, Cache::getStore());

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('queue unavailable'));

        $this->app->instance(Dispatcher::class, $dispatcher);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('queue unavailable');

        try {
            app(PurchaseRequisitionSmartSync::class)->start();
        } finally {
            $this->assertTrue(Cache::lock(PurchaseRequisitionSmartSync::LOCK_KEY, 1)->get());
        }
    }

    public function test_item_smart_batch_processes_max_fifty_and_does_not_skip_with_shrinking_state_set(): void
    {
        foreach (range(1, 60) as $id) {
            $this->createItem($id);
        }

        $client = new SmartSyncItemClient();
        $service = $this->itemService($client);

        $first = $service->syncSmartMissingStateBatch(50, 0);
        $second = $service->syncSmartMissingStateBatch(50, 0);

        $this->assertSame(range(1, 50), $client->detailCallsBefore(51));
        $this->assertSame(range(51, 60), array_slice($client->detailCalls, 50));
        $this->assertSame(50, $first['items_selected']);
        $this->assertSame(10, $second['items_selected']);
        $this->assertSame(60, AccurateItemUnitSyncState::count());
        $this->assertTrue($second['stage_complete']);
    }

    public function test_zero_unit_success_is_complete_and_failed_item_remains_eligible(): void
    {
        $this->createItem(1);
        $this->createItem(2);

        $client = new SmartSyncItemClient([
            1 => ['ok' => true, 'status' => 200, 'body' => ['s' => true, 'd' => ['unit1' => null]]],
            2 => ['ok' => false, 'status' => 500, 'body' => ['error' => 'HTTP_ERROR']],
        ]);

        $result = $this->itemService($client)->syncSmartMissingStateBatch(50, 0);

        $this->assertSame(1, $result['items_with_no_populated_units']);
        $this->assertDatabaseHas('accurate_item_unit_sync_states', ['item_accurate_id' => 1, 'unit_count' => 0]);
        $this->assertDatabaseMissing('accurate_item_unit_sync_states', ['item_accurate_id' => 2]);
        $this->assertTrue($result['stage_complete']);
        $this->assertSame(2, $result['next_item_accurate_id']);
    }

    public function test_permanently_failing_item_does_not_loop_and_is_eligible_next_workflow(): void
    {
        foreach (range(1, 51) as $id) {
            $this->createItem($id);
        }

        $client = new SmartSyncItemClient([
            1 => ['ok' => false, 'status' => 500, 'body' => ['error' => 'HTTP_ERROR']],
        ]);
        $service = $this->itemService($client);

        $first = $service->syncSmartMissingStateBatch(50, 0);
        $second = $service->syncSmartMissingStateBatch(50, 0, $first['next_item_accurate_id']);

        $this->assertSame(range(1, 50), array_slice($client->detailCalls, 0, 50));
        $this->assertSame([51], array_slice($client->detailCalls, 50));
        $this->assertFalse($first['stage_complete']);
        $this->assertTrue($second['stage_complete']);
        $this->assertDatabaseMissing('accurate_item_unit_sync_states', ['item_accurate_id' => 1]);
        $this->assertDatabaseHas('accurate_item_unit_sync_states', ['item_accurate_id' => 51]);

        $nextWorkflowClient = new SmartSyncItemClient([
            1 => ['ok' => false, 'status' => 500, 'body' => ['error' => 'HTTP_ERROR']],
        ]);

        $nextWorkflow = $this->itemService($nextWorkflowClient)->syncSmartMissingStateBatch(50, 0);

        $this->assertSame([1], $nextWorkflowClient->detailCalls);
        $this->assertTrue($nextWorkflow['stage_complete']);
        $this->assertDatabaseMissing('accurate_item_unit_sync_states', ['item_accurate_id' => 1]);
    }

    public function test_item_job_chains_next_item_batch_or_purchase_order_stage_with_ten_second_delay(): void
    {
        Queue::fake();

        $lock = Cache::lock(PurchaseRequisitionSmartSync::LOCK_KEY, 21600);
        $this->assertTrue($lock->get());
        $owner = $lock->owner();

        $itemService = Mockery::mock(AccurateItemUnitCacheSyncService::class);
        $itemService->shouldReceive('syncSmartMissingStateBatch')
            ->once()
            ->with(50, 500, null)
            ->andReturn(['stage_complete' => false, 'next_item_accurate_id' => 50]);

        (new SyncPurchaseRequisitionItemUnitsBatch($owner))->handle($itemService);

        Queue::assertPushed(SyncPurchaseRequisitionItemUnitsBatch::class, function ($job) use ($owner): bool {
            return $job->lockOwner === $owner
                && $job->afterItemAccurateId === 50
                && $this->hasSmartSyncQueueIsolation($job)
                && $this->hasTenSecondDelay($job);
        });

        Queue::fake();
        $completeService = Mockery::mock(AccurateItemUnitCacheSyncService::class);
        $completeService->shouldReceive('syncSmartMissingStateBatch')
            ->once()
            ->with(50, 500, 50)
            ->andReturn(['stage_complete' => true]);

        (new SyncPurchaseRequisitionItemUnitsBatch($owner, 50))->handle($completeService);

        Queue::assertPushed(SyncPurchaseRequisitionPurchaseOrdersBatch::class, function ($job) use ($owner): bool {
            return $job->lockOwner === $owner
                && $job->page === 1
                && $this->hasSmartSyncQueueIsolation($job)
                && $this->hasTenSecondDelay($job);
        });
    }

    public function test_purchase_order_smart_batch_details_max_fifty_unknown_and_skips_known_processed_ids(): void
    {
        foreach (range(1, 60) as $id) {
            $rows[] = ['id' => $id, 'number' => "PO.{$id}", 'transDate' => '20/08/2026'];
        }

        AccuratePurchaseOrderSyncState::create([
            'purchase_order_accurate_id' => 1,
            'purchase_order_number' => 'PO.1',
            'purchase_order_date' => '2026-08-20',
            'last_synced_at' => now(),
        ]);

        $client = new SmartSyncPurchaseOrderClient($rows ?? []);
        $result = (new PurchaseOrderLatestPriceSyncService($client))->syncSmartUnprocessedPurchaseOrderBatch(1, 100, 50, 0);

        $this->assertSame(range(2, 51), $client->detailCalls);
        $this->assertSame(1, $result['known_skipped']);
        $this->assertSame(50, $result['details_fetched']);
        $this->assertTrue($result['max_details_reached']);
        $this->assertSame(1, $result['next_page']);
    }

    public function test_mixed_purchase_order_page_continues_same_page_until_all_unknown_ids_are_processed(): void
    {
        $rows = [];
        foreach (range(1, 100) as $id) {
            $rows[] = ['id' => $id, 'number' => "PO.{$id}", 'transDate' => '20/08/2026'];
        }

        foreach (range(1, 20) as $id) {
            AccuratePurchaseOrderSyncState::create([
                'purchase_order_accurate_id' => $id,
                'purchase_order_number' => "PO.{$id}",
                'purchase_order_date' => '2026-08-20',
                'last_synced_at' => now(),
            ]);
        }

        $client = new SmartSyncPurchaseOrderClient($rows, [], 1);
        $service = new PurchaseOrderLatestPriceSyncService($client);

        $first = $service->syncSmartUnprocessedPurchaseOrderBatch(1, 100, 50, 0);
        $second = $service->syncSmartUnprocessedPurchaseOrderBatch(1, 100, 50, 0, $first['attempted_purchase_order_ids']);

        $this->assertSame(range(21, 70), array_slice($client->detailCalls, 0, 50));
        $this->assertSame(range(71, 100), array_slice($client->detailCalls, 50));
        $this->assertSame(50, $first['detail_requests']);
        $this->assertSame(30, $second['detail_requests']);
        $this->assertTrue($first['max_details_reached']);
        $this->assertSame(1, $first['next_page']);
        $this->assertTrue($second['stage_complete']);
        $this->assertSame(100, AccuratePurchaseOrderSyncState::count());
    }

    public function test_failing_purchase_order_is_not_retried_within_workflow_and_remains_eligible_next_workflow(): void
    {
        $rows = [];
        foreach (range(1, 100) as $id) {
            $rows[] = ['id' => $id, 'number' => "PO.{$id}", 'transDate' => '20/08/2026'];
        }

        $client = new SmartSyncPurchaseOrderClient($rows, [1], 1);
        $service = new PurchaseOrderLatestPriceSyncService($client);

        $first = $service->syncSmartUnprocessedPurchaseOrderBatch(1, 100, 50, 0);
        $second = $service->syncSmartUnprocessedPurchaseOrderBatch(1, 100, 50, 0, $first['attempted_purchase_order_ids']);

        $this->assertSame(range(1, 50), array_slice($client->detailCalls, 0, 50));
        $this->assertSame(range(51, 100), array_slice($client->detailCalls, 50));
        $this->assertSame(50, $first['detail_requests']);
        $this->assertSame(50, $second['detail_requests']);
        $this->assertSame(1, $second['workflow_attempted_skipped']);
        $this->assertTrue($second['stage_complete']);
        $this->assertDatabaseMissing('accurate_purchase_order_sync_states', ['purchase_order_accurate_id' => 1]);
        $this->assertSame(99, AccuratePurchaseOrderSyncState::count());

        $nextWorkflowClient = new SmartSyncPurchaseOrderClient($rows, [1], 1);
        $nextWorkflow = (new PurchaseOrderLatestPriceSyncService($nextWorkflowClient))
            ->syncSmartUnprocessedPurchaseOrderBatch(1, 100, 50, 0);

        $this->assertSame([1], $nextWorkflowClient->detailCalls);
        $this->assertTrue($nextWorkflow['stage_complete']);
        $this->assertDatabaseMissing('accurate_purchase_order_sync_states', ['purchase_order_accurate_id' => 1]);
    }

    public function test_purchase_order_stage_chains_with_delay_and_final_stage_releases_lock(): void
    {
        Queue::fake();

        $lock = Cache::lock(PurchaseRequisitionSmartSync::LOCK_KEY, 21600);
        $this->assertTrue($lock->get());
        $owner = $lock->owner();

        $poService = Mockery::mock(PurchaseOrderLatestPriceSyncService::class);
        $poService->shouldReceive('syncSmartUnprocessedPurchaseOrderBatch')
            ->once()
            ->with(1, 100, 50, 500, [])
            ->andReturn(['stage_complete' => false, 'next_page' => 1, 'attempted_purchase_order_ids' => [1, 2]]);

        (new SyncPurchaseRequisitionPurchaseOrdersBatch($owner, 1))->handle($poService);

        Queue::assertPushed(SyncPurchaseRequisitionPurchaseOrdersBatch::class, function ($job): bool {
            return $job->page === 1
                && $job->attemptedPurchaseOrderIds === [1, 2]
                && $this->hasSmartSyncQueueIsolation($job)
                && $this->hasTenSecondDelay($job);
        });

        Queue::fake();
        $completeService = Mockery::mock(PurchaseOrderLatestPriceSyncService::class);
        $completeService->shouldReceive('syncSmartUnprocessedPurchaseOrderBatch')
            ->once()
            ->with(2, 100, 50, 500, [])
            ->andReturn(['stage_complete' => true]);

        (new SyncPurchaseRequisitionPurchaseOrdersBatch($owner, 2))->handle($completeService);

        Queue::assertNothingPushed();
        $this->assertTrue(Cache::lock(PurchaseRequisitionSmartSync::LOCK_KEY, 1)->get());
    }

    public function test_stale_jobs_do_not_perform_work_or_release_another_workflow_lock(): void
    {
        Queue::fake();

        $oldLock = Cache::lock(PurchaseRequisitionSmartSync::LOCK_KEY, 21600);
        $this->assertTrue($oldLock->get());
        $oldOwner = $oldLock->owner();
        $oldLock->forceRelease();

        $newLock = Cache::lock(PurchaseRequisitionSmartSync::LOCK_KEY, 21600);
        $this->assertTrue($newLock->get());
        $newOwner = $newLock->owner();

        $itemService = Mockery::mock(AccurateItemUnitCacheSyncService::class);
        $itemService->shouldNotReceive('syncSmartMissingStateBatch');
        (new SyncPurchaseRequisitionItemUnitsBatch($oldOwner))->handle($itemService);

        $poService = Mockery::mock(PurchaseOrderLatestPriceSyncService::class);
        $poService->shouldNotReceive('syncSmartUnprocessedPurchaseOrderBatch');
        (new SyncPurchaseRequisitionPurchaseOrdersBatch($oldOwner, 1))->handle($poService);

        $restoredNewLock = Cache::restoreLock(PurchaseRequisitionSmartSync::LOCK_KEY, $newOwner);
        $this->assertTrue($restoredNewLock->isOwnedByCurrentProcess());
        Queue::assertNothingPushed();
    }

    public function test_failed_jobs_release_workflow_lock(): void
    {
        $lock = Cache::lock(PurchaseRequisitionSmartSync::LOCK_KEY, 21600);
        $this->assertTrue($lock->get());
        $owner = $lock->owner();

        (new SyncPurchaseRequisitionItemUnitsBatch($owner))->failed(new \RuntimeException('boom'));

        $this->assertTrue(Cache::lock(PurchaseRequisitionSmartSync::LOCK_KEY, 1)->get());
    }

    public function test_existing_application_jobs_are_not_assigned_to_smart_sync_queue(): void
    {
        $existingJobPaths = [
            app_path('Jobs/SyncAccurateItemsJob.php'),
            app_path('Jobs/Accurate/FullSyncJobOrders.php'),
        ];

        foreach ($existingJobPaths as $path) {
            $contents = file_get_contents($path);

            $this->assertStringNotContainsString(PurchaseRequisitionSmartSync::QUEUE_NAME, $contents);
            $this->assertStringNotContainsString(PurchaseRequisitionSmartSync::QUEUE_CONNECTION, $contents);
            $this->assertStringNotContainsString('QUEUE_CONNECTION', $contents);
        }
    }

    public function test_smart_sync_code_path_does_not_reference_remote_write_methods(): void
    {
        $paths = [
            app_path('Services/PurchaseRequisitions/SmartSync/PurchaseRequisitionSmartSync.php'),
            app_path('Jobs/PurchaseRequisitions/SyncPurchaseRequisitionItemUnitsBatch.php'),
            app_path('Jobs/PurchaseRequisitions/SyncPurchaseRequisitionPurchaseOrdersBatch.php'),
        ];

        $contents = implode("\n", array_map(fn(string $path): string => file_get_contents($path), $paths));

        $this->assertStringNotContainsString('purchaseRequisitionSaveDraft', $contents);
        $this->assertStringNotContainsString('postJson', $contents);
        $this->assertStringNotContainsString('purchase-requisition/save.do', $contents);
        $this->assertStringNotContainsString('PUT', $contents);
        $this->assertStringNotContainsString('PATCH', $contents);
        $this->assertStringNotContainsString('DELETE', $contents);
    }

    private function itemService(SmartSyncItemClient $client): AccurateItemUnitCacheSyncService
    {
        return new AccurateItemUnitCacheSyncService(
            $client,
            new AccurateItemUnitService(new class extends AccurateClient {
                public function __construct() {}
            }),
        );
    }

    private function createItem(int $accurateId): AccurateItem
    {
        return AccurateItem::create([
            'accurate_id' => $accurateId,
            'no' => "ITEM.{$accurateId}",
            'name' => "Item {$accurateId}",
            'raw' => [],
        ]);
    }

    private function hasTenSecondDelay(object $job): bool
    {
        $delay = $job->delay ?? null;

        if ($delay instanceof \DateTimeInterface) {
            return $delay->getTimestamp() >= now()->addSeconds(9)->getTimestamp();
        }

        return (int) $delay >= 10;
    }

    private function hasSmartSyncQueueIsolation(object $job): bool
    {
        return ($job->connection ?? null) === PurchaseRequisitionSmartSync::QUEUE_CONNECTION
            && ($job->queue ?? null) === PurchaseRequisitionSmartSync::QUEUE_NAME;
    }
}

class SmartSyncItemClient extends AccurateClient
{
    public array $detailCalls = [];

    public function __construct(private array $responses = []) {}

    public function detailItemById(int|string $accurateItemId): array
    {
        $id = (int) $accurateItemId;
        $this->detailCalls[] = $id;

        return $this->responses[$id] ?? [
            'ok' => true,
            'status' => 200,
            'body' => ['s' => true, 'd' => ['unit1' => ['id' => $id + 1000, 'name' => 'pcs']]],
        ];
    }

    public function detailCallsBefore(int $id): array
    {
        return array_values(array_filter($this->detailCalls, fn(int $call): bool => $call < $id));
    }

    public function postJson(string $path, array $body = [], array $query = []): array
    {
        throw new \RuntimeException('Smart Sync item stage must not call remote writes.');
    }
}

class SmartSyncPurchaseOrderClient extends AccurateClient
{
    public array $detailCalls = [];

    public function __construct(
        private array $rows,
        private array $failingIds = [],
        private int $pageCount = 2,
    ) {}

    public function listPurchaseOrders(array $params = []): array
    {
        return [
            'ok' => true,
            'status' => 200,
            'body' => ['s' => true, 'd' => $this->rows, 'sp' => ['page' => $params['sp.page'] ?? 1, 'pageCount' => $this->pageCount]],
        ];
    }

    public function detailPurchaseOrder(int|string $id): array
    {
        $id = (int) $id;
        $this->detailCalls[] = $id;

        if (in_array($id, $this->failingIds, true)) {
            return ['ok' => false, 'status' => 500, 'body' => ['error' => 'HTTP_ERROR']];
        }

        return [
            'ok' => true,
            'status' => 200,
            'body' => [
                's' => true,
                'd' => [
                    'id' => $id,
                    'number' => "PO.{$id}",
                    'transDate' => '20/08/2026',
                    'detailItem' => [],
                ],
            ],
        ];
    }

    public function postJson(string $path, array $body = [], array $query = []): array
    {
        throw new \RuntimeException('Smart Sync PO stage must not call remote writes.');
    }
}
