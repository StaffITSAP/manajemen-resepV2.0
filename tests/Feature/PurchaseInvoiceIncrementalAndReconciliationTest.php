<?php

namespace Tests\Feature;

use App\Jobs\PurchaseRequisitions\SyncPurchaseRequisitionItemUnitsBatch;
use App\Jobs\PurchaseRequisitions\SyncPurchaseRequisitionPurchaseOrdersBatch;
use App\Models\PurchaseInvoiceLatestPriceMigrationState;
use App\Models\PurchaseItemCostValue;
use App\Models\PurchaseItemLatestPrice;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Services\Accurate\AccurateClient;
use App\Services\Accurate\PurchaseInvoiceLatestPriceSyncService;
use App\Services\Accurate\PurchaseOrderLatestPriceSyncService;
use App\Services\PurchaseRequisitions\SmartSync\PurchaseRequisitionSmartSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseInvoiceIncrementalAndReconciliationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();
        Schema::create('accurate_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('accurate_id')->unique();
            $table->string('no')->nullable();
            $table->string('name')->nullable();
            $table->json('raw')->nullable();
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
            $table->unique(['item_accurate_id', 'item_unit_accurate_id'], 'test_latest_item_unit_unique');
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
        Schema::create('purchase_requisitions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('creator_name')->nullable();
            $table->date('trans_date');
            $table->string('requisition_type')->default('PURCHASE');
            $table->string('description')->nullable();
            $table->unsignedBigInteger('accurate_branch_id')->nullable();
            $table->unsignedBigInteger('branch_accurate_id')->nullable();
            $table->string('branch_name')->nullable();
            $table->string('status')->default('draft');
            $table->string('sync_status')->default('pending');
            $table->timestamps();
        });
        Schema::create('purchase_requisition_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_requisition_id')->constrained('purchase_requisitions')->cascadeOnDelete();
            $table->unsignedBigInteger('accurate_item_id')->nullable();
            $table->unsignedBigInteger('item_accurate_id');
            $table->string('item_no');
            $table->string('item_name');
            $table->unsignedBigInteger('item_unit_accurate_id');
            $table->string('item_unit_name');
            $table->decimal('quantity', 24, 6)->default(0);
            $table->date('required_date');
            $table->decimal('latest_purchase_unit_price', 24, 8)->default(0);
            $table->decimal('total_price', 24, 8)->default(0);
            $table->unsignedBigInteger('source_purchase_order_accurate_id')->nullable();
            $table->string('source_purchase_order_number')->nullable();
            $table->date('source_purchase_order_date')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Cache::lock(PurchaseRequisitionSmartSync::LOCK_KEY, 1)->forceRelease();
        Cache::forget(PurchaseRequisitionSmartSync::STATUS_KEY);
        $this->dropTables();

        parent::tearDown();
    }

    public function test_incremental_start_preserves_existing_cursor(): void
    {
        Queue::fake();
        $state = $this->completedState(3, 50, null, '2026-08-20');

        $result = app(PurchaseRequisitionSmartSync::class)->start();
        $state = $state->fresh();

        $this->assertSame('started', $result['status']);
        $this->assertSame('incremental_running', $state->status);
        $this->assertSame(3, $state->incremental_page);
        $this->assertSame(50, $state->incremental_row_index);
        $this->assertSame(7, $state->current_page);
        $this->assertSame(0, $state->current_row_index);
        Queue::assertPushed(SyncPurchaseRequisitionItemUnitsBatch::class);
    }

    public function test_incremental_failure_records_failure_without_advancing_cursor(): void
    {
        Queue::fake();
        $state = $this->completedState(3, 50, '2026-08-20', '2026-08-19');
        $owner = $this->ownSmartSyncLock();
        $service = new PurchaseInvoiceLatestPriceSyncService(new IncrementalInvoiceFakeClient(failDetailId: 253), fn (): null => null);

        (new SyncPurchaseRequisitionPurchaseOrdersBatch($owner, 3))->handle($service);

        $state = $state->fresh();

        $this->assertSame('incremental_failed', $state->status);
        $this->assertSame(3, $state->incremental_page);
        $this->assertSame(50, $state->incremental_row_index);
        $this->assertSame('2026-08-20', $state->incremental_run_upper_trans_date->toDateString());
        $this->assertSame('Gagal mengambil detail Purchase Invoice.', $state->error_message);
        $this->assertDatabaseHas('purchase_item_latest_prices', ['purchase_order_accurate_id' => 251]);
        $this->assertDatabaseHas('purchase_item_latest_prices', ['purchase_order_accurate_id' => 252]);
        Queue::assertNothingPushed();
    }

    public function test_incremental_retry_resumes_from_saved_checkpoint_and_advances_after_success(): void
    {
        Queue::fake();
        $state = $this->completedState(3, 50, '2026-08-20', '2026-08-19');
        $owner = $this->ownSmartSyncLock();
        (new SyncPurchaseRequisitionPurchaseOrdersBatch($owner, 3))->handle(
            new PurchaseInvoiceLatestPriceSyncService(new IncrementalInvoiceFakeClient(failDetailId: 253), fn (): null => null),
        );

        $retryStart = app(PurchaseRequisitionSmartSync::class)->start();
        $stateAtRetry = $state->fresh();
        $retryClient = new IncrementalInvoiceFakeClient();

        (new SyncPurchaseRequisitionPurchaseOrdersBatch($retryStart['lock_owner'], $stateAtRetry->incremental_page))->handle(
            new PurchaseInvoiceLatestPriceSyncService($retryClient, fn (): null => null),
        );

        $state = $state->fresh();

        $this->assertSame('incremental_running', $stateAtRetry->status);
        $this->assertSame(3, $stateAtRetry->incremental_page);
        $this->assertSame(50, $stateAtRetry->incremental_row_index);
        $this->assertSame([251, 252, 253], array_slice($retryClient->detailIds, 0, 3));
        $this->assertSame('incremental_running', $state->status);
        $this->assertSame(4, $state->incremental_page);
        $this->assertSame(0, $state->incremental_row_index);
        $this->assertSame('2026-08-20', $state->incremental_run_upper_trans_date->toDateString());
        $this->assertNull($state->error_message);
    }

    public function test_boundary_capture_exception_marks_incremental_failed_and_releases_lock(): void
    {
        Queue::fake();
        $state = $this->completedState(3, 50, null, '2026-08-20');
        $owner = $this->ownSmartSyncLock();

        (new SyncPurchaseRequisitionPurchaseOrdersBatch($owner, 3))->handle(
            new PurchaseInvoiceLatestPriceSyncService(new ThrowingBoundaryInvoiceFakeClient(), fn (): null => null),
        );

        $state = $state->fresh();

        $this->assertSame('incremental_failed', $state->status);
        $this->assertNull($state->incremental_run_upper_trans_date);
        $this->assertSame(3, $state->incremental_page);
        $this->assertSame(50, $state->incremental_row_index);
        $this->assertSame('Boundary capture exploded.', $state->error_message);
        $this->assertFalse(PurchaseRequisitionSmartSync::ownsLock($owner));

        $retry = app(PurchaseRequisitionSmartSync::class)->start();

        $this->assertSame('started', $retry['status']);
        $this->assertSame('incremental_running', $state->fresh()->status);
    }

    public function test_partial_incremental_batch_keeps_running_status_active_boundary_and_lock(): void
    {
        Queue::fake();
        $state = $this->completedState(1, 0, '2026-09-04', '2026-08-19');
        $owner = $this->ownSmartSyncLock();

        (new SyncPurchaseRequisitionPurchaseOrdersBatch($owner, 1))->handle(
            new PurchaseInvoiceLatestPriceSyncService(new IncrementalInvoiceFakeClient(), fn (): null => null),
        );

        $state = $state->fresh();

        $this->assertSame('incremental_running', $state->status);
        $this->assertSame('2026-09-04', $state->incremental_run_upper_trans_date->toDateString());
        $this->assertSame('2026-08-19', $state->incremental_completed_upper_trans_date->toDateString());
        $this->assertSame(1, $state->incremental_page);
        $this->assertSame(50, $state->incremental_row_index);
        $this->assertTrue(PurchaseRequisitionSmartSync::ownsLock($owner));
        Queue::assertPushed(SyncPurchaseRequisitionPurchaseOrdersBatch::class);
    }

    public function test_malformed_row_advances_cursor_without_fetching_detail(): void
    {
        $client = new MalformedRowInvoiceFakeClient();
        $service = new PurchaseInvoiceLatestPriceSyncService($client, fn (): null => null);

        $result = $service->syncSmartUnprocessedPurchaseInvoiceBatch(1, 100, 2, 0, [], 'quick', true, 0);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['skipped_malformed']);
        $this->assertSame([1, 3], $client->detailIds);
        $this->assertSame(3, $result['rows_consumed']);
        $this->assertSame(3, $result['next_row_index']);
        $this->assertFalse($result['page_complete']);
    }

    public function test_pi_parser_handles_slash_dates_as_day_month_year_and_preserves_iso_dates(): void
    {
        $service = new PurchaseInvoiceLatestPriceSyncService(new BoundaryInvoiceFakeClient([]), fn (): null => null);

        $this->assertSame('2026-08-12', $service->parsePiDate('12/08/2026')?->toDateString());
        $this->assertSame('2026-08-13', $service->parsePiDate('13/08/2026')?->toDateString());
        $this->assertSame('2026-08-31', $service->parsePiDate('31/08/2026')?->toDateString());
        $this->assertSame('2026-12-08', $service->parsePiDate('08/12/2026')?->toDateString());
        $this->assertSame('2026-08-20', $service->parsePiDate('2026-08-20')?->toDateString());
    }

    public function test_candidate_purchase_order_date_uses_canonical_pi_parser_for_detail_date(): void
    {
        $client = new BoundaryInvoiceFakeClient([
            1 => [['id' => 1208, 'transDate' => '11/08/2026']],
        ], [
            1208 => ['itemId' => 7208, 'unitId' => 51, 'price' => '1208.00000000', 'date' => '12/08/2026'],
        ]);

        $result = (new PurchaseInvoiceLatestPriceSyncService($client, fn (): null => null))
            ->syncSmartUnprocessedPurchaseInvoiceBatch(1, 100, 50, 0, [], 'quick', false, 0, '12/08/2026', null);

        $this->assertTrue($result['ok']);
        $price = PurchaseItemLatestPrice::query()
            ->where('item_accurate_id', 7208)
            ->where('purchase_order_accurate_id', 1208)
            ->where('source_type', PurchaseItemLatestPrice::SOURCE_TYPE_PI)
            ->firstOrFail();

        $this->assertSame('2026-08-12', $price->purchase_order_date->toDateString());
    }

    public function test_incremental_list_row_comparison_uses_canonical_pi_parser(): void
    {
        Queue::fake();
        $state = $this->completedState(1, 0, '2026-08-12', '2026-08-11');
        $owner = $this->ownSmartSyncLock();
        $client = new BoundaryInvoiceFakeClient([
            1 => [
                ['id' => 1308, 'transDate' => '13/08/2026'],
                ['id' => 1208, 'transDate' => '12/08/2026'],
                ['id' => 1108, 'transDate' => '11/08/2026'],
                ['id' => 1008, 'transDate' => '10/08/2026'],
            ],
        ]);

        (new SyncPurchaseRequisitionPurchaseOrdersBatch($owner, 1))->handle(
            new PurchaseInvoiceLatestPriceSyncService($client, fn (): null => null),
        );

        $state = $state->fresh();

        $this->assertSame([1208, 1108], $client->detailIds);
        $this->assertSame('completed', $state->status);
        $this->assertSame('2026-08-12', $state->incremental_completed_upper_trans_date->toDateString());
        $this->assertNull($state->incremental_run_upper_trans_date);
    }

    public function test_first_migration_reconciliation_deletes_only_stale_pi_cache_and_leaves_non_pi_rows_cost_values_and_pr_snapshots(): void
    {
        $requisition = PurchaseRequisition::query()->create([
            'trans_date' => '2026-08-20',
            'status' => 'submitted',
            'sync_status' => 'pending',
        ]);
        PurchaseRequisitionItem::query()->create([
            'purchase_requisition_id' => $requisition->id,
            'item_accurate_id' => 9999,
            'item_no' => 'OLD',
            'item_name' => 'Old Snapshot',
            'item_unit_accurate_id' => 51,
            'item_unit_name' => 'grm',
            'quantity' => '2',
            'required_date' => '2026-08-21',
            'latest_purchase_unit_price' => '123.00000000',
            'total_price' => '246.00000000',
            'source_purchase_order_accurate_id' => 700,
            'source_purchase_order_number' => 'PO.OLD',
            'source_purchase_order_date' => '2026-08-01',
        ]);
        $this->latestPrice(501, 51, 9001, 'PI.STALE', '2026-08-01', '100.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PI);
        $this->latestPrice(502, 51, 9002, 'WILL-BE-PI', '2026-08-01', '100.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PI);
        $this->latestPrice(503, 51, 9003, 'PO.9003', '2026-08-01', '100.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PO);
        $this->latestPrice(504, 51, 9004, 'LEGACY.9004');
        $this->latestPrice(505, 51, 9005, 'CV.9005', '2026-08-01', '100.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_COST_VALUE);
        $this->costValue(801, 51, '88.00000000');
        $service = new PurchaseInvoiceLatestPriceSyncService(new IncrementalInvoiceFakeClient(), fn (): null => null);

        $result = $service->reconcile([[
            'item_accurate_id' => 502,
            'item_no' => 'ITEM-502',
            'item_name' => 'Item 502',
            'item_unit_accurate_id' => 51,
            'item_unit_name' => 'grm',
            'unit_price' => '777.00000000',
            'purchase_order_accurate_id' => 3002,
            'purchase_order_number' => 'PI.3002',
            'purchase_order_date' => '2026-08-25',
            'purchase_order_detail_id' => 13002,
            'source_updated_at' => null,
            'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PI,
        ]]);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['legacy_deleted']);
        $this->assertDatabaseMissing('purchase_item_latest_prices', ['item_accurate_id' => 501, 'item_unit_accurate_id' => 51]);
        $this->assertDatabaseHas('purchase_item_latest_prices', ['item_accurate_id' => 502, 'purchase_order_accurate_id' => 3002]);
        $this->assertDatabaseHas('purchase_item_latest_prices', ['item_accurate_id' => 503, 'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PO]);
        $this->assertDatabaseHas('purchase_item_latest_prices', ['item_accurate_id' => 504, 'source_type' => null]);
        $this->assertDatabaseHas('purchase_item_latest_prices', ['item_accurate_id' => 505, 'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_COST_VALUE]);
        $this->assertDatabaseHas('purchase_item_cost_values', ['item_accurate_id' => 801, 'item_unit_accurate_id' => 51, 'unit_price' => '88.00000000']);
        $this->assertSame(1, PurchaseRequisition::query()->count());
        $this->assertSame(1, PurchaseRequisitionItem::query()->count());
        $snapshot = PurchaseRequisitionItem::query()->firstOrFail();
        $this->assertSame('123.00000000', $snapshot->latest_purchase_unit_price);
        $this->assertSame('PO.OLD', $snapshot->source_purchase_order_number);
    }

    public function test_empty_reconciliation_deletes_only_pi_rows(): void
    {
        $this->latestPrice(901, 51, 1901, 'PI.1901', '2026-08-01', '100.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PI);
        $this->latestPrice(902, 51, 1902, 'PO.1902', '2026-08-01', '100.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PO);
        $this->latestPrice(903, 51, 1903, 'LEGACY.1903');
        $this->latestPrice(904, 51, 1904, 'CV.1904', '2026-08-01', '100.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_COST_VALUE);

        $result = (new PurchaseInvoiceLatestPriceSyncService(new BoundaryInvoiceFakeClient([]), fn (): null => null))->reconcile([]);

        $this->assertSame(1, $result['legacy_deleted']);
        $this->assertDatabaseMissing('purchase_item_latest_prices', ['item_accurate_id' => 901]);
        $this->assertDatabaseHas('purchase_item_latest_prices', ['item_accurate_id' => 902, 'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PO]);
        $this->assertDatabaseHas('purchase_item_latest_prices', ['item_accurate_id' => 903, 'source_type' => null]);
        $this->assertDatabaseHas('purchase_item_latest_prices', ['item_accurate_id' => 904, 'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_COST_VALUE]);
    }

    public function test_full_reconciliation_authoritative_candidate_replaces_stale_future_pi_cache(): void
    {
        $this->latestPrice(911, 51, 1911, 'PI.STALE', '2026-12-08', '1911.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PI);

        $result = (new PurchaseInvoiceLatestPriceSyncService(new BoundaryInvoiceFakeClient([]), fn (): null => null))->reconcile([[
            'item_accurate_id' => 911,
            'item_no' => 'ITEM-911',
            'item_name' => 'Item 911',
            'item_unit_accurate_id' => 51,
            'item_unit_name' => 'grm',
            'unit_price' => '812.00000000',
            'purchase_order_accurate_id' => 812,
            'purchase_order_number' => 'PI.2026.08.00120',
            'purchase_order_date' => '2026-08-12',
            'purchase_order_detail_id' => 10812,
            'source_updated_at' => null,
            'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PI,
        ]]);

        $this->assertSame(1, $result['updated']);
        $price = PurchaseItemLatestPrice::query()->where('item_accurate_id', 911)->firstOrFail();
        $this->assertSame(51, (int) $price->item_unit_accurate_id);
        $this->assertSame(812, (int) $price->purchase_order_accurate_id);
        $this->assertSame('PI.2026.08.00120', $price->purchase_order_number);
        $this->assertSame('2026-08-12', $price->purchase_order_date->toDateString());
        $this->assertSame('812.00000000', $price->unit_price);
        $this->assertSame(PurchaseItemLatestPrice::SOURCE_TYPE_PI, $price->source_type);
    }

    public function test_direct_full_sync_uses_canonical_parser_and_authoritative_latest_candidate_set(): void
    {
        $this->latestPrice(931, 51, 1931, 'PI.STALE', '2026-12-08', '1931.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PI);
        $this->latestPrice(932, 51, 1932, 'PI.OBSOLETE', '2026-08-01', '1932.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PI);
        $this->latestPrice(933, 51, 1933, 'PO.1933', '2026-08-01', '1933.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PO);
        $this->latestPrice(934, 51, 1934, 'LEGACY.1934');
        $this->costValue(935, 51, '935.00000000');

        $result = (new PurchaseInvoiceLatestPriceSyncService(new BoundaryInvoiceFakeClient([
            1 => [
                ['id' => 812, 'transDate' => '12/08/2026'],
                ['id' => 813, 'transDate' => '13/08/2026'],
                ['id' => 811, 'transDate' => '11/08/2026'],
            ],
        ], [
            812 => ['itemId' => 931, 'unitId' => 51, 'price' => '812.00000000', 'date' => '12/08/2026'],
            813 => ['itemId' => 931, 'unitId' => 51, 'price' => '813.00000000', 'date' => '13/08/2026'],
            811 => ['itemId' => 936, 'unitId' => 51, 'price' => '811.00000000', 'date' => '2026-08-11'],
        ]), fn (): null => null))->sync(1, 100, null, null, 0);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['inserted']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['legacy_deleted']);
        $updated = PurchaseItemLatestPrice::query()->where('item_accurate_id', 931)->firstOrFail();
        $inserted = PurchaseItemLatestPrice::query()->where('item_accurate_id', 936)->firstOrFail();
        $this->assertSame(813, (int) $updated->purchase_order_accurate_id);
        $this->assertSame('2026-08-13', $updated->purchase_order_date->toDateString());
        $this->assertSame('813.00000000', $updated->unit_price);
        $this->assertSame(PurchaseItemLatestPrice::SOURCE_TYPE_PI, $updated->source_type);
        $this->assertSame(811, (int) $inserted->purchase_order_accurate_id);
        $this->assertSame('2026-08-11', $inserted->purchase_order_date->toDateString());
        $this->assertSame(PurchaseItemLatestPrice::SOURCE_TYPE_PI, $inserted->source_type);
        $this->assertDatabaseMissing('purchase_item_latest_prices', ['item_accurate_id' => 932]);
        $this->assertDatabaseHas('purchase_item_latest_prices', ['item_accurate_id' => 933, 'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PO]);
        $this->assertDatabaseHas('purchase_item_latest_prices', ['item_accurate_id' => 934, 'source_type' => null]);
        $this->assertDatabaseHas('purchase_item_cost_values', ['item_accurate_id' => 935, 'unit_price' => '935.00000000']);
    }

    public function test_reconciliation_rolls_back_when_candidate_store_fails(): void
    {
        $this->latestPrice(921, 51, 1921, 'PI.1921', '2026-08-01', '100.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PI);
        $this->latestPrice(922, 51, 1922, 'PI.1922', '2026-08-01', '100.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PI);

        try {
            (new PurchaseInvoiceLatestPriceSyncService(new BoundaryInvoiceFakeClient([]), fn (): null => null))->reconcile([
                [
                    'item_accurate_id' => 921,
                    'item_no' => 'ITEM-921',
                    'item_name' => 'Item 921',
                    'item_unit_accurate_id' => 51,
                    'item_unit_name' => 'grm',
                    'unit_price' => '921.00000000',
                    'purchase_order_accurate_id' => 2921,
                    'purchase_order_number' => 'PI.2921',
                    'purchase_order_date' => '2026-08-25',
                    'purchase_order_detail_id' => 12921,
                    'source_updated_at' => null,
                    'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PI,
                ],
                [
                    'item_accurate_id' => null,
                    'item_no' => 'BROKEN',
                    'item_name' => 'Broken candidate',
                    'item_unit_accurate_id' => 51,
                    'item_unit_name' => 'grm',
                    'unit_price' => '1.00000000',
                    'purchase_order_accurate_id' => 2999,
                    'purchase_order_number' => 'PI.2999',
                    'purchase_order_date' => '2026-08-25',
                    'purchase_order_detail_id' => 12999,
                    'source_updated_at' => null,
                    'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PI,
                ],
            ]);
        } catch (QueryException) {
        }

        $this->assertDatabaseHas('purchase_item_latest_prices', [
            'item_accurate_id' => 921,
            'purchase_order_accurate_id' => 1921,
            'unit_price' => '100.00000000',
            'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PI,
        ]);
        $this->assertDatabaseHas('purchase_item_latest_prices', [
            'item_accurate_id' => 922,
            'purchase_order_accurate_id' => 1922,
            'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PI,
        ]);
    }

    public function test_incremental_first_run_captures_upper_date_and_resets_execution_cursor(): void
    {
        Queue::fake();
        $state = $this->completedState(3, 50, null, '2026-08-20');
        $owner = $this->ownSmartSyncLock();
        $client = new BoundaryInvoiceFakeClient([
            1 => [
                ['id' => 10, 'transDate' => '2026-09-04'],
                ['id' => 9, 'transDate' => '2026-09-04'],
            ],
        ]);

        (new SyncPurchaseRequisitionPurchaseOrdersBatch($owner, 3))->handle(
            new PurchaseInvoiceLatestPriceSyncService($client, fn (): null => null),
        );

        $state = $state->fresh();

        $this->assertSame('completed', $state->status);
        $this->assertSame('2026-09-04', $state->incremental_completed_upper_trans_date->toDateString());
        $this->assertNull($state->incremental_run_upper_trans_date);
        $this->assertSame(2, $state->incremental_page);
        $this->assertSame(0, $state->incremental_row_index);
        $this->assertSame([1, 1], $client->requestedPages);
        $this->assertSame([10, 9], $client->detailIds);
    }

    public function test_legacy_completed_state_with_missing_boundary_fails_closed_without_using_cache_max_date(): void
    {
        Queue::fake();
        $state = $this->completedState(157, 0);
        $this->latestPrice(7001, 51, 701, 'PI.701', '2026-09-02', '701.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PI);
        $this->latestPrice(7002, 51, 702, 'PI.702', '2026-09-04', '702.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PI);
        $this->latestPrice(7003, 51, 703, 'PO.703', '2026-09-06', '703.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PO);
        $owner = $this->ownSmartSyncLock();
        $client = new BoundaryInvoiceFakeClient([
            1 => [
                ['id' => 110, 'transDate' => '2026-09-05'],
                ['id' => 100, 'transDate' => '2026-09-04'],
                ['id' => 90, 'transDate' => '2026-09-03'],
            ],
        ]);

        (new SyncPurchaseRequisitionPurchaseOrdersBatch($owner, 157))->handle(
            new PurchaseInvoiceLatestPriceSyncService($client, fn (): null => null),
        );

        $state = $state->fresh();

        $this->assertSame('incremental_failed', $state->status);
        $this->assertNull($state->incremental_completed_upper_trans_date);
        $this->assertNull($state->incremental_run_upper_trans_date);
        $this->assertSame('PI incremental baseline is missing. Repair or rebuild the PI latest-price cache before running Smart Sync.', $state->error_message);
        $this->assertSame([], $client->detailIds);
        $this->assertSame([], $client->requestedPages);
        $this->assertDatabaseMissing('purchase_item_latest_prices', ['purchase_order_accurate_id' => 90]);
    }

    public function test_legacy_completed_state_with_missing_boundary_does_not_proceed_into_historical_scan(): void
    {
        Queue::fake();
        $state = $this->completedState(157, 0);
        $this->latestPrice(7101, 51, 711, 'PI.711', '2026-09-04', '711.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PI);
        $owner = $this->ownSmartSyncLock();
        $client = new BoundaryInvoiceFakeClient([
            1 => array_map(
                fn (int $id): array => ['id' => $id, 'transDate' => '2026-09-05'],
                range(1000, 1099),
            ),
        ]);

        (new SyncPurchaseRequisitionPurchaseOrdersBatch($owner, 157))->handle(
            new PurchaseInvoiceLatestPriceSyncService($client, fn (): null => null),
        );

        $state = $state->fresh();

        $this->assertSame('incremental_failed', $state->status);
        $this->assertNull($state->incremental_run_upper_trans_date);
        $this->assertNull($state->incremental_completed_upper_trans_date);
        $this->assertSame(157, $state->incremental_page);
        $this->assertSame(0, $state->incremental_row_index);
        $this->assertSame([], $client->detailIds);
        $this->assertSame([], $client->requestedPages);
    }

    public function test_empty_pi_cache_missing_legacy_boundary_fails_closed_without_inventing_date(): void
    {
        Queue::fake();
        $state = $this->completedState(157, 0);
        $owner = $this->ownSmartSyncLock();
        $client = new BoundaryInvoiceFakeClient([]);

        (new SyncPurchaseRequisitionPurchaseOrdersBatch($owner, 157))->handle(
            new PurchaseInvoiceLatestPriceSyncService($client, fn (): null => null),
        );

        $state = $state->fresh();

        $this->assertSame('incremental_failed', $state->status);
        $this->assertNull($state->incremental_completed_upper_trans_date);
        $this->assertNull($state->incremental_run_upper_trans_date);
        $this->assertSame([], $client->requestedPages);
    }

    public function test_successful_initial_full_migration_seeds_incremental_completed_boundary_from_reconciled_pi_cache(): void
    {
        Queue::fake();
        $state = PurchaseInvoiceLatestPriceMigrationState::query()->create([
            'status' => 'running',
            'run_id' => 'initial-full-migration',
            'current_page' => 1,
            'current_row_index' => 0,
            'incremental_page' => 1,
            'incremental_row_index' => 0,
            'candidates' => [],
            'completed_at' => null,
        ]);
        $owner = $this->ownSmartSyncLock();
        $client = new BoundaryInvoiceFakeClient([
            1 => [
                ['id' => 200, 'transDate' => '2026-09-04'],
                ['id' => 100, 'transDate' => '2026-09-01'],
            ],
        ], [
            200 => ['itemId' => 8200, 'unitId' => 51, 'price' => '200.00000000', 'date' => '2026-09-04'],
            100 => ['itemId' => 8100, 'unitId' => 51, 'price' => '100.00000000', 'date' => '2026-09-01'],
        ]);

        (new SyncPurchaseRequisitionPurchaseOrdersBatch($owner, 1))->handle(
            new PurchaseInvoiceLatestPriceSyncService($client, fn (): null => null),
        );

        $state = $state->fresh();

        $this->assertSame('completed', $state->status);
        $this->assertNotNull($state->completed_at);
        $this->assertSame('2026-09-04', $state->incremental_completed_upper_trans_date->toDateString());
        $this->assertNull($state->incremental_run_upper_trans_date);
    }

    public function test_incremental_never_calls_reconcile(): void
    {
        Queue::fake();
        $state = $this->completedState(1, 0, '2026-09-04', '2026-09-03');
        $owner = $this->ownSmartSyncLock();
        $service = new ReconcileCountingPurchaseInvoiceLatestPriceSyncService(new BoundaryInvoiceFakeClient([
            1 => [
                ['id' => 10, 'transDate' => '2026-09-04'],
            ],
        ]));

        (new SyncPurchaseRequisitionPurchaseOrdersBatch($owner, 1))->handle($service);

        $this->assertSame(0, $service->reconcileCalls);
        $this->assertSame('completed', $state->fresh()->status);
    }

    public function test_newer_date_after_active_boundary_is_deferred_without_detail_fetch(): void
    {
        Queue::fake();
        $state = $this->completedState(1, 0, '2026-09-04', '2026-09-03');
        $owner = $this->ownSmartSyncLock();
        $client = new BoundaryInvoiceFakeClient([
            1 => [
                ['id' => 101, 'transDate' => '2026-09-05'],
                ['id' => 100, 'transDate' => '2026-09-04'],
                ['id' => 99, 'transDate' => '2026-09-03'],
            ],
        ]);

        (new SyncPurchaseRequisitionPurchaseOrdersBatch($owner, 1))->handle(
            new PurchaseInvoiceLatestPriceSyncService($client, fn (): null => null),
        );

        $state = $state->fresh();

        $this->assertSame([100, 99], $client->detailIds);
        $this->assertDatabaseMissing('purchase_item_latest_prices', ['purchase_order_accurate_id' => 101]);
        $this->assertSame('2026-09-04', $state->incremental_completed_upper_trans_date->toDateString());
        $this->assertNull($state->incremental_run_upper_trans_date);
    }

    public function test_stop_condition_processes_equal_boundary_date_and_stops_on_older_date(): void
    {
        Queue::fake();
        $state = $this->completedState(1, 0, '2026-09-04', '2026-09-04');
        $owner = $this->ownSmartSyncLock();
        $client = new BoundaryInvoiceFakeClient([
            1 => [
                ['id' => 100, 'transDate' => '2026-09-04'],
                ['id' => 50, 'transDate' => '2026-09-03'],
            ],
        ]);

        (new SyncPurchaseRequisitionPurchaseOrdersBatch($owner, 1))->handle(
            new PurchaseInvoiceLatestPriceSyncService($client, fn (): null => null),
        );

        $this->assertSame([100], $client->detailIds);
        $this->assertSame('completed', $state->fresh()->status);
    }

    public function test_duplicate_older_newer_and_new_item_unit_incremental_cache_rules(): void
    {
        $this->latestPrice(5100, 51, 100, 'PI.100', '2026-09-04', '200.00000000');
        $client = new BoundaryInvoiceFakeClient([
            1 => [
                ['id' => 90, 'transDate' => '2026-09-03'],
                ['id' => 100, 'transDate' => '2026-09-04'],
                ['id' => 110, 'transDate' => '2026-09-05'],
                ['id' => 200, 'transDate' => '2026-09-05'],
            ],
        ], [
            90 => ['itemId' => 5100, 'unitId' => 51, 'price' => '90.00000000', 'date' => '2026-09-03'],
            100 => ['itemId' => 5100, 'unitId' => 51, 'price' => '200.00000000', 'date' => '2026-09-04'],
            110 => ['itemId' => 5100, 'unitId' => 51, 'price' => '300.00000000', 'date' => '2026-09-05'],
            200 => ['itemId' => 5200, 'unitId' => 51, 'price' => '400.00000000', 'date' => '2026-09-05'],
        ]);
        $service = new PurchaseInvoiceLatestPriceSyncService($client, fn (): null => null);

        $result = $service->syncSmartUnprocessedPurchaseInvoiceBatch(1, 100, 50, 0, [], 'quick', false, 0, '2026-09-05', null);

        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('purchase_item_latest_prices', ['item_accurate_id' => 5100, 'purchase_order_accurate_id' => 110]);
        $this->assertDatabaseHas('purchase_item_latest_prices', ['item_accurate_id' => 5200, 'purchase_order_accurate_id' => 200]);
        $this->assertSame(2, PurchaseItemLatestPrice::query()->count());
    }

    public function test_existing_newer_po_is_replaced_by_older_incoming_pi(): void
    {
        $this->latestPrice(6100, 51, 900, 'PO.900', '2026-09-04', '900.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PO);
        $service = new PurchaseInvoiceLatestPriceSyncService(new BoundaryInvoiceFakeClient([
            1 => [['id' => 800, 'transDate' => '2026-09-01']],
        ], [
            800 => ['itemId' => 6100, 'unitId' => 51, 'price' => '800.00000000', 'date' => '2026-09-01'],
        ]), fn (): null => null);

        $result = $service->syncSmartUnprocessedPurchaseInvoiceBatch(1, 100, 50, 0, [], 'quick', false, 0, '2026-09-04', null);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['updated']);
        $this->assertDatabaseHas('purchase_item_latest_prices', [
            'item_accurate_id' => 6100,
            'purchase_order_accurate_id' => 800,
            'purchase_order_number' => 'PI.800',
            'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PI,
        ]);
    }

    public function test_existing_older_po_is_replaced_by_newer_incoming_pi(): void
    {
        $this->latestPrice(6200, 51, 700, 'PO.700', '2026-08-01', '700.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PO);

        $result = (new PurchaseInvoiceLatestPriceSyncService(new BoundaryInvoiceFakeClient([
            1 => [['id' => 710, 'transDate' => '2026-08-02']],
        ], [
            710 => ['itemId' => 6200, 'unitId' => 51, 'price' => '710.00000000', 'date' => '2026-08-02'],
        ]), fn (): null => null))->syncSmartUnprocessedPurchaseInvoiceBatch(1, 100, 50, 0, [], 'quick', false, 0, '2026-08-02', null);

        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('purchase_item_latest_prices', [
            'item_accurate_id' => 6200,
            'purchase_order_accurate_id' => 710,
            'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PI,
        ]);
    }

    public function test_existing_unknown_source_is_replaced_by_incoming_pi(): void
    {
        $this->latestPrice(6250, 51, 950, 'LEGACY.950', '2026-09-04', '950.00000000', null);

        $result = (new PurchaseInvoiceLatestPriceSyncService(new BoundaryInvoiceFakeClient([
            1 => [['id' => 750, 'transDate' => '2026-08-01']],
        ], [
            750 => ['itemId' => 6250, 'unitId' => 51, 'price' => '750.00000000', 'date' => '2026-08-01'],
        ]), fn (): null => null))->syncSmartUnprocessedPurchaseInvoiceBatch(1, 100, 50, 0, [], 'quick', false, 0, '2026-09-04', null);

        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('purchase_item_latest_prices', [
            'item_accurate_id' => 6250,
            'purchase_order_accurate_id' => 750,
            'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PI,
        ]);
    }

    public function test_existing_newer_pi_keeps_out_older_incoming_pi(): void
    {
        $this->latestPrice(6300, 51, 900, 'PI.900', '2026-09-04', '900.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PI);

        $result = (new PurchaseInvoiceLatestPriceSyncService(new BoundaryInvoiceFakeClient([
            1 => [['id' => 800, 'transDate' => '2026-09-01']],
        ], [
            800 => ['itemId' => 6300, 'unitId' => 51, 'price' => '800.00000000', 'date' => '2026-09-01'],
        ]), fn (): null => null))->syncSmartUnprocessedPurchaseInvoiceBatch(1, 100, 50, 0, [], 'quick', false, 0, '2026-09-04', null);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['unchanged']);
        $this->assertDatabaseHas('purchase_item_latest_prices', [
            'item_accurate_id' => 6300,
            'purchase_order_accurate_id' => 900,
            'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PI,
        ]);
    }

    public function test_existing_older_pi_is_replaced_by_newer_incoming_pi(): void
    {
        $this->latestPrice(6400, 51, 800, 'PI.800', '2026-09-01', '800.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PI);

        $result = (new PurchaseInvoiceLatestPriceSyncService(new BoundaryInvoiceFakeClient([
            1 => [['id' => 900, 'transDate' => '2026-09-04']],
        ], [
            900 => ['itemId' => 6400, 'unitId' => 51, 'price' => '900.00000000', 'date' => '2026-09-04'],
        ]), fn (): null => null))->syncSmartUnprocessedPurchaseInvoiceBatch(1, 100, 50, 0, [], 'quick', false, 0, '2026-09-04', null);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['updated']);
        $this->assertDatabaseHas('purchase_item_latest_prices', [
            'item_accurate_id' => 6400,
            'purchase_order_accurate_id' => 900,
            'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PI,
        ]);
    }

    public function test_pi_equal_date_uses_document_id_tie_breaker(): void
    {
        $this->latestPrice(6500, 51, 800, 'PI.800', '2026-09-04', '800.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PI);

        $result = (new PurchaseInvoiceLatestPriceSyncService(new BoundaryInvoiceFakeClient([
            1 => [['id' => 801, 'transDate' => '2026-09-04']],
        ], [
            801 => ['itemId' => 6500, 'unitId' => 51, 'price' => '801.00000000', 'date' => '2026-09-04'],
        ]), fn (): null => null))->syncSmartUnprocessedPurchaseInvoiceBatch(1, 100, 50, 0, [], 'quick', false, 0, '2026-09-04', null);

        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('purchase_item_latest_prices', [
            'item_accurate_id' => 6500,
            'purchase_order_accurate_id' => 801,
            'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PI,
        ]);
    }

    public function test_pi_equal_date_and_document_id_uses_detail_id_tie_breaker(): void
    {
        $this->latestPrice(6600, 51, 800, 'PI.800', '2026-09-04', '800.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PI, 10800);

        $result = (new PurchaseInvoiceLatestPriceSyncService(new DetailIdInvoiceFakeClient([
            1 => [['id' => 800, 'transDate' => '2026-09-04']],
        ], 10801, [
            800 => ['itemId' => 6600, 'unitId' => 51, 'price' => '801.00000000', 'date' => '2026-09-04'],
        ]), fn (): null => null))->syncSmartUnprocessedPurchaseInvoiceBatch(1, 100, 50, 0, [], 'quick', false, 0, '2026-09-04', null);

        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('purchase_item_latest_prices', [
            'item_accurate_id' => 6600,
            'unit_price' => '801.00000000',
            'purchase_order_detail_id' => 10801,
            'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PI,
        ]);
    }

    public function test_full_reconcile_replaces_newer_po_with_older_pi(): void
    {
        $this->latestPrice(6700, 51, 900, 'PO.900', '2026-09-04', '900.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PO);

        $result = (new PurchaseInvoiceLatestPriceSyncService(new BoundaryInvoiceFakeClient([]), fn (): null => null))->reconcile([[
            'item_accurate_id' => 6700,
            'item_no' => 'ITEM-6700',
            'item_name' => 'Item 6700',
            'item_unit_accurate_id' => 51,
            'item_unit_name' => 'grm',
            'unit_price' => '700.00000000',
            'purchase_order_accurate_id' => 700,
            'purchase_order_number' => 'PI.700',
            'purchase_order_date' => '2026-08-01',
            'purchase_order_detail_id' => 10700,
            'source_updated_at' => null,
        ]]);

        $this->assertSame(1, $result['updated']);
        $this->assertDatabaseHas('purchase_item_latest_prices', [
            'item_accurate_id' => 6700,
            'purchase_order_accurate_id' => 700,
            'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PI,
        ]);
    }

    public function test_purchase_order_latest_price_sync_no_longer_inserts_cache_rows(): void
    {
        $result = (new PurchaseOrderLatestPriceSyncService(new PurchaseOrderWriterFakeClient()))->sync(1, 10, 1, 1, 0);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['lines_processed']);
        $this->assertSame(0, $result['inserted']);
        $this->assertSame(0, PurchaseItemLatestPrice::query()->count());
    }

    public function test_purchase_order_latest_price_sync_cannot_overwrite_existing_pi_row(): void
    {
        $this->latestPrice(6800, 51, 800, 'PI.800', '2026-08-01', '800.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PI);

        $result = (new PurchaseOrderLatestPriceSyncService(new PurchaseOrderWriterFakeClient(6800, 51)))->sync(1, 10, 1, 1, 0);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['lines_processed']);
        $this->assertDatabaseHas('purchase_item_latest_prices', [
            'item_accurate_id' => 6800,
            'unit_price' => '800.00000000',
            'purchase_order_number' => 'PI.800',
            'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PI,
        ]);
    }

    public function test_source_type_migration_backfills_known_prefixes_only(): void
    {
        Schema::dropIfExists('purchase_item_latest_prices');
        Schema::create('purchase_item_latest_prices', function (Blueprint $table): void {
            $table->id();
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
        });

        DB::table('purchase_item_latest_prices')->insert([
            ['item_accurate_id' => 1, 'item_unit_accurate_id' => 1, 'unit_price' => 1, 'purchase_order_accurate_id' => 1, 'purchase_order_number' => 'PI.1'],
            ['item_accurate_id' => 2, 'item_unit_accurate_id' => 1, 'unit_price' => 1, 'purchase_order_accurate_id' => 2, 'purchase_order_number' => 'PO.2'],
            ['item_accurate_id' => 3, 'item_unit_accurate_id' => 1, 'unit_price' => 1, 'purchase_order_accurate_id' => 3, 'purchase_order_number' => 'OTHER.3'],
        ]);

        (require database_path('migrations/2026_09_04_000001_add_source_type_to_purchase_item_latest_prices_table.php'))->up();

        $this->assertDatabaseHas('purchase_item_latest_prices', ['purchase_order_number' => 'PI.1', 'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PI]);
        $this->assertDatabaseHas('purchase_item_latest_prices', ['purchase_order_number' => 'PO.2', 'source_type' => PurchaseItemLatestPrice::SOURCE_TYPE_PO]);
        $this->assertDatabaseHas('purchase_item_latest_prices', ['purchase_order_number' => 'OTHER.3', 'source_type' => null]);
    }

    private function completedState(int $incrementalPage, int $incrementalRowIndex, ?string $runUpperTransDate = null, ?string $completedUpperTransDate = null): PurchaseInvoiceLatestPriceMigrationState
    {
        return PurchaseInvoiceLatestPriceMigrationState::query()->create([
            'status' => 'completed',
            'run_id' => 'completed-first-migration',
            'current_page' => 7,
            'current_row_index' => 0,
            'incremental_page' => $incrementalPage,
            'incremental_row_index' => $incrementalRowIndex,
            'incremental_run_upper_trans_date' => $runUpperTransDate,
            'incremental_completed_upper_trans_date' => $completedUpperTransDate,
            'candidates' => null,
            'completed_at' => now(),
        ]);
    }

    private function latestPrice(int $itemId, int $unitId, int $sourceId, string $number, string $date = '2026-08-01', string $price = '100.00000000', ?string $sourceType = null, ?int $detailId = null): PurchaseItemLatestPrice
    {
        return PurchaseItemLatestPrice::query()->create([
            'item_accurate_id' => $itemId,
            'item_no' => "ITEM-$itemId",
            'item_name' => "Item $itemId",
            'item_unit_accurate_id' => $unitId,
            'item_unit_name' => 'grm',
            'unit_price' => $price,
            'purchase_order_accurate_id' => $sourceId,
            'purchase_order_number' => $number,
            'purchase_order_date' => $date,
            'purchase_order_detail_id' => $detailId ?? 10000 + $sourceId,
            'source_type' => $sourceType,
        ]);
    }

    private function ownSmartSyncLock(): string
    {
        $lock = Cache::lock(PurchaseRequisitionSmartSync::LOCK_KEY, PurchaseRequisitionSmartSync::LOCK_TTL_SECONDS);
        $lock->get();
        $owner = $lock->owner();
        PurchaseRequisitionSmartSync::markRunning($owner);

        return $owner;
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('purchase_requisition_items');
        Schema::dropIfExists('purchase_requisitions');
        Schema::dropIfExists('purchase_invoice_latest_price_migration_states');
        Schema::dropIfExists('purchase_item_cost_values');
        Schema::dropIfExists('purchase_item_latest_prices');
        Schema::dropIfExists('accurate_items');
    }

    private function costValue(int $itemId, int $unitId, string $price): PurchaseItemCostValue
    {
        return PurchaseItemCostValue::query()->create([
            'item_accurate_id' => $itemId,
            'item_no' => "ITEM-$itemId",
            'item_name' => "Item $itemId",
            'item_unit_accurate_id' => $unitId,
            'item_unit_name' => 'grm',
            'unit_position' => 1,
            'unit_price' => $price,
            'balance_unit_cost' => $price,
            'synced_at' => now(),
        ]);
    }
}

final class IncrementalInvoiceFakeClient extends AccurateClient
{
    public array $detailIds = [];
    public array $requestedPages = [];

    public function __construct(private ?int $failDetailId = null) {}

    public function listPurchaseInvoices(array $params = []): array
    {
        $page = (int) ($params['sp.page'] ?? 1);
        $this->requestedPages[] = $page;
        $start = (($page - 1) * 100) + 1;

        return ['ok' => true, 'body' => ['d' => array_map(
            fn (int $id): array => ['id' => $id, 'number' => "PI.$id", 'transDate' => '2026-08-20', 'approvalStatus' => 'APPROVED'],
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

final class MalformedRowInvoiceFakeClient extends AccurateClient
{
    public array $detailIds = [];

    public function __construct() {}

    public function listPurchaseInvoices(array $params = []): array
    {
        return ['ok' => true, 'body' => ['d' => array_merge(
            [
                ['id' => 1, 'number' => 'PI.1', 'transDate' => '2026-08-20', 'approvalStatus' => 'APPROVED'],
                ['number' => 'PI.BAD', 'transDate' => '2026-08-20', 'approvalStatus' => 'APPROVED'],
                ['id' => 3, 'number' => 'PI.3', 'transDate' => '2026-08-20', 'approvalStatus' => 'APPROVED'],
            ],
            array_map(
                fn (int $id): array => ['id' => $id, 'number' => "PI.$id", 'transDate' => '2026-08-20', 'approvalStatus' => 'APPROVED'],
                range(4, 100),
            ),
        )]];
    }

    public function detailPurchaseInvoice(int|string $id): array
    {
        $id = (int) $id;
        $this->detailIds[] = $id;

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

final class BoundaryInvoiceFakeClient extends AccurateClient
{
    public array $detailIds = [];
    public array $requestedPages = [];

    public function __construct(private array $pages, private array $details = []) {}

    public function listPurchaseInvoices(array $params = []): array
    {
        $page = (int) ($params['sp.page'] ?? 1);
        $this->requestedPages[] = $page;

        return ['ok' => true, 'body' => ['d' => array_map(
            fn (array $row): array => [
                'id' => $row['id'],
                'number' => $row['number'] ?? "PI.{$row['id']}",
                'transDate' => $row['transDate'],
                'approvalStatus' => 'APPROVED',
            ],
            $this->pages[$page] ?? [],
        )]];
    }

    public function detailPurchaseInvoice(int|string $id): array
    {
        $id = (int) $id;
        $this->detailIds[] = $id;
        $detail = $this->details[$id] ?? ['itemId' => 5000 + $id, 'unitId' => 51, 'price' => (string) (100 + $id), 'date' => '2026-09-04'];

        return ['ok' => true, 'body' => ['d' => [
            'id' => $id,
            'number' => "PI.$id",
            'transDate' => $detail['date'],
            'approvalStatus' => 'APPROVED',
            'detailItem' => [[
                'id' => 10000 + $id,
                'item' => ['id' => $detail['itemId'], 'no' => "ITEM-{$detail['itemId']}", 'name' => "Item {$detail['itemId']}"],
                'itemUnit' => ['id' => $detail['unitId'], 'name' => 'grm'],
                'unitPrice' => $detail['price'],
            ]],
        ]]];
    }
}

final class DetailIdInvoiceFakeClient extends AccurateClient
{
    public function __construct(private array $pages, private int $detailId, private array $details = []) {}

    public function listPurchaseInvoices(array $params = []): array
    {
        $page = (int) ($params['sp.page'] ?? 1);

        return ['ok' => true, 'body' => ['d' => array_map(
            fn (array $row): array => [
                'id' => $row['id'],
                'number' => $row['number'] ?? "PI.{$row['id']}",
                'transDate' => $row['transDate'],
                'approvalStatus' => 'APPROVED',
            ],
            $this->pages[$page] ?? [],
        )]];
    }

    public function detailPurchaseInvoice(int|string $id): array
    {
        $id = (int) $id;
        $detail = $this->details[$id] ?? ['itemId' => 5000 + $id, 'unitId' => 51, 'price' => (string) (100 + $id), 'date' => '2026-09-04'];

        return ['ok' => true, 'body' => ['d' => [
            'id' => $id,
            'number' => "PI.$id",
            'transDate' => $detail['date'],
            'approvalStatus' => 'APPROVED',
            'detailItem' => [[
                'id' => $this->detailId,
                'item' => ['id' => $detail['itemId'], 'no' => "ITEM-{$detail['itemId']}", 'name' => "Item {$detail['itemId']}"],
                'itemUnit' => ['id' => $detail['unitId'], 'name' => 'grm'],
                'unitPrice' => $detail['price'],
            ]],
        ]]];
    }
}

final class PurchaseOrderWriterFakeClient extends AccurateClient
{
    public function __construct(private int $itemId = 6900, private int $unitId = 51) {}

    public function listPurchaseOrders(array $params = []): array
    {
        return ['ok' => true, 'status' => 200, 'body' => ['s' => true, 'd' => [[
            'id' => 990,
            'number' => 'PO.990',
            'transDate' => '2026-09-04',
            'approvalStatus' => 'APPROVED',
        ]]]];
    }

    public function detailPurchaseOrder(int|string $id): array
    {
        return ['ok' => true, 'status' => 200, 'body' => ['s' => true, 'd' => [
            'id' => (int) $id,
            'number' => "PO.$id",
            'transDate' => '2026-09-04',
            'detailItem' => [[
                'id' => 1990,
                'item' => ['id' => $this->itemId, 'no' => "ITEM-{$this->itemId}", 'name' => "Item {$this->itemId}"],
                'itemUnit' => ['id' => $this->unitId, 'name' => 'grm'],
                'unitPrice' => '990.00000000',
            ]],
        ]]];
    }
}

final class ThrowingBoundaryInvoiceFakeClient extends AccurateClient
{
    public function __construct() {}

    public function listPurchaseInvoices(array $params = []): array
    {
        throw new \RuntimeException('Boundary capture exploded.');
    }
}

final class ReconcileCountingPurchaseInvoiceLatestPriceSyncService extends PurchaseInvoiceLatestPriceSyncService
{
    public int $reconcileCalls = 0;

    public function __construct(BoundaryInvoiceFakeClient $client)
    {
        parent::__construct($client, fn (): null => null);
    }

    public function reconcile(array $dataset): array
    {
        $this->reconcileCalls++;

        return ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'legacy_deleted' => 0];
    }
}
