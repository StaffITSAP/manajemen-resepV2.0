<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseRequisitionResource;
use App\Filament\Resources\PurchaseRequisitionResource\Pages\CreatePurchaseRequisition;
use App\Models\AccurateBranch;
use App\Models\AccurateItem;
use App\Models\AccurateItemUnit;
use App\Models\PurchaseItemLatestPrice;
use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Services\PurchaseRequisitions\Accurate\PurchaseRequisitionSender;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class CreatePurchaseRequisitionAutoSendTest extends TestCase
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
        Schema::dropIfExists('notifications');
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

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
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

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::create([
            'name' => 'Tester',
            'email' => 'tester@example.com',
            'password' => 'secret',
            'username' => 'tester',
            'role' => 'superadmin',
        ]);

        $this->actingAs($user);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('purchase_requisition_items');
        Schema::dropIfExists('purchase_requisitions');
        Schema::dropIfExists('purchase_item_latest_prices');
        Schema::dropIfExists('accurate_item_units');
        Schema::dropIfExists('accurate_items');
        Schema::dropIfExists('accurate_branches');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_create_page_saves_local_record_without_invoking_sender_and_redirects_to_view(): void
    {
        $item = $this->seedLocalDependencies();

        $sender = Mockery::mock(PurchaseRequisitionSender::class);
        $sender->shouldReceive('sendDraft')->never();
        $this->app->instance(PurchaseRequisitionSender::class, $sender);

        Livewire::test(CreatePurchaseRequisition::class)
            ->set('data', $this->formData($item))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(PurchaseRequisitionResource::getUrl('view', ['record' => 1]))
            ->assertNotified(Notification::make()
                ->success()
                ->title('Permintaan Barang berhasil disubmit')
                ->body('Permintaan Barang menunggu approval SPV sebelum dikirim ke Accurate.'));

        $record = PurchaseRequisition::query()->with('items')->firstOrFail();
        $this->assertSame('submitted', $record->status);
        $this->assertSame('pending', $record->sync_status);
        $this->assertNull($record->accurate_number);
        $this->assertNull($record->accurate_status);
    }

    public function test_submit_does_not_attempt_definite_sender_failure_path(): void
    {
        $item = $this->seedLocalDependencies();

        $sender = Mockery::mock(PurchaseRequisitionSender::class);
        $sender->shouldReceive('sendDraft')->never();
        $this->app->instance(PurchaseRequisitionSender::class, $sender);

        Livewire::test(CreatePurchaseRequisition::class)
            ->set('data', $this->formData($item))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(PurchaseRequisitionResource::getUrl('view', ['record' => 1]))
            ->assertNotified(Notification::make()
                ->success()
                ->title('Permintaan Barang berhasil disubmit')
                ->body('Permintaan Barang menunggu approval SPV sebelum dikirim ke Accurate.'));

        $record = PurchaseRequisition::query()->with('items')->firstOrFail();
        $this->assertSame('submitted', $record->status);
        $this->assertSame('pending', $record->sync_status);
        $this->assertSame(1, PurchaseRequisition::count());
        $this->assertSame(1, $record->items->count());
    }

    public function test_submit_does_not_attempt_ambiguous_sender_path(): void
    {
        $item = $this->seedLocalDependencies();

        $sender = Mockery::mock(PurchaseRequisitionSender::class);
        $sender->shouldReceive('sendDraft')->never();
        $this->app->instance(PurchaseRequisitionSender::class, $sender);

        Livewire::test(CreatePurchaseRequisition::class)
            ->set('data', $this->formData($item))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(PurchaseRequisitionResource::getUrl('view', ['record' => 1]))
            ->assertNotified(Notification::make()
                ->success()
                ->title('Permintaan Barang berhasil disubmit')
                ->body('Permintaan Barang menunggu approval SPV sebelum dikirim ke Accurate.'));
    }

    public function test_submit_does_not_resolve_sender_or_log_send_exception(): void
    {
        $item = $this->seedLocalDependencies();

        Log::spy();

        $sender = Mockery::mock(PurchaseRequisitionSender::class);
        $sender->shouldReceive('sendDraft')->never();
        $this->app->instance(PurchaseRequisitionSender::class, $sender);

        Livewire::test(CreatePurchaseRequisition::class)
            ->set('data', $this->formData($item))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(PurchaseRequisitionResource::getUrl('view', ['record' => 1]))
            ->assertNotified(Notification::make()
                ->success()
                ->title('Permintaan Barang berhasil disubmit')
                ->body('Permintaan Barang menunggu approval SPV sebelum dikirim ke Accurate.'));

        $record = PurchaseRequisition::query()->with('items')->firstOrFail();
        $this->assertSame('pending', $record->sync_status);
        $this->assertSame(1, $record->items->count());

        Log::shouldNotHaveReceived('error');
    }

    public function test_create_page_renders_submit_label_without_exposing_credentials(): void
    {
        $this->seedLocalDependencies();

        Livewire::test(CreatePurchaseRequisition::class)
            ->assertSee('Submit')
            ->assertDontSee('Simpan & Kirim')
            ->assertDontSee('Bearer')
            ->assertDontSee('X-Api-AppKey')
            ->assertDontSee('test-token');
    }

    private function seedLocalDependencies(): AccurateItem
    {
        AccurateBranch::create([
            'accurate_id' => 50,
            'name' => 'Kantor Pusat',
        ]);

        $item = AccurateItem::create([
            'accurate_id' => 100229,
            'no' => '1002.29',
            'name' => 'Ajinomoto',
            'raw' => [],
        ]);

        AccurateItemUnit::create([
            'accurate_item_id' => $item->id,
            'item_accurate_id' => 100229,
            'item_no' => '1002.29',
            'item_name' => 'Ajinomoto',
            'item_unit_accurate_id' => 1,
            'item_unit_name' => 'grm',
            'position' => 1,
            'source' => 'accurate_item_detail',
        ]);

        PurchaseItemLatestPrice::create([
            'accurate_item_id' => $item->id,
            'item_accurate_id' => 100229,
            'item_no' => '1002.29',
            'item_name' => 'Ajinomoto',
            'item_unit_accurate_id' => 1,
            'item_unit_name' => 'grm',
            'unit_price' => '5000.00000000',
            'purchase_order_accurate_id' => 8001,
            'purchase_order_number' => 'PO.2026.08.00170',
            'purchase_order_date' => '2026-08-20',
            'purchase_order_detail_id' => 10,
        ]);

        return $item;
    }

    private function formData(AccurateItem $item): array
    {
        return [
            'trans_date' => '2026-08-24',
            'description' => 'Outlet A',
            'items' => [[
                'accurate_item_id' => $item->id,
                'item_unit_accurate_id' => 1,
                'quantity' => '1.000000',
                'required_date' => '2026-08-27',
                'note' => 'Tes web',
            ]],
        ];
    }
}
