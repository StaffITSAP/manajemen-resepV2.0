<?php

namespace Tests\Feature;

use App\Models\AccurateItem;
use App\Models\AccurateItemUnit;
use App\Models\AccurateItemUnitSyncState;
use App\Models\AccuratePurchaseOrderSyncState;
use App\Models\PurchaseItemLatestPrice;
use App\Services\Accurate\AccurateClient;
use App\Services\Accurate\AccurateItemUnitCacheSyncService;
use App\Services\Accurate\AccurateItemUnitService;
use App\Services\Accurate\PurchaseOrderLatestPriceSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SmartSyncStateFoundationTest extends TestCase
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
            $table->unique(['item_accurate_id', 'item_unit_accurate_id'], 'test_aiu_state_item_unit_unique');
            $table->unique(['item_accurate_id', 'position'], 'test_aiu_state_position_unique');
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
            $table->unique(['item_accurate_id', 'item_unit_accurate_id'], 'test_latest_state_item_unit_unique');
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
        Schema::dropIfExists('accurate_purchase_order_sync_states');
        Schema::dropIfExists('purchase_item_latest_prices');
        Schema::dropIfExists('accurate_item_unit_sync_states');
        Schema::dropIfExists('accurate_item_units');
        Schema::dropIfExists('accurate_items');

        parent::tearDown();
    }

    public function test_successful_item_detail_with_units_writes_unit_success_state(): void
    {
        $item = $this->createItem(790, '100069', 'Alchemy 200gr');

        $this->itemUnitService([
            790 => $this->itemDetail(['unit1' => ['id' => 50, 'name' => 'pcs'], 'unit2' => ['id' => 51, 'name' => 'grm']]),
        ])->sync(10);

        $this->assertDatabaseHas('accurate_item_unit_sync_states', [
            'accurate_item_id' => $item->id,
            'item_accurate_id' => 790,
            'unit_count' => 2,
        ]);
        $this->assertNotNull(AccurateItemUnitSyncState::where('item_accurate_id', 790)->value('last_synced_at'));
    }

    public function test_successful_zero_unit_item_detail_writes_state_with_zero_count(): void
    {
        $this->createItem(790, '100069', 'Alchemy 200gr');

        $this->itemUnitService([
            790 => $this->itemDetail(['unit1' => null, 'unit2' => null, 'unit3' => null, 'unit4' => null, 'unit5' => null]),
        ])->sync(10);

        $this->assertSame(0, AccurateItemUnit::count());
        $this->assertDatabaseHas('accurate_item_unit_sync_states', [
            'item_accurate_id' => 790,
            'unit_count' => 0,
        ]);
    }

    public function test_failed_item_detail_does_not_create_or_advance_unit_success_state(): void
    {
        $item = $this->createItem(790, '100069', 'Alchemy 200gr');
        $state = AccurateItemUnitSyncState::create([
            'accurate_item_id' => $item->id,
            'item_accurate_id' => 790,
            'unit_count' => 1,
            'last_synced_at' => '2026-08-20 10:00:00',
        ]);

        $this->itemUnitService([
            790 => ['ok' => false, 'status' => 500, 'body' => ['error' => 'HTTP_ERROR']],
        ])->sync(10);

        $fresh = $state->fresh();
        $this->assertSame(1, AccurateItemUnitSyncState::count());
        $this->assertSame(1, $fresh->unit_count);
        $this->assertSame('2026-08-20 10:00:00', $fresh->last_synced_at->format('Y-m-d H:i:s'));
    }

    public function test_rerunning_successful_item_detail_updates_state_without_duplicates(): void
    {
        $this->createItem(790, '100069', 'Alchemy 200gr');
        $service = $this->itemUnitService([
            790 => $this->itemDetail(['unit1' => ['id' => 50, 'name' => 'pcs'], 'unit2' => ['id' => 51, 'name' => 'grm']]),
        ]);

        $service->sync(10);
        $stateId = AccurateItemUnitSyncState::where('item_accurate_id', 790)->value('id');
        $service->sync(10);

        $this->assertSame(1, AccurateItemUnitSyncState::where('item_accurate_id', 790)->count());
        $this->assertSame($stateId, AccurateItemUnitSyncState::where('item_accurate_id', 790)->value('id'));
        $this->assertSame(2, AccurateItemUnit::count());
    }

    public function test_successfully_processed_purchase_order_writes_processed_state_and_latest_price_provenance(): void
    {
        $this->createItem(790, '100069', 'Alchemy 200gr');
        $this->purchaseOrderService([
            ['id' => 1001, 'number' => 'PO.001', 'transDate' => '20/08/2026'],
        ], [
            1001 => $this->purchaseOrderDetail(1001, 'PO.001', '20/08/2026', [
                ['id' => 5001, 'item' => ['id' => 790, 'no' => '100069', 'name' => 'Alchemy 200gr'], 'itemUnit' => ['id' => 50, 'name' => 'pcs'], 'unitPrice' => 75000],
                ['id' => 5002, 'item' => ['id' => 790, 'no' => '100069', 'name' => 'Alchemy 200gr'], 'itemUnit' => ['id' => 51, 'name' => 'grm'], 'unitPrice' => 375],
            ]),
        ])->sync(1, 10, 1);

        $state = AccuratePurchaseOrderSyncState::where('purchase_order_accurate_id', 1001)->firstOrFail();
        $this->assertSame('PO.001', $state->purchase_order_number);
        $this->assertSame('2026-08-20', $state->purchase_order_date->format('Y-m-d'));
        $this->assertSame(2, PurchaseItemLatestPrice::where('item_accurate_id', 790)->count());
        $this->assertDatabaseHas('purchase_item_latest_prices', [
            'item_accurate_id' => 790,
            'item_unit_accurate_id' => 50,
            'purchase_order_accurate_id' => 1001,
            'purchase_order_number' => 'PO.001',
            'purchase_order_detail_id' => 5001,
        ]);
    }

    public function test_failed_purchase_order_detail_does_not_mark_processed(): void
    {
        $this->purchaseOrderService([
            ['id' => 1001, 'number' => 'PO.001', 'transDate' => '20/08/2026'],
        ], [
            1001 => ['ok' => false, 'status' => 500, 'body' => ['error' => 'HTTP_ERROR']],
        ])->sync(1, 10, 1);

        $this->assertSame(0, AccuratePurchaseOrderSyncState::count());
        $this->assertSame(0, PurchaseItemLatestPrice::count());
    }

    public function test_reprocessing_same_purchase_order_updates_state_without_duplicate(): void
    {
        $service = $this->purchaseOrderService([
            ['id' => 1001, 'number' => 'PO.001', 'transDate' => '20/08/2026'],
        ], [
            1001 => $this->purchaseOrderDetail(1001, 'PO.001-REV', '21/08/2026', []),
        ]);

        $service->sync(1, 10, 1);
        $stateId = AccuratePurchaseOrderSyncState::where('purchase_order_accurate_id', 1001)->value('id');
        $service->sync(1, 10, 1);

        $this->assertSame(1, AccuratePurchaseOrderSyncState::where('purchase_order_accurate_id', 1001)->count());
        $this->assertSame($stateId, AccuratePurchaseOrderSyncState::where('purchase_order_accurate_id', 1001)->value('id'));
        $state = AccuratePurchaseOrderSyncState::where('purchase_order_accurate_id', 1001)->firstOrFail();
        $this->assertSame('PO.001-REV', $state->purchase_order_number);
        $this->assertSame('2026-08-21', $state->purchase_order_date->format('Y-m-d'));
    }

    private function itemUnitService(array $responses): AccurateItemUnitCacheSyncService
    {
        return new AccurateItemUnitCacheSyncService(
            new SmartStateItemDetailClient($responses),
            new AccurateItemUnitService(new class extends AccurateClient {
                public function __construct() {}
            }),
        );
    }

    private function purchaseOrderService(array $rows, array $details): PurchaseOrderLatestPriceSyncService
    {
        return new PurchaseOrderLatestPriceSyncService(new SmartStatePurchaseOrderClient($rows, $details));
    }

    private function itemDetail(array $detail): array
    {
        return ['ok' => true, 'status' => 200, 'body' => ['s' => true, 'd' => $detail]];
    }

    private function purchaseOrderDetail(int $id, string $number, string $date, array $detailItems): array
    {
        return [
            'ok' => true,
            'status' => 200,
            'body' => [
                's' => true,
                'd' => [
                    'id' => $id,
                    'number' => $number,
                    'transDate' => $date,
                    'detailItem' => $detailItems,
                ],
            ],
        ];
    }

    private function createItem(int $accurateId, string $no, string $name): AccurateItem
    {
        return AccurateItem::create([
            'accurate_id' => $accurateId,
            'no' => $no,
            'name' => $name,
            'raw' => [],
        ]);
    }
}

class SmartStateItemDetailClient extends AccurateClient
{
    public function __construct(private array $responses) {}

    public function detailItemById(int|string $accurateItemId): array
    {
        return $this->responses[(int) $accurateItemId] ?? ['ok' => false, 'status' => 404, 'body' => ['error' => 'NOT_FOUND']];
    }

    public function postJson(string $path, array $body = [], array $query = []): array
    {
        throw new \RuntimeException('Remote write must not be called by state foundation tests.');
    }
}

class SmartStatePurchaseOrderClient extends AccurateClient
{
    public function __construct(private array $rows, private array $details) {}

    public function listPurchaseOrders(array $params = []): array
    {
        return ['ok' => true, 'status' => 200, 'body' => ['s' => true, 'd' => $this->rows, 'sp' => ['page' => 1, 'pageCount' => 1]]];
    }

    public function detailPurchaseOrder(int|string $id): array
    {
        return $this->details[(int) $id] ?? ['ok' => false, 'status' => 404, 'body' => ['error' => 'NOT_FOUND']];
    }

    public function postJson(string $path, array $body = [], array $query = []): array
    {
        throw new \RuntimeException('Remote write must not be called by state foundation tests.');
    }
}
