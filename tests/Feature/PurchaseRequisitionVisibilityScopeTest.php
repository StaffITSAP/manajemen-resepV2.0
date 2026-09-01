<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseRequisitionLogResource;
use App\Filament\Resources\PurchaseRequisitionLogResource\Pages\ListPurchaseRequisitionLogs;
use App\Filament\Resources\PurchaseRequisitionResource;
use App\Filament\Resources\PurchaseRequisitionResource\Pages\EditPurchaseRequisition;
use App\Filament\Resources\PurchaseRequisitionResource\Pages\ListPurchaseRequisitions;
use App\Filament\Resources\PurchaseRequisitionResource\Pages\ViewPurchaseRequisition;
use App\Models\AccurateBranch;
use App\Models\Permission;
use App\Models\PurchaseRequisition;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseRequisitionVisibilityScopeTest extends TestCase
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
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
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

    public function test_visibility_scope_uses_own_all_none_and_excludes_null_owner(): void
    {
        [$ownerA, $ownerB] = $this->owners();
        $ownRecord = $this->requisition($ownerA, ['description' => 'Own Outlet']);
        $otherRecord = $this->requisition($ownerB, ['description' => 'Other Outlet']);
        $nullOwnerRecord = $this->requisition(null, ['description' => 'Legacy Null Owner']);

        $ownUser = $this->userWithPermissions('own-user', ['view_purchase_requisition_own']);
        $ownRecord->update(['user_id' => $ownUser->id]);
        $allUser = $this->userWithPermissions('all-user', ['view_purchase_requisition_all']);
        $noScopeUser = $this->userWithPermissions('legacy-view-only', ['view_purchase_requisition']);

        $this->assertSame([$ownRecord->id], PurchaseRequisition::query()->visibleTo($ownUser)->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$ownRecord->id, $otherRecord->id, $nullOwnerRecord->id],
            PurchaseRequisition::query()->visibleTo($allUser)->pluck('id')->all(),
        );
        $this->assertSame([], PurchaseRequisition::query()->visibleTo($noScopeUser)->pluck('id')->all());
        $this->assertFalse($nullOwnerRecord->isVisibleTo($ownUser));
    }

    public function test_resource_list_query_is_scoped_server_side(): void
    {
        [$ownerA, $ownerB] = $this->owners();
        $ownRecord = $this->requisition($ownerA, ['description' => 'Own Outlet']);
        $otherRecord = $this->requisition($ownerB, ['description' => 'Other Outlet']);
        $nullOwnerRecord = $this->requisition(null, ['description' => 'Legacy Null Owner']);
        $ownerA = $this->grant($ownerA, ['view_purchase_requisition_own']);
        $allUser = $this->userWithPermissions('all-user', ['view_purchase_requisition_all']);
        $noScopeUser = $this->userWithPermissions('legacy-view-only', ['view_purchase_requisition']);

        $this->actingAs($ownerA);
        $this->assertSame([$ownRecord->id], PurchaseRequisitionResource::getEloquentQuery()->pluck('id')->all());
        Livewire::test(ListPurchaseRequisitions::class)
            ->assertSee('Own Outlet')
            ->assertDontSee('Other Outlet')
            ->assertDontSee('Legacy Null Owner');

        $this->actingAs($allUser);
        $this->assertEqualsCanonicalizing(
            [$ownRecord->id, $otherRecord->id, $nullOwnerRecord->id],
            PurchaseRequisitionResource::getEloquentQuery()->pluck('id')->all(),
        );

        $this->actingAs($noScopeUser);
        $this->assertSame([], PurchaseRequisitionResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_view_policy_and_direct_page_access_require_visibility(): void
    {
        [$ownerA, $ownerB] = $this->owners();
        $ownRecord = $this->requisition($ownerA);
        $otherRecord = $this->requisition($ownerB);
        $ownerA = $this->grant($ownerA, ['view_purchase_requisition_own']);
        $allUser = $this->userWithPermissions('all-user', ['view_purchase_requisition_all']);
        $noScopeUser = $this->userWithPermissions('legacy-view-only', ['view_purchase_requisition']);

        $this->actingAs($ownerA);
        $this->assertTrue($ownerA->can('view', $ownRecord));
        $this->assertFalse($ownerA->can('view', $otherRecord));
        Livewire::test(ViewPurchaseRequisition::class, ['record' => $ownRecord->getRouteKey()])
            ->assertSuccessful();
        Livewire::test(ViewPurchaseRequisition::class, ['record' => $otherRecord->getRouteKey()])
            ->assertForbidden();

        $this->assertTrue($allUser->can('view', $ownRecord));
        $this->assertTrue($allUser->can('view', $otherRecord));
        $this->assertFalse($noScopeUser->can('viewAny', PurchaseRequisition::class));
        $this->assertFalse($noScopeUser->can('view', $ownRecord));
    }

    public function test_print_pdf_action_is_only_visible_for_approved_requisition(): void
    {
        $owner = $this->userWithPermissions('print-owner', ['view_purchase_requisition_all']);
        $this->actingAs($owner);

        foreach ([
            $this->requisition($owner, ['status' => 'draft']),
            $this->requisition($owner, ['status' => 'submitted']),
            $this->requisition($owner, ['status' => 'cancelled', 'rejected_at' => now()]),
        ] as $record) {
            Livewire::test(ViewPurchaseRequisition::class, ['record' => $record->getRouteKey()])
                ->assertActionHidden('printPdf');
        }

        $approved = $this->requisition($owner, ['approved_at' => now(), 'approved_by' => $owner->id]);

        Livewire::test(ViewPurchaseRequisition::class, ['record' => $approved->getRouteKey()])
            ->assertActionVisible('printPdf');
    }

    public function test_print_pdf_endpoint_rejects_unapproved_records_and_returns_pdf_for_approved_record(): void
    {
        $owner = $this->userWithPermissions('print-endpoint-owner', ['view_purchase_requisition_all']);
        $this->actingAs($owner);

        foreach ([
            $this->requisition($owner, ['status' => 'draft']),
            $this->requisition($owner, ['status' => 'submitted']),
            $this->requisition($owner, ['status' => 'cancelled', 'rejected_at' => now()]),
        ] as $record) {
            $this->get(route('purchase-requisitions.print', ['record' => $record]))->assertNotFound();
        }

        $approved = $this->requisition($owner, [
            'approved_at' => now(),
            'approved_by' => $owner->id,
            'sync_status' => 'failed',
        ]);
        $approved->items()->create([
            'item_accurate_id' => 1,
            'item_no' => '100069',
            'item_name' => 'Alchemy',
            'item_unit_accurate_id' => 1,
            'item_unit_name' => 'grm',
            'quantity' => 1,
            'required_date' => '2026-09-01',
            'latest_purchase_unit_price' => 962,
            'total_price' => 962,
        ]);

        $response = $this->get(route('purchase-requisitions.print', ['record' => $approved]));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_edit_policy_requires_edit_permission_visibility_and_editable_state(): void
    {
        [$ownerA, $ownerB] = $this->owners();
        $ownRecord = $this->requisition($ownerA);
        $otherRecord = $this->requisition($ownerB);
        $approvedRecord = $this->requisition($ownerA, ['approved_at' => now(), 'accurate_id' => 1, 'accurate_number' => 'DFT.1']);
        $rejectedRecord = $this->requisition($ownerA, ['status' => 'cancelled', 'rejected_at' => now()]);
        $ownerEditor = $this->grant($ownerA, ['view_purchase_requisition_own', 'edit_purchase_requisition']);
        $allEditor = $this->userWithPermissions('all-editor', ['view_purchase_requisition_all', 'edit_purchase_requisition']);
        $editWithoutScope = $this->userWithPermissions('edit-without-scope', ['edit_purchase_requisition']);
        $superadmin = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'password' => 'secret',
            'username' => 'superadmin',
            'role' => 'superadmin',
        ]);

        $this->actingAs($ownerEditor);
        $this->assertTrue($ownerEditor->can('update', $ownRecord));
        $this->assertFalse($ownerEditor->can('update', $otherRecord));
        Livewire::test(EditPurchaseRequisition::class, ['record' => $ownRecord->getRouteKey()])
            ->assertSuccessful();
        Livewire::test(EditPurchaseRequisition::class, ['record' => $otherRecord->getRouteKey()])
            ->assertForbidden();

        $this->assertTrue($allEditor->can('update', $otherRecord));
        $this->assertFalse($editWithoutScope->can('update', $ownRecord));
        $this->assertFalse($ownerEditor->can('update', $approvedRecord));
        $this->assertFalse($ownerEditor->can('update', $rejectedRecord));
        $this->assertTrue($superadmin->can('update', $otherRecord));
    }

    public function test_log_resource_applies_same_visibility_scope(): void
    {
        [$ownerA, $ownerB] = $this->owners();
        $ownRecord = $this->requisition($ownerA, ['description' => 'Own Log Outlet']);
        $otherRecord = $this->requisition($ownerB, ['description' => 'Other Log Outlet']);
        $ownerLogUser = $this->grant($ownerA, ['view_purchase_requisition_own', 'view_purchase_requisition_log']);
        $allLogUser = $this->userWithPermissions('all-log-user', ['view_purchase_requisition_all', 'view_purchase_requisition_log']);

        $this->actingAs($ownerLogUser);
        $this->assertSame([$ownRecord->id], PurchaseRequisitionLogResource::getEloquentQuery()->pluck('id')->all());
        Livewire::test(ListPurchaseRequisitionLogs::class)
            ->assertSuccessful()
            ->assertSee('Own Log Outlet')
            ->assertDontSee('Other Log Outlet');

        $this->actingAs($allLogUser);
        $this->assertEqualsCanonicalizing(
            [$ownRecord->id, $otherRecord->id],
            PurchaseRequisitionLogResource::getEloquentQuery()->pluck('id')->all(),
        );
    }

    public function test_permission_seeder_adds_scope_permissions_additively(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertDatabaseHas('permissions', ['name' => 'view_purchase_requisition']);
        $this->assertDatabaseHas('permissions', ['name' => 'view_purchase_requisition_own']);
        $this->assertDatabaseHas('permissions', ['name' => 'view_purchase_requisition_all']);
        $this->assertTrue(Role::where('name', 'staff')->firstOrFail()->permissions()->where('name', 'view_purchase_requisition_own')->exists());
        $this->assertTrue(Role::where('name', 'spv')->firstOrFail()->permissions()->where('name', 'view_purchase_requisition_all')->exists());
    }

    private function owners(): array
    {
        return [
            User::create([
                'name' => 'Owner A',
                'email' => 'owner-a@example.com',
                'password' => 'secret',
                'username' => 'owner_a',
            ]),
            User::create([
                'name' => 'Owner B',
                'email' => 'owner-b@example.com',
                'password' => 'secret',
                'username' => 'owner_b',
            ]),
        ];
    }

    private function userWithPermissions(string $name, array $permissionNames): User
    {
        $user = User::create([
            'name' => $name,
            'email' => "{$name}@example.com",
            'password' => 'secret',
            'username' => $name,
        ]);

        return $this->grant($user, $permissionNames);
    }

    private function grant(User $user, array $permissionNames): User
    {
        $role = Role::firstOrCreate(
            ['name' => 'role-' . $user->username],
            ['description' => 'Role ' . $user->name],
        );

        $permissions = collect($permissionNames)
            ->map(fn(string $permissionName): Permission => Permission::firstOrCreate(
                ['name' => $permissionName],
                ['description' => $permissionName],
            ));

        $role->permissions()->syncWithoutDetaching($permissions->pluck('id'));
        $user->roles()->syncWithoutDetaching([$role->id]);
        $user->unsetRelation('roles');

        return $user->fresh();
    }

    private function requisition(?User $owner, array $overrides = []): PurchaseRequisition
    {
        $branch = AccurateBranch::firstOrCreate(['accurate_id' => 50], ['name' => 'Kantor Pusat']);

        return PurchaseRequisition::create(array_merge([
            'user_id' => $owner?->id,
            'creator_name' => $owner?->name,
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
}
