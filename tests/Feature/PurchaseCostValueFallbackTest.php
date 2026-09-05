<?php

namespace Tests\Feature;

use App\Models\AccurateBranch;
use App\Models\AccurateItem;
use App\Models\AccurateItemUnit;
use App\Models\PurchaseItemCostValue;
use App\Models\PurchaseItemLatestPrice;
use App\Models\PurchaseRequisition;
use App\Services\Accurate\AccurateCostValueSyncService;
use App\Services\PurchaseRequisitions\CreateLocalPurchaseRequisition;
use App\Services\PurchaseRequisitions\PurchaseLatestPriceResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseCostValueFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'purchase_requisition_items',
            'purchase_requisitions',
            'purchase_item_cost_values',
            'purchase_item_latest_prices',
            'accurate_item_units',
            'accurate_items',
            'accurate_branches',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('accurate_branches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('accurate_id')->unique();
            $table->string('name')->nullable();
            $table->timestamps();
        });

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
            $table->string('source_type', 20)->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
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

        Schema::create('purchase_requisitions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('creator_name')->nullable();
            $table->date('trans_date');
            $table->string('requisition_type')->default('PURCHASE');
            $table->string('description')->nullable();
            $table->foreignId('accurate_branch_id')->nullable()->constrained('accurate_branches')->nullOnDelete();
            $table->unsignedBigInteger('branch_accurate_id')->nullable();
            $table->string('branch_name')->nullable();
            $table->string('status')->default('submitted');
            $table->string('sync_status')->default('pending');
            $table->string('accurate_status')->nullable();
            $table->unsignedBigInteger('accurate_id')->nullable();
            $table->string('accurate_number')->nullable();
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_requisition_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_requisition_id')->constrained('purchase_requisitions')->cascadeOnDelete();
            $table->foreignId('accurate_item_id')->nullable()->constrained('accurate_items')->nullOnDelete();
            $table->unsignedBigInteger('item_accurate_id');
            $table->string('item_no');
            $table->string('item_name');
            $table->unsignedBigInteger('item_unit_accurate_id');
            $table->string('item_unit_name');
            $table->decimal('quantity', 24, 6)->default(0);
            $table->date('required_date');
            $table->text('note')->nullable();
            $table->decimal('latest_purchase_unit_price', 24, 8)->default(0);
            $table->decimal('total_price', 24, 8)->default(0);
            $table->string('latest_price_source_type', 20)->nullable();
            $table->unsignedBigInteger('source_document_accurate_id')->nullable();
            $table->string('source_document_number')->nullable();
            $table->date('source_document_date')->nullable();
            $table->timestamp('source_price_synced_at')->nullable();
            $table->unsignedBigInteger('source_purchase_order_accurate_id')->nullable();
            $table->string('source_purchase_order_number')->nullable();
            $table->date('source_purchase_order_date')->nullable();
            $table->timestamps();
        });
    }

    public function test_resolver_uses_pi_before_cost_value(): void
    {
        $item = $this->item();
        $this->unit($item, 50, 'pcs', 1);
        $this->costValue($item, 50, '999.00000000');
        $this->pi($item, 50, '123.00000000');

        $resolved = app(PurchaseLatestPriceResolver::class)->resolve(790, 50);

        $this->assertSame(PurchaseItemLatestPrice::SOURCE_TYPE_PI, $resolved?->sourceType);
        $this->assertSame('123.00000000', $resolved->price);
    }

    public function test_resolver_uses_cost_value_when_exact_unit_has_no_pi(): void
    {
        $item = $this->item();
        $this->unit($item, 50, 'pcs', 1);
        $this->unit($item, 51, 'grm', 2);
        $this->pi($item, 50, '123.00000000');
        $this->costValue($item, 51, '7.50000000');

        $resolved = app(PurchaseLatestPriceResolver::class)->resolve(790, 51);

        $this->assertSame(PurchaseItemLatestPrice::SOURCE_TYPE_COST_VALUE, $resolved?->sourceType);
        $this->assertSame('7.50000000', $resolved->price);
    }

    public function test_resolver_rejects_legacy_po_rows(): void
    {
        $item = $this->item();
        $this->unit($item, 50, 'pcs', 1);
        $this->pi($item, 50, '123.00000000', PurchaseItemLatestPrice::SOURCE_TYPE_PO);

        $this->assertNull(app(PurchaseLatestPriceResolver::class)->resolve(790, 50));
    }

    public function test_cost_value_materializes_base_and_alternate_units(): void
    {
        $item = $this->item();

        app(AccurateCostValueSyncService::class)->syncFromItemDetailResponse($item, $this->detailResponse([
            'unit1' => ['id' => 54, 'name' => 'ml'],
            'unit2' => ['id' => 101, 'name' => 'gln'],
            'ratio2' => 15000,
            'balanceUnitCost' => 1.799999,
            'balanceTotalCost' => 216899.881,
        ]));

        $this->assertDatabaseHas('purchase_item_cost_values', [
            'item_accurate_id' => 790,
            'item_unit_accurate_id' => 54,
            'unit_position' => 1,
            'unit_price' => '1.79999900',
            'ratio' => null,
        ]);
        $this->assertDatabaseHas('purchase_item_cost_values', [
            'item_accurate_id' => 790,
            'item_unit_accurate_id' => 101,
            'unit_position' => 2,
            'unit_price' => '26999.98500000',
            'ratio' => '15000.000000000000',
        ]);
    }

    public function test_cost_value_refresh_removes_stale_rows_for_same_item_only(): void
    {
        $itemA = $this->item(790);
        $itemB = $this->item(791);
        $this->costValue($itemA, 50, '1.00000000');
        $this->costValue($itemA, 51, '2.00000000');
        $this->costValue($itemB, 52, '3.00000000');

        app(AccurateCostValueSyncService::class)->syncFromItemDetailResponse($itemA, $this->detailResponse([
            'unit1' => ['id' => 50, 'name' => 'pcs'],
            'balanceUnitCost' => 5,
        ]));

        $this->assertDatabaseHas('purchase_item_cost_values', ['item_accurate_id' => 790, 'item_unit_accurate_id' => 50]);
        $this->assertDatabaseMissing('purchase_item_cost_values', ['item_accurate_id' => 790, 'item_unit_accurate_id' => 51]);
        $this->assertDatabaseHas('purchase_item_cost_values', ['item_accurate_id' => 791, 'item_unit_accurate_id' => 52]);
    }

    public function test_cost_value_failure_leaves_existing_rows_untouched(): void
    {
        $item = $this->item();
        $this->costValue($item, 50, '1.00000000');

        try {
            app(AccurateCostValueSyncService::class)->syncFromItemDetailResponse($item, ['ok' => false, 'status' => 500]);
        } catch (\RuntimeException) {
        }

        $this->assertDatabaseHas('purchase_item_cost_values', ['item_accurate_id' => 790, 'item_unit_accurate_id' => 50]);
    }

    public function test_zero_balance_unit_cost_removes_existing_rows_for_that_item(): void
    {
        $item = $this->item();
        $this->costValue($item, 50, '1.00000000');

        app(AccurateCostValueSyncService::class)->syncFromItemDetailResponse($item, $this->detailResponse([
            'unit1' => ['id' => 50, 'name' => 'pcs'],
            'balanceUnitCost' => 0,
        ]));

        $this->assertDatabaseMissing('purchase_item_cost_values', ['item_accurate_id' => 790, 'item_unit_accurate_id' => 50]);
    }

    public function test_create_purchase_requisition_snapshots_cost_value_without_fake_document(): void
    {
        AccurateBranch::create(['accurate_id' => 50, 'name' => 'Kantor Pusat']);
        $item = $this->item();
        $this->unit($item, 50, 'pcs', 1);
        $this->costValue($item, 50, '10.00000000');

        $record = app(CreateLocalPurchaseRequisition::class)->create([
            'trans_date' => '2026-09-05',
            'items' => [[
                'accurate_item_id' => $item->id,
                'item_unit_accurate_id' => 50,
                'quantity' => '2',
                'required_date' => '2026-09-06',
            ]],
        ]);

        $line = $record->items->first();
        $this->assertSame('10.00000000', $line->latest_purchase_unit_price);
        $this->assertSame('20.00000000', $line->total_price);
        $this->assertSame(PurchaseItemLatestPrice::SOURCE_TYPE_COST_VALUE, $line->latest_price_source_type);
        $this->assertNull($line->source_document_accurate_id);
        $this->assertNull($line->source_document_number);
        $this->assertNull($line->source_purchase_order_accurate_id);
    }

    public function test_create_purchase_requisition_still_rejects_when_no_source_exists(): void
    {
        AccurateBranch::create(['accurate_id' => 50, 'name' => 'Kantor Pusat']);
        $item = $this->item();
        $this->unit($item, 50, 'pcs', 1);

        $this->expectException(ValidationException::class);

        app(CreateLocalPurchaseRequisition::class)->create([
            'trans_date' => '2026-09-05',
            'items' => [[
                'accurate_item_id' => $item->id,
                'item_unit_accurate_id' => 50,
                'quantity' => '2',
                'required_date' => '2026-09-06',
            ]],
        ]);
    }

    private function item(int $accurateId = 790): AccurateItem
    {
        return AccurateItem::create([
            'accurate_id' => $accurateId,
            'no' => (string) $accurateId,
            'name' => 'Item ' . $accurateId,
            'raw' => [],
        ]);
    }

    private function unit(AccurateItem $item, int $unitId, string $name, int $position): AccurateItemUnit
    {
        return AccurateItemUnit::create([
            'accurate_item_id' => $item->id,
            'item_accurate_id' => (int) $item->accurate_id,
            'item_no' => $item->no,
            'item_name' => $item->name,
            'item_unit_accurate_id' => $unitId,
            'item_unit_name' => $name,
            'position' => $position,
            'source' => 'accurate_item_detail',
        ]);
    }

    private function pi(AccurateItem $item, int $unitId, string $price, string $sourceType = PurchaseItemLatestPrice::SOURCE_TYPE_PI): PurchaseItemLatestPrice
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
            'source_type' => $sourceType,
        ]);
    }

    private function costValue(AccurateItem $item, int $unitId, string $price): PurchaseItemCostValue
    {
        return PurchaseItemCostValue::create([
            'accurate_item_id' => $item->id,
            'item_accurate_id' => (int) $item->accurate_id,
            'item_no' => $item->no,
            'item_name' => $item->name,
            'item_unit_accurate_id' => $unitId,
            'item_unit_name' => 'pcs',
            'unit_position' => 1,
            'unit_price' => $price,
            'balance_unit_cost' => $price,
            'synced_at' => now(),
        ]);
    }

    private function detailResponse(array $detail): array
    {
        return ['ok' => true, 'status' => 200, 'body' => ['s' => true, 'd' => $detail + [
            'id' => 790,
            'no' => '790',
            'name' => 'Item 790',
            'itemType' => 'INVENTORY',
        ]]];
    }
}
