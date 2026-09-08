<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseRequisitionLogResource\Pages\ListPurchaseRequisitionLogs;
use App\Filament\Resources\PurchaseRequisitionResource;
use App\Filament\Resources\PurchaseRequisitionResource\Pages\ViewPurchaseRequisition;
use App\Models\AccurateBranch;
use App\Models\Permission;
use App\Models\PurchaseRequisition;
use App\Models\Role;
use App\Models\User;
use App\Services\Accurate\AccurateClient;
use App\Services\PurchaseRequisitions\Accurate\PurchaseRequisitionSender;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class PurchaseRequisitionRejectionTest extends TestCase
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

    public function test_authorized_approver_can_reject_with_reason_without_accurate_calls(): void
    {
        $this->app->bind(PurchaseRequisitionSender::class, fn() => throw new RuntimeException('Sender must not be resolved.'));
        $this->app->bind(AccurateClient::class, fn() => throw new RuntimeException('Accurate client must not be resolved.'));

        $user = $this->userWithPermission('reject_purchase_requisition', 'view_purchase_requisition_own');
        $record = $this->requisition(['user_id' => $user->id, 'error_message' => 'old error']);
        $this->actingAs($user);

        $updated = PurchaseRequisitionResource::rejectRecord($record, '  Stok belum dibutuhkan  ');

        $this->assertSame('cancelled', $updated->status);
        $this->assertSame($user->id, $updated->rejected_by);
        $this->assertNotNull($updated->rejected_at);
        $this->assertSame('Stok belum dibutuhkan', $updated->rejection_reason);
        $this->assertNull($updated->error_message);
        $this->assertDatabaseHas('purchase_requisition_activity_logs', [
            'purchase_requisition_id' => $record->id,
            'user_id' => $user->id,
            'action' => 'reject',
            'summary' => 'Permintaan Barang Ditolak',
        ]);
        $this->assertSame('submitted', $updated->activityLogs->first()->changes['previous_status']);
        $this->assertSame('cancelled', $updated->activityLogs->first()->changes['new_status']);
        $this->assertSame('Stok belum dibutuhkan', $updated->activityLogs->first()->changes['rejection_reason']);
    }

    public function test_empty_and_whitespace_only_reason_are_rejected(): void
    {
        $user = $this->userWithPermission('reject_purchase_requisition');
        $record = $this->requisition();
        $this->actingAs($user);

        foreach (['', '   '] as $reason) {
            try {
                PurchaseRequisitionResource::rejectRecord($record, $reason);
                $this->fail('Blank rejection reason was accepted.');
            } catch (RuntimeException) {
                $this->assertSame('submitted', $record->fresh()->status);
                $this->assertSame(0, $record->activityLogs()->count());
            }
        }
    }

    public function test_unauthorized_user_cannot_reject(): void
    {
        $user = $this->userWithPermission('view_purchase_requisition_own');
        $record = $this->requisition(['user_id' => $user->id]);
        $this->actingAs($user);

        $this->expectException(RuntimeException::class);

        PurchaseRequisitionResource::rejectRecord($record, 'Tidak valid');
    }

    public function test_reject_rechecks_fresh_state_and_does_not_write_activity_log(): void
    {
        $user = $this->userWithPermission('reject_purchase_requisition');
        $record = $this->requisition();
        $this->actingAs($user);

        $record->update([
            'approved_by' => $user->id,
            'approved_at' => now(),
            'accurate_id' => 123,
            'accurate_number' => 'DFT.123',
        ]);

        $this->expectException(RuntimeException::class);

        try {
            PurchaseRequisitionResource::rejectRecord($record, 'Sudah berubah');
        } finally {
            $fresh = $record->fresh();

            $this->assertSame('submitted', $fresh->status);
            $this->assertNull($fresh->rejected_by);
            $this->assertNull($fresh->rejected_at);
            $this->assertNull($fresh->rejection_reason);
            $this->assertSame(0, $fresh->activityLogs()->count());
        }
    }

    public function test_cancelled_or_accurate_records_cannot_be_rejected_again(): void
    {
        $user = $this->userWithPermission('reject_purchase_requisition');
        $this->actingAs($user);

        foreach ([
            $this->requisition(['status' => 'cancelled', 'rejected_by' => $user->id, 'rejected_at' => now(), 'rejection_reason' => 'Lama']),
            $this->requisition(['accurate_id' => 123]),
            $this->requisition(['accurate_number' => 'DFT.123']),
        ] as $record) {
            try {
                PurchaseRequisitionResource::rejectRecord($record, 'Baru');
                $this->fail('Invalid record was rejected.');
            } catch (RuntimeException) {
                $this->assertSame(0, $record->activityLogs()->count());
                $this->assertNotSame('Baru', $record->fresh()->rejection_reason);
            }
        }
    }

    public function test_view_reject_action_requires_reason_and_renders_rejection_detail(): void
    {
        $user = $this->userWithPermission('reject_purchase_requisition', 'view_purchase_requisition_own');
        $record = $this->requisition(['user_id' => $user->id]);
        $this->actingAs($user);

        Livewire::test(ViewPurchaseRequisition::class, ['record' => $record->getRouteKey()])
            ->mountAction('reject')
            ->setActionData(['rejection_reason' => '   '])
            ->callMountedAction()
            ->assertHasActionErrors(['rejection_reason'])
            ->setActionData(['rejection_reason' => 'Barang tidak sesuai kebutuhan'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertSee('Penolakan')
            ->assertSee('Ditolak')
            ->assertSee('Barang tidak sesuai kebutuhan');
    }

    public function test_historical_rejected_record_with_null_reason_is_readable_in_log_detail(): void
    {
        $user = $this->userWithRole('superadmin');
        $rejecter = $this->userWithRole('staff');
        $record = $this->requisition([
            'status' => 'cancelled',
            'rejected_by' => $rejecter->id,
            'rejected_at' => '2026-09-07 10:00:00',
            'rejection_reason' => null,
        ]);
        $record->items()->create([
            'item_accurate_id' => 790,
            'item_no' => 'ITEM-1',
            'item_name' => 'Barang Lama',
            'item_unit_accurate_id' => 50,
            'item_unit_name' => 'pcs',
            'quantity' => '1.000000',
            'required_date' => '2026-09-08',
            'latest_purchase_unit_price' => '100.00000000',
            'total_price' => '100.00000000',
        ]);
        $this->actingAs($user);

        Livewire::test(ListPurchaseRequisitionLogs::class)
            ->mountTableAction('detail', $record)
            ->assertSee('Penolakan')
            ->assertSee('Ditolak Oleh')
            ->assertSee($rejecter->name)
            ->assertSee('Alasan Penolakan');
    }

    private function userWithPermission(string ...$permissionNames): User
    {
        $user = $this->userWithRole(implode('-', $permissionNames) ?: 'staff');
        $role = $user->roles()->first();

        foreach ($permissionNames as $permissionName) {
            $permission = Permission::firstOrCreate(['name' => $permissionName], ['description' => $permissionName]);
            $role->permissions()->syncWithoutDetaching($permission);
        }

        return $user->load('roles.permissions');
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['description' => $roleName]);
        $user = User::create([
            'name' => $roleName,
            'email' => $roleName . uniqid() . '@example.com',
            'password' => 'secret',
            'role' => $roleName,
        ]);
        $user->roles()->attach($role);

        return $user->load('roles.permissions');
    }

    private function requisition(array $overrides = []): PurchaseRequisition
    {
        $branch = AccurateBranch::firstOrCreate(['accurate_id' => 50], ['name' => 'Kantor Pusat']);

        return PurchaseRequisition::create(array_merge([
            'trans_date' => '2026-09-07',
            'requisition_type' => 'PURCHASE',
            'description' => 'Outlet',
            'accurate_branch_id' => $branch->id,
            'branch_accurate_id' => 50,
            'branch_name' => 'Kantor Pusat',
            'status' => 'submitted',
            'sync_status' => 'pending',
        ], $overrides));
    }
}
