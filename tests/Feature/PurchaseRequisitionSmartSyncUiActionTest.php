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
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class PurchaseRequisitionSmartSyncUiActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('purchase_requisition_items');
        Schema::dropIfExists('purchase_requisitions');
        Schema::dropIfExists('accurate_branches');
        Schema::dropIfExists('user_role');
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
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

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
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
            $table->unsignedBigInteger('user_id')->nullable();
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
            $table->decimal('latest_purchase_unit_price', 24, 8)->default(0);
            $table->decimal('total_price', 24, 8)->default(0);
            $table->timestamps();
        });

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        Cache::lock(PurchaseRequisitionSmartSync::LOCK_KEY, 1)->forceRelease();
        Cache::forget(PurchaseRequisitionSmartSync::STATUS_KEY);

        Schema::dropIfExists('purchase_requisition_items');
        Schema::dropIfExists('purchase_requisitions');
        Schema::dropIfExists('accurate_branches');
        Schema::dropIfExists('user_role');
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_superadmin_can_see_smart_sync_action_and_existing_create_action_remains(): void
    {
        $this->actingAs($this->createUser('superadmin'));

        Livewire::test(ListPurchaseRequisitions::class)
            ->assertActionVisible('smartSync')
            ->assertActionHasLabel('smartSync', 'Sinkron Data Permintaan Barang')
            ->assertActionEnabled('smartSync')
            ->assertActionVisible('create')
            ->assertActionHasLabel('create', 'Buat Permintaan Barang');

        $this->assertPurchaseRequisitionTableLayoutStillDeclared();
    }

    public function test_normal_user_cannot_see_smart_sync_action(): void
    {
        $this->actingAs($this->createUser('staff'));

        Livewire::test(ListPurchaseRequisitions::class)
            ->assertActionHidden('smartSync')
            ->assertDontSee('Sinkron Data Permintaan Barang')
            ->assertActionVisible('create')
            ->assertActionHasLabel('create', 'Buat Permintaan Barang');
    }

    public function test_confirmation_decline_does_not_call_coordinator(): void
    {
        $this->actingAs($this->createUser('superadmin'));

        $smartSync = Mockery::mock(PurchaseRequisitionSmartSync::class);
        $smartSync->shouldNotReceive('start');
        $smartSync->shouldReceive('isRunning')->andReturnFalse();
        $this->app->instance(PurchaseRequisitionSmartSync::class, $smartSync);

        Livewire::test(ListPurchaseRequisitions::class)
            ->mountAction('smartSync')
            ->assertSee('Sinkron Data Permintaan Barang')
            ->assertSee('Sistem akan memperbarui satuan barang dan harga pembelian terakhir dari Accurate secara bertahap.')
            ->assertSee('Proses akan berjalan di background.')
            ->assertSee('Mulai Sinkronisasi')
            ->assertSee('Batal')
            ->unmountAction();
    }

    public function test_confirmation_calls_coordinator_once_and_started_result_notifies_without_accurate_client(): void
    {
        $this->actingAs($this->createUser('superadmin'));
        Queue::fake();

        $accurateClient = Mockery::mock(AccurateClient::class);
        $accurateClient->shouldNotReceive('detailItemById');
        $accurateClient->shouldNotReceive('listPurchaseOrders');
        $accurateClient->shouldNotReceive('detailPurchaseOrder');
        $accurateClient->shouldNotReceive('purchaseRequisitionSaveDraft');
        $this->app->instance(AccurateClient::class, $accurateClient);

        $smartSync = Mockery::mock(PurchaseRequisitionSmartSync::class);
        $smartSync->shouldReceive('isRunning')->andReturnFalse();
        $smartSync->shouldReceive('start')
            ->once()
            ->andReturn(['status' => 'started', 'lock_owner' => 'owner-1']);
        $this->app->instance(PurchaseRequisitionSmartSync::class, $smartSync);

        Livewire::test(ListPurchaseRequisitions::class)
            ->callAction('smartSync')
            ->assertNotified(Notification::make()
                ->title('Sinkronisasi dimulai.')
                ->body('Data satuan barang dan harga pembelian akan diperbarui secara bertahap di background.')
                ->success());

        $this->assertSame(0, DB::table('permissions')->count());
        Queue::assertNothingPushed();
    }

    public function test_already_running_result_does_not_dispatch_duplicate_workflow_and_notifies(): void
    {
        $this->actingAs($this->createUser('superadmin'));
        Queue::fake();

        $lock = Cache::lock(PurchaseRequisitionSmartSync::LOCK_KEY, 21600);
        $this->assertTrue($lock->get());
        Cache::forget(PurchaseRequisitionSmartSync::STATUS_KEY);

        Livewire::test(ListPurchaseRequisitions::class)
            ->callAction('smartSync')
            ->assertNotified(Notification::make()
                ->title('Sinkronisasi sedang berjalan')
                ->body('Sinkronisasi Data Permintaan Barang masih berlangsung. Tunggu hingga proses selesai.')
                ->warning());

        Queue::assertNothingPushed();
    }

    public function test_running_server_state_disables_smart_sync_action_with_running_label(): void
    {
        $this->actingAs($this->createUser('superadmin'));

        PurchaseRequisitionSmartSync::markRunning('owner-1');

        Livewire::test(ListPurchaseRequisitions::class)
            ->assertActionVisible('smartSync')
            ->assertActionDisabled('smartSync')
            ->assertActionHasLabel('smartSync', 'Sinkronisasi sedang berjalan...');
    }

    public function test_running_state_persists_across_fresh_component_render_and_releases_to_idle(): void
    {
        $this->actingAs($this->createUser('superadmin'));

        PurchaseRequisitionSmartSync::markRunning('owner-1');

        Livewire::test(ListPurchaseRequisitions::class)
            ->assertActionDisabled('smartSync')
            ->assertActionHasLabel('smartSync', 'Sinkronisasi sedang berjalan...');

        PurchaseRequisitionSmartSync::clearRunning('owner-1');

        Livewire::test(ListPurchaseRequisitions::class)
            ->assertActionEnabled('smartSync')
            ->assertActionHasLabel('smartSync', 'Sinkron Data Permintaan Barang');
    }

    public function test_table_approve_action_sends_submitted_pending_requisition_to_accurate(): void
    {
        $this->actingAs($this->createUser('superadmin'));

        $record = $this->createSubmittedRequisition();

        $sender = Mockery::mock(PurchaseRequisitionSender::class);
        $sender->shouldReceive('sendDraft')
            ->once()
            ->withArgs(fn(PurchaseRequisition $arg): bool => $arg->is($record))
            ->andReturnUsing(function (PurchaseRequisition $record): PurchaseRequisition {
                $record->update([
                    'sync_status' => 'synced',
                    'accurate_id' => 102200,
                    'accurate_number' => 'DFT.26745',
                    'accurate_status' => 'DRAFT',
                    'error_message' => null,
                    'synced_at' => now(),
                ]);

                return $record->fresh(['items']);
            });
        $this->app->instance(PurchaseRequisitionSender::class, $sender);

        Livewire::test(ListPurchaseRequisitions::class)
            ->assertTableActionVisible('approve', $record)
            ->callTableAction('approve', $record)
            ->assertHasNoTableActionErrors()
            ->assertNotified(Notification::make()
                ->success()
                ->title('Permintaan Barang berhasil di-approve dan dikirim ke Accurate.')
                ->body("Nomor Accurate: DFT.26745\nStatus Accurate: DRAFT"));

        $this->assertSame('synced', $record->fresh()->sync_status);
    }

    public function test_list_page_does_not_reference_accurate_client_or_remote_write_paths(): void
    {
        $contents = file_get_contents(app_path('Filament/Resources/PurchaseRequisitionResource/Pages/ListPurchaseRequisitions.php'));

        $this->assertStringNotContainsString('AccurateClient', $contents);
        $this->assertStringNotContainsString('detailItemById', $contents);
        $this->assertStringNotContainsString('listPurchaseOrders', $contents);
        $this->assertStringNotContainsString('detailPurchaseOrder', $contents);
        $this->assertStringNotContainsString('purchaseRequisitionSaveDraft', $contents);
        $this->assertStringNotContainsString('save.do', $contents);
    }

    private function createUser(string $roleName): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => $roleName],
            ['description' => ucfirst($roleName)],
        );

        $user = User::create([
            'name' => ucfirst($roleName),
            'email' => "{$roleName}@example.com",
            'password' => 'secret',
            'username' => $roleName,
            'role' => $roleName,
        ]);

        $user->roles()->attach($role);

        if ($roleName === 'staff') {
            $permissions = collect([
                ['name' => 'view_purchase_requisition', 'description' => 'View Permintaan Barang'],
                ['name' => 'create_purchase_requisition', 'description' => 'Create Permintaan Barang'],
            ])->map(fn(array $permission): Permission => Permission::query()->firstOrCreate(
                ['name' => $permission['name']],
                $permission,
            ));

            $role->permissions()->syncWithoutDetaching($permissions->pluck('id'));
        }

        $user->load('roles');

        return $user;
    }

    private function createSubmittedRequisition(): PurchaseRequisition
    {
        $branch = AccurateBranch::query()->create([
            'accurate_id' => 50,
            'name' => 'Kantor Pusat',
        ]);

        $record = PurchaseRequisition::query()->create([
            'trans_date' => '2026-08-22',
            'requisition_type' => 'PURCHASE',
            'description' => 'Outlet A',
            'accurate_branch_id' => $branch->id,
            'branch_accurate_id' => 50,
            'branch_name' => 'Kantor Pusat',
            'status' => 'submitted',
            'sync_status' => 'pending',
        ]);

        $record->items()->create([
            'item_accurate_id' => 790,
            'item_no' => '100069',
            'item_name' => 'Alchemy 200gr',
            'item_unit_accurate_id' => 50,
            'item_unit_name' => 'pcs',
            'quantity' => '2.000000',
            'required_date' => '2026-08-24',
            'latest_purchase_unit_price' => '75000.00000000',
            'total_price' => '150000.00000000',
        ]);

        return $record->fresh(['items']);
    }

    private function assertPurchaseRequisitionTableLayoutStillDeclared(): void
    {
        $contents = file_get_contents(app_path('Filament/Resources/PurchaseRequisitionResource.php'));

        $this->assertStringContainsString("TextColumn::make('trans_date')", $contents);
        $this->assertStringContainsString("->label('Tanggal')", $contents);
        $this->assertStringContainsString("TextColumn::make('description')", $contents);
        $this->assertStringContainsString("->label('Divisi Outlet')", $contents);
        $this->assertStringContainsString("TextColumn::make('branch_name')", $contents);
        $this->assertStringContainsString("->label('Cabang')", $contents);
        $this->assertStringContainsString("TextColumn::make('request_summary')", $contents);
        $this->assertStringContainsString("->label('Ringkasan Permintaan')", $contents);
        $this->assertStringContainsString("TextColumn::make('estimated_total')", $contents);
        $this->assertStringContainsString("->label('Nilai Estimasi')", $contents);
        $this->assertStringContainsString("TextColumn::make('status_summary')", $contents);
        $this->assertStringContainsString('Tables\Actions\ViewAction::make()', $contents);
        $this->assertStringContainsString("'create' => Pages\CreatePurchaseRequisition::route('/create')", $contents);
    }
}
