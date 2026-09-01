<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseRequisitionResource;
use App\Filament\Resources\PurchaseRequisitionResource\Pages\EditPurchaseRequisition;
use App\Filament\Resources\PurchaseRequisitionResource\Pages\ViewPurchaseRequisition;
use App\Models\AccurateBranch;
use App\Models\AccurateItem;
use App\Models\AccurateItemUnit;
use App\Models\Permission;
use App\Models\PurchaseItemLatestPrice;
use App\Models\PurchaseRequisition;
use App\Models\Role;
use App\Models\User;
use App\Services\Accurate\AccurateClient;
use App\Services\PurchaseRequisitions\Accurate\PurchaseRequisitionSender;
use App\Services\PurchaseRequisitions\UpdateLocalPurchaseRequisition;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class EditPurchaseRequisitionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'purchase_requisition_activity_logs',
            'purchase_requisition_items',
            'purchase_requisitions',
            'purchase_item_latest_prices',
            'accurate_item_units',
            'accurate_items',
            'accurate_branches',
            'role_permission',
            'user_role',
            'permissions',
            'roles',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

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

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('user_role', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
        });

        Schema::create('accurate_branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('accurate_id')->unique();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('accurate_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('accurate_id')->unique();
            $table->string('no')->nullable();
            $table->string('name')->nullable();
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
            $table->timestamps();
        });

        Schema::create('purchase_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
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
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
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

        Schema::create('purchase_requisition_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_requisition_id')->constrained('purchase_requisitions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->text('summary')->nullable();
            $table->json('changes')->nullable();
            $table->timestamps();
        });
    }

    public function test_pending_authorized_user_can_see_and_open_edit(): void
    {
        $user = $this->userWithPermission('view_purchase_requisition_own', 'edit_purchase_requisition');
        $record = $this->requisition(['user_id' => $user->id]);
        $this->actingAs($user);

        $this->assertTrue($user->can('update', $record));

        Livewire::test(EditPurchaseRequisition::class, ['record' => $record->getRouteKey()])
            ->assertSuccessful();
    }

    public function test_unauthorized_user_cannot_edit_pending_record(): void
    {
        $user = $this->userWithPermission('view_purchase_requisition_own');
        $this->actingAs($user);
        $record = $this->requisition(['user_id' => $user->id]);

        $this->assertFalse(auth()->user()->can('update', $record));
    }

    public function test_full_detail_edit_updates_same_record_replaces_snapshots_and_logs_activity(): void
    {
        $user = $this->userWithPermission('view_purchase_requisition_own', 'edit_purchase_requisition');
        $old = $this->item(790, 'OLD-1', 'Item Lama');
        $new = $this->item(791, 'NEW-1', 'Item Baru');
        $this->unit($old, 50, 'pcs');
        $this->unit($new, 60, 'box');
        $this->price($old, 50, '100.00000000', 'PO-OLD');
        $this->price($new, 60, '250.00000000', 'PO-NEW');

        $record = $this->requisition();
        $record->items()->create([
            'accurate_item_id' => $old->id,
            'item_accurate_id' => 790,
            'item_no' => 'OLD-1',
            'item_name' => 'Item Lama',
            'item_unit_accurate_id' => 50,
            'item_unit_name' => 'pcs',
            'quantity' => '10.000000',
            'required_date' => '2026-08-30',
            'note' => 'lama',
            'latest_purchase_unit_price' => '100.00000000',
            'total_price' => '1000.00000000',
            'source_purchase_order_accurate_id' => 900,
            'source_purchase_order_number' => 'PO-OLD',
            'source_purchase_order_date' => '2026-08-01',
        ]);

        $updated = app(UpdateLocalPurchaseRequisition::class)->update($record, [
            'trans_date' => '2026-09-01',
            'description' => 'Outlet Baru',
            'items' => [[
                'accurate_item_id' => $new->id,
                'item_unit_accurate_id' => 60,
                'quantity' => '3',
                'required_date' => '2026-09-05',
                'note' => 'baru',
            ]],
        ], $user->id);

        $this->assertSame($record->id, $updated->id);
        $this->assertSame(1, PurchaseRequisition::count());
        $this->assertSame('submitted', $updated->status);
        $this->assertNull($updated->approved_at);
        $this->assertNull($updated->rejected_at);
        $this->assertNull($updated->accurate_id);
        $this->assertNull($updated->accurate_number);
        $this->assertNull($updated->accurate_status);

        $item = $updated->items->first();
        $this->assertSame($new->id, $item->accurate_item_id);
        $this->assertSame(791, $item->item_accurate_id);
        $this->assertSame('NEW-1', $item->item_no);
        $this->assertSame('Item Baru', $item->item_name);
        $this->assertSame(60, $item->item_unit_accurate_id);
        $this->assertSame('box', $item->item_unit_name);
        $this->assertSame('3.000000', $item->quantity);
        $this->assertSame('250.00000000', $item->latest_purchase_unit_price);
        $this->assertSame('750.00000000', $item->total_price);
        $this->assertSame('PO-NEW', $item->source_purchase_order_number);

        $this->assertDatabaseHas('purchase_requisition_activity_logs', [
            'purchase_requisition_id' => $record->id,
            'user_id' => $user->id,
            'action' => 'Edit Permintaan Barang',
        ]);
        $this->assertStringContainsString('Ganti item', $updated->activityLogs()->first()->summary);
    }

    public function test_approved_and_rejected_records_are_locked_for_button_and_direct_access(): void
    {
        $user = $this->userWithPermission('view_purchase_requisition_own', 'edit_purchase_requisition');
        $this->actingAs($user);

        foreach ([
            $this->requisition(['user_id' => $user->id, 'approved_at' => now(), 'approved_by' => 1, 'accurate_id' => 1, 'accurate_number' => 'DFT.1']),
            $this->requisition(['user_id' => $user->id, 'status' => 'cancelled', 'rejected_at' => now(), 'rejected_by' => 1]),
        ] as $record) {
            $this->assertFalse(auth()->user()->can('update', $record));
        }
    }

    public function test_race_condition_rejects_save_without_partial_update(): void
    {
        $user = $this->userWithPermission('view_purchase_requisition_own', 'edit_purchase_requisition');
        $item = $this->item(790, 'ITEM-1', 'Item');
        $this->unit($item, 50, 'pcs');
        $this->price($item, 50, '100.00000000', 'PO-1');
        $record = $this->requisition();

        $record->update(['approved_at' => now(), 'approved_by' => $user->id, 'accurate_id' => 1, 'accurate_number' => 'DFT.1']);

        $this->expectException(ValidationException::class);

        app(UpdateLocalPurchaseRequisition::class)->update($record, [
            'trans_date' => '2026-09-01',
            'description' => 'Should Not Save',
            'items' => [[
                'accurate_item_id' => $item->id,
                'item_unit_accurate_id' => 50,
                'quantity' => '2',
                'required_date' => '2026-09-01',
            ]],
        ], $user->id);
    }

    public function test_edit_does_not_resolve_sender_or_accurate_client(): void
    {
        $this->app->bind(PurchaseRequisitionSender::class, fn() => throw new \RuntimeException('Sender must not be resolved.'));
        $this->app->bind(AccurateClient::class, fn() => throw new \RuntimeException('Accurate client must not be resolved.'));

        $item = $this->item(790, 'ITEM-1', 'Item');
        $this->unit($item, 50, 'pcs');
        $this->price($item, 50, '100.00000000', 'PO-1');
        $record = $this->requisition();

        app(UpdateLocalPurchaseRequisition::class)->update($record, [
            'trans_date' => '2026-09-01',
            'description' => 'Local Only',
            'items' => [[
                'accurate_item_id' => $item->id,
                'item_unit_accurate_id' => 50,
                'quantity' => '2',
                'required_date' => '2026-09-01',
            ]],
        ], null);

        $this->assertSame('Local Only', $record->fresh()->description);
    }

    private function userWithPermission(string ...$permissionNames): User
    {
        $label = implode('-', $permissionNames);
        $user = User::create([
            'name' => $label,
            'email' => $label . '@example.com',
            'password' => 'secret',
        ]);
        $role = Role::create(['name' => $label]);
        foreach ($permissionNames as $permissionName) {
            $permission = Permission::firstOrCreate(['name' => $permissionName], ['description' => $permissionName]);
            $role->permissions()->attach($permission);
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function requisition(array $overrides = []): PurchaseRequisition
    {
        $branch = AccurateBranch::firstOrCreate(['accurate_id' => 50], ['name' => 'Kantor Pusat']);

        return PurchaseRequisition::create(array_merge([
            'trans_date' => '2026-08-29',
            'requisition_type' => 'PURCHASE',
            'description' => 'Outlet',
            'accurate_branch_id' => $branch->id,
            'branch_accurate_id' => 50,
            'branch_name' => 'Kantor Pusat',
            'status' => 'submitted',
            'sync_status' => 'pending',
        ], $overrides));
    }

    private function item(int $accurateId, string $no, string $name): AccurateItem
    {
        return AccurateItem::create(['accurate_id' => $accurateId, 'no' => $no, 'name' => $name]);
    }

    private function unit(AccurateItem $item, int $unitId, string $name): AccurateItemUnit
    {
        return AccurateItemUnit::create([
            'accurate_item_id' => $item->id,
            'item_accurate_id' => $item->accurate_id,
            'item_no' => $item->no,
            'item_name' => $item->name,
            'item_unit_accurate_id' => $unitId,
            'item_unit_name' => $name,
            'position' => 1,
            'source' => 'test',
        ]);
    }

    private function price(AccurateItem $item, int $unitId, string $price, string $poNumber): PurchaseItemLatestPrice
    {
        return PurchaseItemLatestPrice::create([
            'accurate_item_id' => $item->id,
            'item_accurate_id' => $item->accurate_id,
            'item_no' => $item->no,
            'item_name' => $item->name,
            'item_unit_accurate_id' => $unitId,
            'item_unit_name' => 'unit',
            'unit_price' => $price,
            'purchase_order_accurate_id' => 900,
            'purchase_order_number' => $poNumber,
            'purchase_order_date' => '2026-08-01',
        ]);
    }
}
