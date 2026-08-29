<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseRequisitionLogResource;
use App\Filament\Resources\PurchaseRequisitionLogResource\Pages\ListPurchaseRequisitionLogs;
use App\Models\PurchaseRequisition;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseRequisitionLogResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('purchase_requisition_items');
        Schema::dropIfExists('purchase_requisitions');
        Schema::dropIfExists('accurate_branches');
        Schema::dropIfExists('user_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('username')->nullable();
            $table->string('role')->nullable();
            $table->rememberToken()->nullable();
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

        Schema::create('user_role', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'role_id']);
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
            $table->unsignedBigInteger('source_purchase_order_accurate_id')->nullable();
            $table->string('source_purchase_order_number')->nullable();
            $table->date('source_purchase_order_date')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_requisition_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_requisition_id')->constrained('purchase_requisitions')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->text('summary')->nullable();
            $table->json('changes')->nullable();
            $table->timestamps();
        });

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('purchase_requisition_items');
        Schema::dropIfExists('purchase_requisition_activity_logs');
        Schema::dropIfExists('purchase_requisitions');
        Schema::dropIfExists('accurate_branches');
        Schema::dropIfExists('user_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_creator_name_falls_back_to_current_user_name_for_old_records(): void
    {
        $user = $this->createUser('superadmin', 'Current User Name');
        $record = $this->createPurchaseRequisition($user, ['creator_name' => null]);

        $this->assertSame('Current User Name', PurchaseRequisitionLogResource::creatorName($record->load('user')));
    }

    public function test_superadmin_can_access_log_resource_and_non_superadmin_is_denied(): void
    {
        $this->actingAs($this->createUser('superadmin', 'Super Admin'));

        Livewire::test(ListPurchaseRequisitionLogs::class)
            ->assertOk();

        $this->actingAs($this->createUser('staff', 'Staff User'));

        Livewire::test(ListPurchaseRequisitionLogs::class)
            ->assertForbidden();
    }

    public function test_resource_is_read_only(): void
    {
        $this->actingAs($this->createUser('superadmin', 'Super Admin'));

        $record = new PurchaseRequisition();
        $pages = array_keys(PurchaseRequisitionLogResource::getPages());

        $this->assertSame(['index'], $pages);
        $this->assertFalse(PurchaseRequisitionLogResource::canCreate());
        $this->assertFalse(PurchaseRequisitionLogResource::canEdit($record));
        $this->assertFalse(PurchaseRequisitionLogResource::canDelete($record));
        $this->assertFalse(PurchaseRequisitionLogResource::canDeleteAny());

        Livewire::test(ListPurchaseRequisitionLogs::class)
            ->assertTableBulkActionDoesNotExist('delete')
            ->assertTableActionExists('detail');
    }

    public function test_table_shows_local_log_columns_and_ambiguous_result(): void
    {
        $this->actingAs($this->createUser('superadmin', 'Super Admin'));
        $record = $this->createPurchaseRequisition(null, [
            'creator_name' => 'Snapshot Creator',
            'accurate_number' => 'PR.2026.00001',
            'branch_name' => 'Kantor Pusat',
            'description' => 'Outlet A',
            'sync_status' => 'failed',
            'error_message' => 'AMBIGUOUS_REVIEW_REQUIRED: transport status unknown',
        ]);
        $this->createItem($record, 'Alchemy 200gr', '2.000000', 'pcs', '150000.00000000');
        $this->createItem($record, 'Dori 500', '3.000000', 'grm', '90000.00000000');

        Livewire::test(ListPurchaseRequisitionLogs::class)
            ->assertSee('Snapshot Creator')
            ->assertSee('PR.2026.00001')
            ->assertSee('Kantor Pusat')
            ->assertSee('Outlet A')
            ->assertSee('Alchemy 200gr 2 pcs')
            ->assertSee('Dori 500 3 grm')
            ->assertSee('2')
            ->assertSee('Rp 240.000')
            ->assertSee('Perlu Review');
    }

    public function test_detail_action_opens_modal_with_local_read_only_data(): void
    {
        $this->actingAs($this->createUser('superadmin', 'Super Admin'));
        $record = $this->createPurchaseRequisition(null, [
            'creator_name' => 'Snapshot Creator',
            'accurate_id' => 12345,
            'accurate_number' => 'PR.2026.00002',
            'accurate_status' => 'DRAFT',
            'sync_status' => 'synced',
            'synced_at' => '2026-08-24 10:30:00',
        ]);
        $this->createItem($record, 'Alchemy 200gr', '2.000000', 'pcs', '150000.00000000', [
            'item_no' => '100069',
            'note' => 'Butuh cepat',
            'source_purchase_order_number' => 'PO.2026.08.00170',
            'source_purchase_order_date' => '2026-08-20',
        ]);

        Livewire::test(ListPurchaseRequisitionLogs::class)
            ->mountTableAction('detail', $record)
            ->assertSee('Detail Permintaan Barang - PR.2026.00002')
            ->assertSee('Snapshot Creator')
            ->assertSee('PR.2026.00002')
            ->assertSee('12345')
            ->assertSee('Berhasil')
            ->assertSee('Alchemy 200gr')
            ->assertSee('100069')
            ->assertSee('Butuh cepat')
            ->assertSee('PO.2026.08.00170')
            ->assertDontSee('Lihat')
            ->assertDontSee('Retry');

        $this->assertArrayNotHasKey('view', PurchaseRequisitionLogResource::getPages());
    }

    public function test_detail_modal_title_falls_back_without_accurate_number(): void
    {
        $record = $this->createPurchaseRequisition(null, ['accurate_number' => null]);

        $this->assertSame('Detail Permintaan Barang', PurchaseRequisitionLogResource::detailModalHeading($record));
    }

    public function test_log_query_prepares_relationship_counts_and_totals(): void
    {
        $query = PurchaseRequisitionLogResource::getEloquentQuery();

        $this->assertArrayHasKey('user', $query->getEagerLoads());
        $this->assertArrayHasKey('items', $query->getEagerLoads());

        $sql = $query->toSql();
        $this->assertStringContainsString('items_count', $sql);
        $this->assertStringContainsString('items_total_price', $sql);
    }

    private function createUser(string $roleName, string $name): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => $roleName],
            ['description' => ucfirst($roleName)],
        );

        $user = User::create([
            'name' => $name,
            'email' => str_replace(' ', '-', strtolower($name)) . '@example.com',
            'password' => 'secret',
            'username' => str_replace(' ', '_', strtolower($name)),
            'role' => $roleName,
        ]);

        $user->roles()->attach($role);
        $user->load('roles');

        return $user;
    }

    private function createPurchaseRequisition(?User $user, array $attributes = []): PurchaseRequisition
    {
        return PurchaseRequisition::create(array_merge([
            'user_id' => $user?->id,
            'creator_name' => 'Snapshot Creator',
            'trans_date' => '2026-08-24',
            'requisition_type' => 'PURCHASE',
            'description' => 'Outlet A',
            'branch_name' => 'Kantor Pusat',
            'status' => 'draft',
            'sync_status' => 'pending',
            'created_at' => '2026-08-24 09:00:00',
            'updated_at' => '2026-08-24 09:00:00',
        ], $attributes));
    }

    private function createItem(PurchaseRequisition $record, string $name, string $quantity, string $unit, string $totalPrice, array $attributes = []): void
    {
        $record->items()->create(array_merge([
            'item_accurate_id' => 790,
            'item_no' => '100069',
            'item_name' => $name,
            'item_unit_accurate_id' => 50,
            'item_unit_name' => $unit,
            'quantity' => $quantity,
            'required_date' => '2026-08-25',
            'note' => null,
            'latest_purchase_unit_price' => '75000.00000000',
            'total_price' => $totalPrice,
        ], $attributes));
    }
}
