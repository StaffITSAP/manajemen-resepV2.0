<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseRequisitionResource\Pages\ListPurchaseRequisitions;
use App\Models\AccurateBranch;
use App\Models\Permission;
use App\Models\PurchaseRequisition;
use App\Models\Role;
use App\Models\User;
use App\Services\Accurate\AccurateClient;
use App\Services\PurchaseRequisitions\Accurate\PurchaseRequisitionSender;
use App\Services\PurchaseRequisitions\SmartSync\PurchaseRequisitionSmartSync;
use App\Services\PurchaseRequisitions\UpdatePurchaseRequisitionReceiptStatus;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class PurchaseRequisitionReceiptStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'purchase_requisition_activity_logs',
            'purchase_requisition_items',
            'purchase_requisitions',
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
            $table->text('rejection_reason')->nullable();
            $table->enum('receipt_status', ['pending', 'received'])->default('pending');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_requisition_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_requisition_id')->constrained('purchase_requisitions')->cascadeOnDelete();
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

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_existing_purchase_requisition_defaults_to_pending_receipt_status(): void
    {
        $owner = $this->userWithPermissions('owner', ['view_purchase_requisition_own']);
        $record = $this->eligibleRequisition($owner);

        $this->assertSame(PurchaseRequisition::RECEIPT_STATUS_PENDING, $record->fresh()->receiptStatusOrDefault());
        $this->assertNull($record->fresh()->received_at);
    }

    public function test_requester_can_confirm_pending_receipt_once(): void
    {
        $owner = $this->userWithPermissions('owner', ['view_purchase_requisition_own']);
        $record = $this->eligibleRequisition($owner);

        $updated = $this->service()->confirmReceived($record, $owner);

        $this->assertSame(PurchaseRequisition::RECEIPT_STATUS_RECEIVED, $updated->receipt_status);
        $this->assertNotNull($updated->received_at);
        $this->assertDatabaseHas('purchase_requisition_activity_logs', [
            'purchase_requisition_id' => $record->id,
            'user_id' => $owner->id,
            'action' => 'Konfirmasi Penerimaan Barang',
        ]);
    }

    public function test_other_requester_and_viewer_cannot_change_receipt_status(): void
    {
        $owner = $this->userWithPermissions('owner', ['view_purchase_requisition_own']);
        $other = $this->userWithPermissions('other', ['view_purchase_requisition_own']);
        $viewer = $this->userWithPermissions('viewer', ['view_purchase_requisition_all']);
        $record = $this->eligibleRequisition($owner);

        foreach ([$other, $viewer] as $actor) {
            try {
                $this->service()->confirmReceived($record, $actor);
                $this->fail('Unauthorized user changed receipt status.');
            } catch (RuntimeException) {
                $this->assertSame(PurchaseRequisition::RECEIPT_STATUS_PENDING, $record->fresh()->receipt_status);
            }
        }
    }

    public function test_requester_cannot_revert_received_status(): void
    {
        $owner = $this->userWithPermissions('owner', ['view_purchase_requisition_own']);
        $record = $this->eligibleRequisition($owner, [
            'receipt_status' => PurchaseRequisition::RECEIPT_STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);

        $this->service()->revertReceived($record, $owner);
    }

    public function test_superadmin_can_revert_and_requester_can_confirm_again(): void
    {
        $owner = $this->userWithPermissions('owner', ['view_purchase_requisition_own']);
        $superadmin = $this->userWithRole('superadmin');
        $record = $this->eligibleRequisition($owner, [
            'receipt_status' => PurchaseRequisition::RECEIPT_STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        $reverted = $this->service()->revertReceived($record, $superadmin);

        $this->assertSame(PurchaseRequisition::RECEIPT_STATUS_PENDING, $reverted->receipt_status);
        $this->assertNull($reverted->received_at);
        $this->assertDatabaseHas('purchase_requisition_activity_logs', [
            'purchase_requisition_id' => $record->id,
            'user_id' => $superadmin->id,
            'action' => 'Batalkan Penerimaan Barang',
        ]);

        $confirmed = $this->service()->confirmReceived($record->fresh(), $owner);

        $this->assertSame(PurchaseRequisition::RECEIPT_STATUS_RECEIVED, $confirmed->receipt_status);
        $this->assertSame(2, $confirmed->activityLogs()->count());
    }

    public function test_ineligible_purchase_requisition_cannot_be_confirmed(): void
    {
        $owner = $this->userWithPermissions('owner', ['view_purchase_requisition_own']);

        foreach ([
            ['sync_status' => 'pending', 'approved_at' => null],
            ['status' => 'draft'],
            ['status' => 'cancelled', 'rejected_at' => now()],
            ['sync_status' => 'failed', 'approved_at' => now()],
        ] as $overrides) {
            $record = $this->requisition($owner, $overrides);

            try {
                $this->service()->confirmReceived($record, $owner);
                $this->fail('Ineligible record was confirmed.');
            } catch (RuntimeException) {
                $this->assertSame(PurchaseRequisition::RECEIPT_STATUS_PENDING, $record->fresh()->receiptStatusOrDefault());
            }
        }
    }

    public function test_badge_confirmation_cancel_does_not_change_database(): void
    {
        $owner = $this->userWithPermissions('owner', ['view_purchase_requisition_own']);
        $record = $this->eligibleRequisition($owner);
        $this->actingAs($owner);

        Livewire::test(ListPurchaseRequisitions::class)
            ->mountTableAction('confirmReceipt', $record)
            ->assertSee('Konfirmasi Penerimaan Barang')
            ->assertSee('Ya, Sudah Diterima')
            ->unmountTableAction();

        $this->assertSame(PurchaseRequisition::RECEIPT_STATUS_PENDING, $record->fresh()->receipt_status);
        $this->assertNull($record->fresh()->received_at);
        $this->assertSame(0, $record->activityLogs()->count());
    }

    public function test_badge_confirmation_updates_receipt_without_accurate_interaction(): void
    {
        $this->app->bind(PurchaseRequisitionSender::class, fn() => throw new RuntimeException('Sender must not be resolved.'));
        $this->app->bind(AccurateClient::class, fn() => throw new RuntimeException('Accurate client must not be resolved.'));

        $owner = $this->userWithPermissions('owner', ['view_purchase_requisition_own']);
        $record = $this->eligibleRequisition($owner);
        $this->actingAs($owner);

        Livewire::test(ListPurchaseRequisitions::class)
            ->assertSee('Status Penerimaan')
            ->assertSee('Belum Diterima')
            ->assertSeeHtml('aria-label="Konfirmasi Penerimaan Barang"')
            ->assertSeeHtml('wire:click.stop.prevent')
            ->assertDontSeeHtml('aria-label="Status Penerimaan"')
            ->mountTableAction('confirmReceipt', $record)
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified(Notification::make()
                ->success()
                ->title('Status penerimaan diperbarui.')
                ->body('Barang ditandai sudah diterima.'));

        $fresh = $record->fresh();

        $this->assertSame(PurchaseRequisition::RECEIPT_STATUS_RECEIVED, $fresh->receipt_status);
        $this->assertNotNull($fresh->received_at);
        $this->assertSame('synced', $fresh->sync_status);
        $this->assertSame('DRAFT', $fresh->accurate_status);
        $this->assertSame(9001, $fresh->accurate_id);
        $this->assertSame('DFT.1', $fresh->accurate_number);
        $this->assertNull($fresh->payload);
        $this->assertNull($fresh->response);
    }

    public function test_receipt_service_does_not_resolve_accurate_or_smart_sync_services(): void
    {
        $this->app->bind(PurchaseRequisitionSender::class, fn() => throw new RuntimeException('Sender must not be resolved.'));
        $this->app->bind(PurchaseRequisitionSmartSync::class, fn() => throw new RuntimeException('Smart sync must not be resolved.'));
        $this->app->bind(AccurateClient::class, fn() => throw new RuntimeException('Accurate client must not be resolved.'));

        $owner = $this->userWithPermissions('owner', ['view_purchase_requisition_own']);
        $record = $this->eligibleRequisition($owner);

        $updated = $this->service()->confirmReceived($record, $owner);

        $this->assertSame(PurchaseRequisition::RECEIPT_STATUS_RECEIVED, $updated->receipt_status);
    }

    public function test_superadmin_revert_action_uses_confirmation(): void
    {
        $owner = $this->userWithPermissions('owner', ['view_purchase_requisition_own']);
        $superadmin = $this->userWithRole('superadmin');
        $record = $this->eligibleRequisition($owner, [
            'receipt_status' => PurchaseRequisition::RECEIPT_STATUS_RECEIVED,
            'received_at' => now(),
        ]);
        $this->actingAs($superadmin);

        Livewire::test(ListPurchaseRequisitions::class)
            ->assertTableActionVisible('revertReceipt', $record)
            ->mountTableAction('revertReceipt', $record)
            ->assertSee('Batalkan Penerimaan Barang')
            ->assertSee('Batalkan Penerimaan')
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame(PurchaseRequisition::RECEIPT_STATUS_PENDING, $record->fresh()->receipt_status);
        $this->assertNull($record->fresh()->received_at);
    }

    private function service(): UpdatePurchaseRequisitionReceiptStatus
    {
        return app(UpdatePurchaseRequisitionReceiptStatus::class);
    }

    private function eligibleRequisition(User $owner, array $overrides = []): PurchaseRequisition
    {
        return $this->requisition($owner, array_merge([
            'status' => 'submitted',
            'sync_status' => 'synced',
            'approved_by' => $this->userWithRole('spv')->id,
            'approved_at' => now(),
            'accurate_status' => 'DRAFT',
            'accurate_id' => 9001,
            'accurate_number' => 'DFT.1',
        ], $overrides));
    }

    private function requisition(User $owner, array $overrides = []): PurchaseRequisition
    {
        $branch = AccurateBranch::firstOrCreate(['accurate_id' => 50], ['name' => 'Kantor Pusat']);

        return PurchaseRequisition::create(array_merge([
            'user_id' => $owner->id,
            'creator_name' => $owner->name,
            'trans_date' => '2026-09-22',
            'requisition_type' => 'PURCHASE',
            'description' => 'Outlet',
            'accurate_branch_id' => $branch->id,
            'branch_accurate_id' => 50,
            'branch_name' => 'Kantor Pusat',
            'status' => 'submitted',
            'sync_status' => 'pending',
            'receipt_status' => PurchaseRequisition::RECEIPT_STATUS_PENDING,
        ], $overrides));
    }

    private function userWithPermissions(string $name, array $permissionNames): User
    {
        $user = User::create([
            'name' => $name,
            'email' => "{$name}@example.com",
            'password' => 'secret',
            'username' => $name,
        ]);

        $role = Role::firstOrCreate(['name' => "role-{$name}"], ['description' => $name]);

        $permissions = collect($permissionNames)
            ->map(fn(string $permissionName): Permission => Permission::firstOrCreate(
                ['name' => $permissionName],
                ['description' => $permissionName],
            ));

        $role->permissions()->syncWithoutDetaching($permissions->pluck('id'));
        $user->roles()->attach($role);

        return $user->fresh(['roles.permissions']);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['description' => $roleName]);
        $user = User::create([
            'name' => $roleName . uniqid(),
            'email' => $roleName . uniqid() . '@example.com',
            'password' => 'secret',
            'username' => $roleName . uniqid(),
            'role' => $roleName,
        ]);
        $user->roles()->attach($role);

        return $user->fresh(['roles.permissions']);
    }
}
