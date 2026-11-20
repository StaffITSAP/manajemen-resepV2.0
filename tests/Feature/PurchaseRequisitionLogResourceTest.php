<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseRequisitionLogResource;
use App\Filament\Resources\PurchaseRequisitionLogResource\Pages\ListPurchaseRequisitionLogs;
use App\Filament\Resources\PurchaseRequisitionResource;
use App\Models\PurchaseRequisition;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
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
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
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
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
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
            ->assertTableActionExists('detail', fn (Action $action): bool =>
                $action->getModalHeading() === 'Detail Permintaan Barang - PR.2026.00002'
                && $action->getModalContent() instanceof \Illuminate\Contracts\Support\Htmlable
            , $record);

        Livewire::test(ListPurchaseRequisitionLogs::class)
            ->mountTableAction('detail', $record)
            ->assertActionMounted(TestAction::make('detail')->table($record));

        $detail = view('filament.components.purchase-requisition-log-detail', [
            'record' => $record->fresh()->load(['rejecter', 'user', 'items']),
        ])->render();

        foreach (['Snapshot Creator', 'PR.2026.00002', '12345', 'Berhasil', 'Alchemy 200gr', '100069', 'Butuh cepat', 'PO.2026.08.00170'] as $text) {
            $this->assertStringContainsString($text, $detail);
        }

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
        $this->assertArrayHasKey('summaryItems', $query->getEagerLoads());
        $this->assertArrayHasKey('latestActivityLog.user', $query->getEagerLoads());
        $this->assertArrayNotHasKey('items', $query->getEagerLoads());
        $this->assertArrayNotHasKey('activityLogs', $query->getEagerLoads());

        $sql = $query->toSql();
        $this->assertStringContainsString('items_count', $sql);
        $this->assertStringContainsString('items_total_price', $sql);
    }

    public function test_list_queries_load_item_previews_and_latest_activity_only(): void
    {
        $this->actingAs($this->createUser('superadmin', 'Performance Admin'));
        $records = [];

        for ($recordIndex = 1; $recordIndex <= 3; $recordIndex++) {
            $record = $this->createPurchaseRequisition(null, ['description' => "Outlet {$recordIndex}"]);
            $records[] = $record;

            for ($itemIndex = 1; $itemIndex <= 25; $itemIndex++) {
                $this->createItem($record, "Item {$itemIndex}", '1.000000', 'pcs', '100.00000000');
            }

            for ($activityIndex = 1; $activityIndex <= 40; $activityIndex++) {
                $record->activityLogs()->create([
                    'action' => "edit-{$activityIndex}",
                    'summary' => "Activity {$activityIndex}",
                    'created_at' => now()->addSeconds($activityIndex),
                    'updated_at' => now()->addSeconds($activityIndex),
                ]);
            }
        }

        DB::enableQueryLog();
        $baselineNormalListRecords = PurchaseRequisition::query()
            ->visibleTo(auth()->user())
            ->with(['approver', 'branch', 'rejecter', 'user', 'items'])
            ->limit(3)
            ->get();
        $baselineNormalListQueries = DB::getQueryLog();
        DB::flushQueryLog();

        $normalListRecords = PurchaseRequisitionResource::getEloquentQuery()->limit(3)->get();
        $normalListQueries = DB::getQueryLog();
        DB::flushQueryLog();

        $baselineLogListRecords = PurchaseRequisition::query()
            ->visibleTo(auth()->user())
            ->with(['user', 'items'])
            ->with(['activityLogs' => fn ($query) => $query->latest()->with('user')])
            ->withCount('items')
            ->withSum('items as items_total_price', 'total_price')
            ->limit(3)
            ->get();
        $baselineLogListQueries = DB::getQueryLog();
        DB::flushQueryLog();

        $logListRecords = PurchaseRequisitionLogResource::getEloquentQuery()->limit(3)->get();
        $logListQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $baselineNormalItemLoads = array_filter($baselineNormalListQueries, fn (array $query): bool =>
            str_starts_with($query['query'], 'select * from "purchase_requisition_items"')
        );
        $itemLoadsForNormalList = array_filter($normalListQueries, fn (array $query): bool =>
            str_contains($query['query'], 'row_number() over (partition by "purchase_requisition_items"')
        );
        $baselineLogItemLoads = array_filter($baselineLogListQueries, fn (array $query): bool =>
            str_starts_with($query['query'], 'select * from "purchase_requisition_items"')
        );
        $itemLoadsForLogList = array_filter($logListQueries, fn (array $query): bool =>
            str_contains($query['query'], 'row_number() over (partition by "purchase_requisition_items"')
        );
        $baselineActivityLoadsForLogList = array_filter($baselineLogListQueries, fn (array $query): bool =>
            str_starts_with($query['query'], 'select * from "purchase_requisition_activity_logs"')
        );
        $activityLoadsForLogList = array_filter($logListQueries, fn (array $query): bool =>
            str_starts_with($query['query'], 'select "purchase_requisition_activity_logs".*')
        );

        $this->assertCount(1, $baselineNormalItemLoads);
        $this->assertCount(1, $itemLoadsForNormalList);
        $this->assertCount(1, $baselineLogItemLoads);
        $this->assertCount(1, $itemLoadsForLogList);
        $this->assertCount(1, $baselineActivityLoadsForLogList);
        $this->assertCount(1, $activityLoadsForLogList);
        $this->assertSame(75, $baselineNormalListRecords->sum(fn (PurchaseRequisition $record): int => $record->items->count()));
        $this->assertSame(6, $normalListRecords->sum(fn (PurchaseRequisition $record): int => $record->summaryItems->count()));
        $this->assertSame(75, $baselineLogListRecords->sum(fn (PurchaseRequisition $record): int => $record->items->count()));
        $this->assertSame(6, $logListRecords->sum(fn (PurchaseRequisition $record): int => $record->summaryItems->count()));
        $this->assertSame(75, $normalListRecords->sum('items_count'));
        $this->assertSame(75, $logListRecords->sum('items_count'));
        $this->assertSame(120, $baselineLogListRecords->sum(fn (PurchaseRequisition $record): int => $record->activityLogs->count()));
        $this->assertSame(3, $logListRecords->filter(fn (PurchaseRequisition $record): bool => $record->latestActivityLog !== null)->count());
        $this->assertSame('edit-40', $logListRecords->first()->latestActivityLog->action);
        $this->assertStringContainsString('edit-40', PurchaseRequisitionLogResource::lastEditSummary($logListRecords->first()));
        $this->assertStringNotContainsString('edit-1', PurchaseRequisitionLogResource::lastEditSummary($logListRecords->first()));
        $this->assertFalse($logListRecords->first()->relationLoaded('items'));
        $this->assertFalse($logListRecords->first()->relationLoaded('activityLogs'));
        $this->assertSame(2500.0, (float) $normalListRecords->first()->items_total_price);
        $this->assertSame(2500.0, (float) $logListRecords->first()->items_total_price);
        $this->assertStringContainsString('+23 barang lainnya', PurchaseRequisitionLogResource::itemSummary($logListRecords->first()));

        $summaryMethod = new \ReflectionMethod(PurchaseRequisitionResource::class, 'requestSummary');
        $this->assertStringContainsString('+ 23 barang lainnya', $summaryMethod->invoke(null, $normalListRecords->first()));

        $withoutHistory = $this->createPurchaseRequisition(null, ['description' => 'No history']);
        $historyHtml = null;
        $detailHtml = null;

        Livewire::test(ListPurchaseRequisitionLogs::class)
            ->assertTableActionVisible('editHistory', $records[0])
            ->assertTableActionHidden('editHistory', $withoutHistory)
            ->assertTableActionExists('editHistory', function (Action $action) use (&$historyHtml): bool {
                $content = $action->getModalContent();
                $historyHtml = $content?->render();

                return $content instanceof \Illuminate\Contracts\Support\Htmlable;
            }, $records[0])
            ->assertTableActionExists('detail', function (Action $action) use (&$detailHtml): bool {
                $content = $action->getModalContent();
                $detailHtml = $content?->render();

                return $content instanceof \Illuminate\Contracts\Support\Htmlable;
            }, $records[0]);

        $this->assertStringContainsString('Activity 1', $historyHtml);
        $this->assertStringContainsString('Activity 40', $historyHtml);
        $this->assertLessThan(strpos($historyHtml, 'Activity 1'), strpos($historyHtml, 'Activity 40'));
        $this->assertStringContainsString('Item 25', $detailHtml);
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
