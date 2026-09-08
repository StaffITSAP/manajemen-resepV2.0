<?php

namespace Tests\Feature;

use App\Jobs\PurchaseRequisitions\SyncPurchaseRequisitionItemUnitsBatch;
use App\Jobs\PurchaseRequisitions\SyncPurchaseRequisitionPurchaseOrdersBatch;
use App\Models\AccurateItem;
use App\Models\AccurateItemUnit;
use App\Models\PurchaseInvoiceLatestPriceMigrationState;
use App\Models\PurchaseItemCostValue;
use App\Models\PurchaseItemLatestPrice;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionSmartSyncCostValueState;
use App\Services\Accurate\AccurateClient;
use App\Services\Accurate\AccurateItemUnitCacheSyncService;
use App\Services\Accurate\AccurateItemUnitService;
use App\Services\PurchaseRequisitions\PurchaseLatestPriceResolver;
use App\Services\PurchaseRequisitions\SmartSync\PurchaseRequisitionSmartSync;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseRequisitionSmartSyncCostValueStageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-07 10:00:00');

        foreach ([
            'purchase_requisition_items',
            'purchase_requisitions',
            'pr_smart_sync_cv_states',
            'purchase_invoice_latest_price_migration_states',
            'purchase_item_cost_values',
            'purchase_item_latest_prices',
            'accurate_item_unit_sync_states',
            'accurate_item_units',
            'accurate_items',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('accurate_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('accurate_id')->unique();
            $table->string('no')->nullable();
            $table->string('name')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('accurate_item_units', function (Blueprint $table): void {
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
            $table->unique(['item_accurate_id', 'item_unit_accurate_id'], 'test_cv_stage_item_unit_unique');
            $table->unique(['item_accurate_id', 'position'], 'test_cv_stage_position_unique');
        });

        Schema::create('accurate_item_unit_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('accurate_item_id')->nullable()->constrained('accurate_items')->nullOnDelete();
            $table->unsignedBigInteger('item_accurate_id')->unique();
            $table->unsignedSmallInteger('unit_count')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_item_latest_prices', function (Blueprint $table): void {
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
            $table->string('source_type', 20)->nullable()->index();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['item_accurate_id', 'item_unit_accurate_id'], 'test_cv_stage_latest_unique');
        });

        Schema::create('purchase_item_cost_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('accurate_item_id')->nullable()->constrained('accurate_items')->nullOnDelete();
            $table->unsignedBigInteger('item_accurate_id');
            $table->string('item_no')->nullable();
            $table->string('item_name')->nullable();
            $table->unsignedBigInteger('item_unit_accurate_id');
            $table->string('item_unit_name')->nullable();
            $table->unsignedTinyInteger('unit_position');
            $table->decimal('unit_price', 24, 8);
            $table->decimal('balance_unit_cost', 24, 8)->nullable();
            $table->decimal('ratio', 24, 12)->nullable();
            $table->decimal('balance_total_cost', 24, 8)->nullable();
            $table->string('source_hash')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['item_accurate_id', 'item_unit_accurate_id'], 'test_cv_stage_cost_unique');
        });

        Schema::create('purchase_invoice_latest_price_migration_states', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('not_completed')->index();
            $table->string('run_id')->nullable()->unique();
            $table->unsignedInteger('current_page')->default(1);
            $table->unsignedInteger('current_row_index')->default(0);
            $table->unsignedInteger('incremental_page')->default(1);
            $table->unsignedInteger('incremental_row_index')->default(0);
            $table->date('incremental_run_upper_trans_date')->nullable();
            $table->date('incremental_completed_upper_trans_date')->nullable();
            $table->json('candidates')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pr_smart_sync_cv_states', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 20)->default('idle')->index();
            $table->unsignedBigInteger('current_item_accurate_id')->nullable()->index();
            $table->timestamp('initialized_at')->nullable();
            $table->timestamp('last_full_started_at')->nullable();
            $table->timestamp('last_full_completed_at')->nullable();
            $table->unsignedInteger('failures')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_requisitions', function (Blueprint $table): void {
            $table->id();
            $table->date('trans_date');
            $table->string('status')->default('submitted');
            $table->string('sync_status')->default('pending');
            $table->timestamps();
        });

        Schema::create('purchase_requisition_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_requisition_id')->constrained('purchase_requisitions')->cascadeOnDelete();
            $table->unsignedBigInteger('item_accurate_id');
            $table->string('item_no');
            $table->string('item_name');
            $table->unsignedBigInteger('item_unit_accurate_id');
            $table->string('item_unit_name');
            $table->decimal('quantity', 24, 6)->default(0);
            $table->date('required_date');
            $table->decimal('latest_purchase_unit_price', 24, 8)->default(0);
            $table->decimal('total_price', 24, 8)->default(0);
            $table->string('latest_price_source_type', 20)->nullable();
            $table->unsignedBigInteger('source_document_accurate_id')->nullable();
            $table->string('source_document_number')->nullable();
            $table->date('source_document_date')->nullable();
            $table->timestamp('source_price_synced_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::lock(PurchaseRequisitionSmartSync::LOCK_KEY, 1)->forceRelease();
        Cache::forget(PurchaseRequisitionSmartSync::STATUS_KEY);

        foreach ([
            'purchase_requisition_items',
            'purchase_requisitions',
            'pr_smart_sync_cv_states',
            'purchase_invoice_latest_price_migration_states',
            'purchase_item_cost_values',
            'purchase_item_latest_prices',
            'accurate_item_unit_sync_states',
            'accurate_item_units',
            'accurate_items',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_one_button_start_path_remains_and_no_separate_full_cv_workflow_exists(): void
    {
        Queue::fake();

        $result = app(PurchaseRequisitionSmartSync::class)->start();

        $this->assertSame('started', $result['status']);
        Queue::assertPushed(SyncPurchaseRequisitionItemUnitsBatch::class, 1);
        $this->assertFalse(class_exists('App\\Models\\' . 'CostValueFullRefreshState'));
        $this->assertFalse(Schema::hasTable('cost_value_' . 'full_refresh_states'));
        $this->assertStringNotContainsString('accurate:refresh-' . 'cost-values', $this->consoleCommandFiles());
    }

    public function test_configured_detail_sleep_is_passed_to_full_cost_value_stage_without_changing_batch_size(): void
    {
        Queue::fake();
        config([
            'accurate.purchase_requisition_smart_sync_detail_sleep_ms' => 250,
            'accurate.purchase_requisition_smart_sync_inter_batch_delay_seconds' => 2,
        ]);
        $this->item(790);
        $lockOwner = $this->ownedSmartSyncLock();
        $service = new ConfigurableSleepCvStageFakeService();

        (new SyncPurchaseRequisitionItemUnitsBatch($lockOwner))->handle($service);

        $this->assertSame([50, 250, null], $service->lastFullArgs);
        Queue::assertPushed(SyncPurchaseRequisitionPurchaseOrdersBatch::class, function ($job): bool {
            $delay = $job->delay ?? null;

            return $delay instanceof \DateTimeInterface
                && $delay->getTimestamp() >= now()->addSecond()->getTimestamp();
        });
    }

    public function test_first_run_full_initializes_cv_without_using_unit_state_as_completion(): void
    {
        Queue::fake();
        $item = $this->item(790);
        $this->unitState($item);

        $lockOwner = $this->ownedSmartSyncLock();
        $client = new SmartSyncCvClient([
            790 => $this->detail(790, 10, ['unit1' => ['id' => 50, 'name' => 'pcs']]),
        ]);

        (new SyncPurchaseRequisitionItemUnitsBatch($lockOwner))->handle($this->itemService($client));

        $this->assertSame([790], $client->detailCalls);
        $this->assertDatabaseHas('purchase_item_cost_values', [
            'item_accurate_id' => 790,
            'item_unit_accurate_id' => 50,
            'unit_price' => '10.00000000',
        ]);
        $this->assertNotNull(PurchaseRequisitionSmartSyncCostValueState::first()?->initialized_at);
        $this->assertNotNull(PurchaseRequisitionSmartSyncCostValueState::first()?->last_full_completed_at);
        Queue::assertPushed(SyncPurchaseRequisitionPurchaseOrdersBatch::class);
    }

    public function test_not_due_run_skips_full_cv_and_still_continues_to_pi(): void
    {
        Queue::fake();
        config(['accurate.purchase_requisition_cost_value_full_refresh_hours' => 24]);
        $this->completedCvState(now()->subHours(23));
        $this->item(790);
        $lockOwner = $this->ownedSmartSyncLock();
        $client = new SmartSyncCvClient();

        (new SyncPurchaseRequisitionItemUnitsBatch($lockOwner))->handle($this->itemService($client));

        $this->assertSame([], $client->detailCalls);
        Queue::assertPushed(SyncPurchaseRequisitionPurchaseOrdersBatch::class);
    }

    public function test_due_run_revisits_synced_items_updates_cursor_and_completes(): void
    {
        Queue::fake();
        config(['accurate.purchase_requisition_cost_value_full_refresh_hours' => 24]);
        $this->completedCvState(now()->subHours(24));
        foreach ([790, 791] as $id) {
            $item = $this->item($id);
            $this->unitState($item);
        }

        $lockOwner = $this->ownedSmartSyncLock();
        $client = new SmartSyncCvClient([
            790 => $this->detail(790, 11, ['unit1' => ['id' => 50, 'name' => 'pcs']]),
            791 => $this->detail(791, 12, ['unit1' => ['id' => 51, 'name' => 'pcs']]),
        ]);

        (new SyncPurchaseRequisitionItemUnitsBatch($lockOwner))->handle($this->itemService($client));

        $this->assertSame([790, 791], $client->detailCalls);
        $state = PurchaseRequisitionSmartSyncCostValueState::firstOrFail();
        $this->assertSame('completed', $state->status);
        $this->assertNull($state->current_item_accurate_id);
        $this->assertNotNull($state->last_full_completed_at);
    }

    public function test_full_cv_cursor_progresses_and_resumes_by_accurate_id(): void
    {
        Queue::fake();
        config(['accurate.purchase_requisition_cost_value_full_refresh_hours' => 24]);
        $this->completedCvState(now()->subHours(24));
        foreach (range(1, 60) as $id) {
            $this->item($id);
        }

        $lockOwner = $this->ownedSmartSyncLock();
        $client = new SmartSyncCvClient();
        (new SyncPurchaseRequisitionItemUnitsBatch($lockOwner))->handle($this->itemService($client));

        $this->assertSame(range(1, 50), $client->detailCalls);
        $this->assertSame(50, PurchaseRequisitionSmartSyncCostValueState::firstOrFail()->current_item_accurate_id);

        $client->detailCalls = [];
        (new SyncPurchaseRequisitionItemUnitsBatch($lockOwner, 50, 'full'))->handle($this->itemService($client));

        $this->assertSame(range(51, 60), $client->detailCalls);
        $this->assertSame('completed', PurchaseRequisitionSmartSyncCostValueState::firstOrFail()->status);
    }

    public function test_cv_refresh_updates_removes_preserves_and_recalculates_without_touching_pi_or_pr_snapshots(): void
    {
        Queue::fake();
        $item = $this->item(790);
        $other = $this->item(791);
        $this->completedCvState(now()->subHours(24));
        $this->costValue($item, 50, '1.00000000');
        $this->costValue($item, 51, '2.00000000', 2);
        $this->costValue($other, 52, '3.00000000');
        $pi = $this->pi($item, 50, '99.00000000');
        $snapshot = $this->purchaseRequisitionSnapshot();
        $lockOwner = $this->ownedSmartSyncLock();
        $client = new SmartSyncCvClient([
            790 => $this->detail(790, 5, ['unit1' => ['id' => 50, 'name' => 'pcs'], 'unit2' => ['id' => 53, 'name' => 'box'], 'ratio2' => 3]),
            791 => ['ok' => false, 'status' => 500, 'body' => ['error' => 'HTTP_ERROR']],
        ]);

        (new SyncPurchaseRequisitionItemUnitsBatch($lockOwner))->handle($this->itemService($client));

        $this->assertDatabaseHas('purchase_item_cost_values', ['item_accurate_id' => 790, 'item_unit_accurate_id' => 50, 'unit_price' => '5.00000000']);
        $this->assertDatabaseHas('purchase_item_cost_values', ['item_accurate_id' => 790, 'item_unit_accurate_id' => 53, 'unit_price' => '15.00000000']);
        $this->assertDatabaseMissing('purchase_item_cost_values', ['item_accurate_id' => 790, 'item_unit_accurate_id' => 51]);
        $this->assertDatabaseHas('purchase_item_cost_values', ['item_accurate_id' => 791, 'item_unit_accurate_id' => 52, 'unit_price' => '3.00000000']);
        $this->assertSame($pi->unit_price, $pi->fresh()->unit_price);
        $this->assertSame('123.00000000', $snapshot->fresh()->latest_purchase_unit_price);
        $this->assertSame(PurchaseItemLatestPrice::SOURCE_TYPE_PI, app(PurchaseLatestPriceResolver::class)->resolve(790, 50)?->sourceType);
        $this->assertSame(PurchaseItemLatestPrice::SOURCE_TYPE_COST_VALUE, app(PurchaseLatestPriceResolver::class)->resolve(790, 53)?->sourceType);
    }

    public function test_unavailable_and_malformed_detail_handling(): void
    {
        Queue::fake();
        $itemA = $this->item(790);
        $itemB = $this->item(791);
        $this->completedCvState(now()->subHours(24));
        $this->costValue($itemA, 50, '1.00000000');
        $this->costValue($itemB, 51, '2.00000000');
        $lockOwner = $this->ownedSmartSyncLock();
        $client = new SmartSyncCvClient([
            790 => $this->detail(790, 0, ['unit1' => ['id' => 50, 'name' => 'pcs']]),
            791 => ['ok' => true, 'status' => 200, 'body' => ['s' => true, 'd' => ['balanceUnitCost' => 5]]],
        ]);

        (new SyncPurchaseRequisitionItemUnitsBatch($lockOwner))->handle($this->itemService($client));

        $this->assertDatabaseMissing('purchase_item_cost_values', ['item_accurate_id' => 790]);
        $this->assertDatabaseHas('purchase_item_cost_values', ['item_accurate_id' => 791, 'item_unit_accurate_id' => 51]);
        $this->assertSame(1, PurchaseRequisitionSmartSyncCostValueState::firstOrFail()->failures);
    }

    public function test_invalid_cost_value_numeric_input_fails_preserves_rows_and_continues(): void
    {
        Queue::fake();
        Log::spy();
        $itemA = $this->item(790);
        $itemB = $this->item(791);
        $this->completedCvState(now()->subHours(24));
        $this->costValue($itemA, 50, '1.00000000');
        $lockOwner = $this->ownedSmartSyncLock();
        $client = new SmartSyncCvClient([
            790 => $this->detail(790, 10, [
                'unit1' => ['id' => 50, 'name' => 'pcs'],
                'unit2' => ['id' => 51, 'name' => 'box'],
                'ratio2' => '5,3',
            ]),
            791 => $this->detail(791, 12, ['unit1' => ['id' => 52, 'name' => 'pcs']]),
        ]);

        (new SyncPurchaseRequisitionItemUnitsBatch($lockOwner))->handle($this->itemService($client));

        $this->assertSame([790, 791], $client->detailCalls);
        $this->assertDatabaseHas('purchase_item_cost_values', [
            'item_accurate_id' => 790,
            'item_unit_accurate_id' => 50,
            'unit_price' => '1.00000000',
        ]);
        $this->assertDatabaseHas('purchase_item_cost_values', [
            'item_accurate_id' => 791,
            'item_unit_accurate_id' => 52,
            'unit_price' => '12.00000000',
        ]);
        $state = PurchaseRequisitionSmartSyncCostValueState::firstOrFail();
        $this->assertSame('completed', $state->status);
        $this->assertSame(1, $state->failures);
        $this->assertSame('Completed with 1 item failure(s). See application log for item details.', $state->last_error);
        Log::shouldHaveReceived('warning')
            ->with('[AccurateItemUnitCache] cost value refresh failed', \Mockery::on(
                fn(array $context): bool => $context['accurate_item_id'] === 790
                    && $context['item_no'] === '790'
                    && $context['item_name'] === 'Item 790'
                    && $context['message'] === 'Payload detail item memiliki nilai ratio2 yang tidak valid.'
            ))
            ->once();
    }

    public function test_full_cv_completion_with_zero_failures_clears_last_error(): void
    {
        Queue::fake();
        $this->completedCvState(now()->subHours(24))->update([
            'last_error' => 'old warning',
        ]);
        $this->item(790);
        $lockOwner = $this->ownedSmartSyncLock();
        $client = new SmartSyncCvClient([
            790 => $this->detail(790, 12, ['unit1' => ['id' => 52, 'name' => 'pcs']]),
        ]);

        (new SyncPurchaseRequisitionItemUnitsBatch($lockOwner))->handle($this->itemService($client));

        $state = PurchaseRequisitionSmartSyncCostValueState::firstOrFail();
        $this->assertSame('completed', $state->status);
        $this->assertSame(0, $state->failures);
        $this->assertNull($state->last_error);
    }

    public function test_pi_state_is_not_reset_and_duplicate_smart_sync_remains_blocked(): void
    {
        Queue::fake();
        PurchaseInvoiceLatestPriceMigrationState::create([
            'status' => 'completed',
            'run_id' => 'pi-run',
            'current_page' => 9,
            'incremental_page' => 4,
            'incremental_row_index' => 2,
            'completed_at' => now()->subDay(),
        ]);

        $first = app(PurchaseRequisitionSmartSync::class)->start();
        $second = app(PurchaseRequisitionSmartSync::class)->start();

        $this->assertSame('started', $first['status']);
        $this->assertSame('already_running', $second['status']);
        $state = PurchaseInvoiceLatestPriceMigrationState::firstOrFail();
        $this->assertSame(9, $state->current_page);
        $this->assertSame(4, $state->incremental_page);
        $this->assertSame(2, $state->incremental_row_index);
    }

    public function test_failed_job_marks_running_cv_state_failed_and_releases_lock(): void
    {
        $lockOwner = $this->ownedSmartSyncLock();
        PurchaseRequisitionSmartSyncCostValueState::create([
            'status' => 'running',
            'current_item_accurate_id' => 50,
            'failures' => 0,
        ]);

        (new SyncPurchaseRequisitionItemUnitsBatch($lockOwner, 50, 'full'))->failed(new \RuntimeException('timeout'));

        $state = PurchaseRequisitionSmartSyncCostValueState::firstOrFail();
        $this->assertSame('failed', $state->status);
        $this->assertSame('timeout', $state->last_error);
        $this->assertTrue(Cache::lock(PurchaseRequisitionSmartSync::LOCK_KEY, 1)->get());
    }

    public function test_refresh_interval_default_and_configured_decision(): void
    {
        $this->assertSame(24, config('accurate.purchase_requisition_cost_value_full_refresh_hours'));

        Queue::fake();
        config(['accurate.purchase_requisition_cost_value_full_refresh_hours' => 6]);
        $this->completedCvState(now()->subHours(6));
        $this->item(790);
        $lockOwner = $this->ownedSmartSyncLock();
        $client = new SmartSyncCvClient();

        (new SyncPurchaseRequisitionItemUnitsBatch($lockOwner))->handle($this->itemService($client));

        $this->assertSame([790], $client->detailCalls);
    }

    private function itemService(SmartSyncCvClient $client): AccurateItemUnitCacheSyncService
    {
        return new AccurateItemUnitCacheSyncService(
            $client,
            new AccurateItemUnitService(new class extends AccurateClient {
                public function __construct() {}
            }),
            fn(int $sleepMs): null => null,
        );
    }

    private function ownedSmartSyncLock(): string
    {
        $lock = Cache::lock(PurchaseRequisitionSmartSync::LOCK_KEY, 21600);
        $this->assertTrue($lock->get());
        PurchaseRequisitionSmartSync::markRunning($lock->owner());

        return $lock->owner();
    }

    private function completedCvState(Carbon $completedAt): PurchaseRequisitionSmartSyncCostValueState
    {
        return PurchaseRequisitionSmartSyncCostValueState::create([
            'status' => 'completed',
            'initialized_at' => $completedAt,
            'last_full_completed_at' => $completedAt,
            'failures' => 0,
        ]);
    }

    private function item(int $accurateId): AccurateItem
    {
        return AccurateItem::create([
            'accurate_id' => $accurateId,
            'no' => (string) $accurateId,
            'name' => 'Item ' . $accurateId,
            'raw' => [],
        ]);
    }

    private function unitState(AccurateItem $item): void
    {
        $item->setTable('accurate_items');
        \App\Models\AccurateItemUnitSyncState::create([
            'accurate_item_id' => $item->id,
            'item_accurate_id' => (int) $item->accurate_id,
            'unit_count' => 1,
            'last_synced_at' => now()->subDay(),
        ]);
    }

    private function costValue(AccurateItem $item, int $unitId, string $price, int $position = 1): PurchaseItemCostValue
    {
        return PurchaseItemCostValue::create([
            'accurate_item_id' => $item->id,
            'item_accurate_id' => (int) $item->accurate_id,
            'item_no' => $item->no,
            'item_name' => $item->name,
            'item_unit_accurate_id' => $unitId,
            'item_unit_name' => 'pcs',
            'unit_position' => $position,
            'unit_price' => $price,
            'balance_unit_cost' => $price,
            'synced_at' => now()->subDay(),
        ]);
    }

    private function pi(AccurateItem $item, int $unitId, string $price): PurchaseItemLatestPrice
    {
        return PurchaseItemLatestPrice::create([
            'accurate_item_id' => $item->id,
            'item_accurate_id' => (int) $item->accurate_id,
            'item_no' => $item->no,
            'item_name' => $item->name,
            'item_unit_accurate_id' => $unitId,
            'item_unit_name' => 'pcs',
            'unit_price' => $price,
            'purchase_order_accurate_id' => 900,
            'purchase_order_number' => 'PI.900',
            'purchase_order_date' => '2026-09-01',
            'purchase_order_detail_id' => 901,
            'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PI,
            'synced_at' => now(),
        ]);
    }

    private function purchaseRequisitionSnapshot()
    {
        $record = PurchaseRequisition::create([
            'trans_date' => '2026-09-07',
            'status' => 'submitted',
            'sync_status' => 'pending',
        ]);

        return $record->items()->create([
            'item_accurate_id' => 790,
            'item_no' => '790',
            'item_name' => 'Item 790',
            'item_unit_accurate_id' => 50,
            'item_unit_name' => 'pcs',
            'quantity' => '1.000000',
            'required_date' => '2026-09-08',
            'latest_purchase_unit_price' => '123.00000000',
            'total_price' => '123.00000000',
            'latest_price_source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_COST_VALUE,
        ]);
    }

    private function detail(int $id, int|float|string $balanceUnitCost, array $detail): array
    {
        return [
            'ok' => true,
            'status' => 200,
            'body' => [
                's' => true,
                'd' => $detail + [
                    'id' => $id,
                    'no' => (string) $id,
                    'name' => 'Item ' . $id,
                    'balanceUnitCost' => $balanceUnitCost,
                ],
            ],
        ];
    }

    private function consoleCommandFiles(): string
    {
        return implode("\n", array_map(
            fn(string $path): string => file_get_contents($path),
            glob(app_path('Console/Commands/*.php')),
        ));
    }
}

class SmartSyncCvClient extends AccurateClient
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
            'body' => ['s' => true, 'd' => [
                'id' => $id,
                'no' => (string) $id,
                'name' => 'Item ' . $id,
                'unit1' => ['id' => $id + 1000, 'name' => 'pcs'],
                'balanceUnitCost' => 1,
            ]],
        ];
    }

    public function postJson(string $path, array $body = [], array $query = []): array
    {
        throw new \RuntimeException('Remote write must not be called by Smart Sync CV tests.');
    }
}

class ConfigurableSleepCvStageFakeService extends AccurateItemUnitCacheSyncService
{
    public array $lastFullArgs = [];

    public function __construct() {}

    public function syncSmartFullCostValueBatch(int $limit = 50, int $sleepMs = 500, ?int $afterAccurateId = null): array
    {
        $this->lastFullArgs = [$limit, $sleepMs, $afterAccurateId];

        return ['stage_complete' => true];
    }
}
