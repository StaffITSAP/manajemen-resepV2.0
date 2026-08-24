<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseRequisitionResource;
use App\Models\AccurateBranch;
use App\Models\AccurateItem;
use App\Models\AccurateItemUnit;
use App\Models\PurchaseItemLatestPrice;
use App\Models\PurchaseRequisition;
use App\Services\Accurate\AccurateClient;
use App\Services\PurchaseRequisitions\CreateLocalPurchaseRequisition;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateLocalPurchaseRequisitionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('purchase_requisition_items');
        Schema::dropIfExists('purchase_requisitions');
        Schema::dropIfExists('purchase_item_latest_prices');
        Schema::dropIfExists('accurate_item_units');
        Schema::dropIfExists('accurate_items');
        Schema::dropIfExists('accurate_branches');

        Schema::create('accurate_branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('accurate_id')->unique();
            $table->string('name')->nullable();
            $table->string('description')->nullable();
            $table->string('location_code')->nullable();
            $table->timestamps();
        });

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
            $table->unique(['item_accurate_id', 'item_unit_accurate_id'], 'test_aiu_item_unit_unique');
            $table->unique(['item_accurate_id', 'position'], 'test_aiu_item_position_unique');
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
            $table->unique(['item_accurate_id', 'item_unit_accurate_id'], 'test_latest_item_unit_unique');
        });

        Schema::create('purchase_requisitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('creator_name')->nullable();
            $table->date('trans_date');
            $table->string('requisition_type')->default('PURCHASE');
            $table->string('description')->nullable();
            $table->foreignId('accurate_branch_id')->nullable()->constrained('accurate_branches')->nullOnDelete();
            $table->unsignedBigInteger('branch_accurate_id')->nullable();
            $table->string('branch_name')->nullable();
            $table->enum('status', ['draft', 'submitted', 'cancelled'])->default('draft');
            $table->enum('sync_status', ['pending', 'processing', 'synced', 'failed'])->default('pending');
            $table->string('accurate_status')->nullable();
            $table->unsignedBigInteger('accurate_id')->nullable();
            $table->string('accurate_number')->nullable();
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_requisition_items', function (Blueprint $table) {
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
            $table->unsignedBigInteger('source_purchase_order_accurate_id')->nullable();
            $table->string('source_purchase_order_number')->nullable();
            $table->date('source_purchase_order_date')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('purchase_requisition_items');
        Schema::dropIfExists('purchase_requisitions');
        Schema::dropIfExists('purchase_item_latest_prices');
        Schema::dropIfExists('accurate_item_units');
        Schema::dropIfExists('accurate_items');
        Schema::dropIfExists('accurate_branches');

        parent::tearDown();
    }

    public function test_creates_local_purchase_requisition_with_multiple_items_and_snapshots(): void
    {
        $branch = $this->createBranch();
        $alchemy = $this->createItem(790, '100069', 'Alchemy 200gr');
        $sugar = $this->createItem(791, '100070', 'Gula');
        $this->createUnit($alchemy, 50, 'pcs', 1);
        $this->createUnit($sugar, 50, 'pcs', 1);
        $this->createLatestPrice($alchemy, 50, '75000.00000000', 81350, 'PO.2026.08.00170', '2026-08-20');
        $this->createLatestPrice($sugar, 50, '12000.00000000', 81351, 'PO.2026.08.00171', '2026-08-21');

        $record = $this->service()->create([
            'trans_date' => '2026-08-22',
            'description' => 'Outlet A',
            'items' => [
                [
                    'accurate_item_id' => $alchemy->id,
                    'item_unit_accurate_id' => 50,
                    'quantity' => '2.500000',
                    'required_date' => '2026-08-25',
                    'note' => 'Butuh cepat',
                ],
                [
                    'accurate_item_id' => $sugar->id,
                    'item_unit_accurate_id' => 50,
                    'quantity' => '3.000000',
                    'required_date' => '2026-08-26',
                ],
            ],
        ]);

        $this->assertSame('PURCHASE', $record->requisition_type);
        $this->assertSame('draft', $record->status);
        $this->assertSame('pending', $record->sync_status);
        $this->assertNull($record->accurate_id);
        $this->assertNull($record->accurate_number);
        $this->assertSame($branch->id, $record->accurate_branch_id);
        $this->assertSame(50, $record->branch_accurate_id);
        $this->assertSame('Kantor Pusat', $record->branch_name);
        $this->assertCount(2, $record->items);

        $first = $record->items->first();
        $this->assertSame($alchemy->id, $first->accurate_item_id);
        $this->assertSame(790, $first->item_accurate_id);
        $this->assertSame('100069', $first->item_no);
        $this->assertSame('Alchemy 200gr', $first->item_name);
        $this->assertSame(50, $first->item_unit_accurate_id);
        $this->assertSame('pcs', $first->item_unit_name);
        $this->assertSame('75000.00000000', $first->latest_purchase_unit_price);
        $this->assertSame('187500.00000000', $first->total_price);
        $this->assertSame(81350, $first->source_purchase_order_accurate_id);
        $this->assertSame('PO.2026.08.00170', $first->source_purchase_order_number);
    }

    public function test_creator_name_snapshot_and_user_id_are_persisted_when_user_is_provided(): void
    {
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('username')->nullable();
            $table->string('role')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        $userId = \App\Models\User::create([
            'name' => 'Admin Permintaan',
            'email' => 'admin-permintaan@example.com',
            'password' => 'secret',
            'username' => 'admin_permintaan',
            'role' => 'superadmin',
        ])->id;

        $this->createBranch();
        $item = $this->createItem(790, '100069', 'Alchemy 200gr');
        $this->createUnit($item, 50, 'pcs', 1);
        $this->createLatestPrice($item, 50, '75000.00000000', 81350, 'PO.2026.08.00170', '2026-08-20');

        $record = $this->service()->create([
            'trans_date' => '2026-08-22',
            'items' => [[
                'accurate_item_id' => $item->id,
                'item_unit_accurate_id' => 50,
                'quantity' => '1',
                'required_date' => '2026-08-25',
            ]],
        ], $userId);

        $this->assertSame($userId, $record->user_id);
        $this->assertSame('Admin Permintaan', $record->creator_name);
    }

    public function test_pcs_and_grm_for_same_item_are_distinguishable_and_price_requires_item_plus_unit(): void
    {
        $this->createBranch();
        $item = $this->createItem(790, '100069', 'Alchemy 200gr');
        $this->createUnit($item, 50, 'pcs', 1);
        $this->createUnit($item, 51, 'grm', 2);
        $this->createLatestPrice($item, 50, '75000.00000000', 81350, 'PO.2026.08.00170', '2026-08-20');

        $this->expectException(ValidationException::class);

        $this->service()->create([
            'trans_date' => '2026-08-22',
            'description' => 'Outlet A',
            'items' => [[
                'accurate_item_id' => $item->id,
                'item_unit_accurate_id' => 51,
                'quantity' => '1',
                'required_date' => '2026-08-25',
            ]],
        ]);
    }

    public function test_item_without_cached_units_cannot_be_saved_and_rolls_back_parent(): void
    {
        $this->createBranch();
        $item = $this->createItem(790, '100069', 'Alchemy 200gr');
        $this->createLatestPrice($item, 50, '75000.00000000', 81350, 'PO.2026.08.00170', '2026-08-20');

        try {
            $this->service()->create([
                'trans_date' => '2026-08-22',
                'description' => 'Outlet A',
                'items' => [[
                    'accurate_item_id' => $item->id,
                    'item_unit_accurate_id' => 50,
                    'quantity' => '1',
                    'required_date' => '2026-08-25',
                ]],
            ]);
        } catch (ValidationException) {
            //
        }

        $this->assertSame(0, PurchaseRequisition::count());
    }

    public function test_missing_latest_price_is_rejected_safely_and_rolls_back(): void
    {
        $this->createBranch();
        $item = $this->createItem(790, '100069', 'Alchemy 200gr');
        $this->createUnit($item, 50, 'pcs', 1);

        try {
            $this->service()->create([
                'trans_date' => '2026-08-22',
                'description' => 'Outlet A',
                'items' => [[
                    'accurate_item_id' => $item->id,
                    'item_unit_accurate_id' => 50,
                    'quantity' => '1',
                    'required_date' => '2026-08-25',
                ]],
            ]);
        } catch (ValidationException) {
            //
        }

        $this->assertSame(0, PurchaseRequisition::count());
    }

    public function test_price_from_another_unit_is_never_used(): void
    {
        $this->createBranch();
        $item = $this->createItem(790, '100069', 'Alchemy 200gr');
        $this->createUnit($item, 50, 'pcs', 1);
        $this->createUnit($item, 51, 'grm', 2);
        $this->createLatestPrice($item, 51, '375.00000000', 81350, 'PO.2026.08.00170', '2026-08-20');

        try {
            $this->service()->create([
                'trans_date' => '2026-08-22',
                'description' => 'Outlet A',
                'items' => [[
                    'accurate_item_id' => $item->id,
                    'item_unit_accurate_id' => 50,
                    'quantity' => '1',
                    'required_date' => '2026-08-25',
                ]],
            ]);
        } catch (ValidationException) {
            //
        }

        $this->assertSame(0, PurchaseRequisition::count());
    }

    public function test_kantor_pusat_must_exist_locally(): void
    {
        $item = $this->createItem(790, '100069', 'Alchemy 200gr');
        $this->createUnit($item, 50, 'pcs', 1);
        $this->createLatestPrice($item, 50, '75000.00000000', 81350, 'PO.2026.08.00170', '2026-08-20');

        $this->expectException(ValidationException::class);

        $this->service()->create([
            'trans_date' => '2026-08-22',
            'items' => [[
                'accurate_item_id' => $item->id,
                'item_unit_accurate_id' => 50,
                'quantity' => '1',
                'required_date' => '2026-08-25',
            ]],
        ]);
    }

    public function test_no_accurate_client_or_network_interaction_occurs(): void
    {
        $this->app->bind(AccurateClient::class, function () {
            throw new \RuntimeException('AccurateClient must not be resolved during local save.');
        });

        $this->createBranch();
        $item = $this->createItem(790, '100069', 'Alchemy 200gr');
        $this->createUnit($item, 50, 'pcs', 1);
        $this->createLatestPrice($item, 50, '75000.00000000', 81350, 'PO.2026.08.00170', '2026-08-20');

        $record = $this->service()->create([
            'trans_date' => '2026-08-22',
            'items' => [[
                'accurate_item_id' => $item->id,
                'item_unit_accurate_id' => 50,
                'quantity' => '1',
                'required_date' => '2026-08-25',
            ]],
        ]);

        $this->assertSame(1, $record->items()->count());
    }

    public function test_resource_has_no_delete_or_edit_page_for_phase_2c(): void
    {
        $pages = array_keys(PurchaseRequisitionResource::getPages());

        $this->assertContains('index', $pages);
        $this->assertContains('create', $pages);
        $this->assertContains('view', $pages);
        $this->assertNotContains('edit', $pages);
        $this->assertFalse(PurchaseRequisitionResource::canDelete(new PurchaseRequisition()));
    }

    private function service(): CreateLocalPurchaseRequisition
    {
        return app(CreateLocalPurchaseRequisition::class);
    }

    private function createBranch(): AccurateBranch
    {
        return AccurateBranch::create([
            'accurate_id' => 50,
            'name' => 'Kantor Pusat',
        ]);
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

    private function createUnit(AccurateItem $item, int $unitAccurateId, string $name, int $position): AccurateItemUnit
    {
        return AccurateItemUnit::create([
            'accurate_item_id' => $item->id,
            'item_accurate_id' => (int) $item->accurate_id,
            'item_no' => $item->no,
            'item_name' => $item->name,
            'item_unit_accurate_id' => $unitAccurateId,
            'item_unit_name' => $name,
            'position' => $position,
            'source' => 'accurate_item_detail',
        ]);
    }

    private function createLatestPrice(
        AccurateItem $item,
        int $unitAccurateId,
        string $price,
        int $poAccurateId,
        string $poNumber,
        string $poDate,
    ): PurchaseItemLatestPrice {
        return PurchaseItemLatestPrice::create([
            'accurate_item_id' => $item->id,
            'item_accurate_id' => (int) $item->accurate_id,
            'item_no' => $item->no,
            'item_name' => $item->name,
            'item_unit_accurate_id' => $unitAccurateId,
            'item_unit_name' => AccurateItemUnit::where('item_accurate_id', $item->accurate_id)
                ->where('item_unit_accurate_id', $unitAccurateId)
                ->value('item_unit_name') ?: 'pcs',
            'unit_price' => $price,
            'purchase_order_accurate_id' => $poAccurateId,
            'purchase_order_number' => $poNumber,
            'purchase_order_date' => $poDate,
            'purchase_order_detail_id' => 5001,
        ]);
    }
}
