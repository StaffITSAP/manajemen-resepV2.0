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
use Illuminate\Support\Facades\DB;
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
        Schema::dropIfExists('purchase_item_cost_values');
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
            $table->boolean('is_active')->default(true);
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

        Schema::create('purchase_item_cost_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('accurate_item_id')->nullable();
            $table->unsignedBigInteger('item_accurate_id');
            $table->unsignedBigInteger('item_unit_accurate_id');
            $table->decimal('unit_price', 24, 8)->default(0);
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
        Schema::dropIfExists('purchase_item_cost_values');
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

    public function test_reactive_quantity_preserves_large_whole_number_through_submission(): void
    {
        $item = $this->seedLocalDependencies();
        $data = $this->formData($item);
        $data['items'][0]['quantity'] = '1200';

        $form = Livewire::test(CreatePurchaseRequisition::class)
            ->set('data', $data)
            ->set('data.items.0.item_unit_accurate_id', null)
            ->set('data.items.0.item_unit_accurate_id', 1)
            ->set('data.items.0.quantity', '1200');

        $this->assertMatchesRegularExpression(
            '/<input\b(?=[^>]*wire:model\.live\.blur="data\.items\.0\.quantity")(?=[^>]*type="text")(?=[^>]*inputmode="decimal")[^>]*>/s',
            $form->html(),
        );
        $this->assertSame('1200', $form->get('data.items.0.quantity'));
        $this->assertSame('6000000.00000000', $form->get('data.items.0.total_price'));

        $form->call('create')->assertHasNoFormErrors();

        $this->assertSame('1200.000000', PurchaseRequisition::query()->firstOrFail()->items()->firstOrFail()->quantity);
    }

    public function test_reactive_decimal_quantities_and_repeater_rows_keep_their_precision(): void
    {
        $item = $this->seedLocalDependencies();
        PurchaseItemLatestPrice::query()->firstOrFail()->update(['unit_price' => '1.23456789']);
        $data = $this->formData($item);
        $data['items'][0]['quantity'] = '1.234567';
        $data['items'][] = [...$data['items'][0], 'quantity' => '0.123456'];

        $form = Livewire::test(CreatePurchaseRequisition::class)
            ->set('data', $data)
            ->set('data.items.0.item_unit_accurate_id', null)
            ->set('data.items.0.item_unit_accurate_id', 1)
            ->set('data.items.1.item_unit_accurate_id', null)
            ->set('data.items.1.item_unit_accurate_id', 1)
            ->set('data.items.0.quantity', '1.234567')
            ->set('data.items.1.quantity', '0.123456');

        $this->assertSame('1.234567', $form->get('data.items.0.quantity'));
        $this->assertSame('0.123456', $form->get('data.items.1.quantity'));
        $this->assertSame('1.52415677', $form->get('data.items.0.total_price'));
        $this->assertSame('0.15241481', $form->get('data.items.1.total_price'));

        $form->call('create')->assertHasNoFormErrors();

        $quantities = PurchaseRequisition::query()->firstOrFail()->items()->orderBy('id')->pluck('quantity')->all();
        $this->assertSame(['1.234567', '0.123456'], $quantities);
        $totals = PurchaseRequisition::query()->firstOrFail()->items()->orderBy('id')->pluck('total_price')->all();
        $this->assertSame(['1.52415677', '0.15241481'], $totals);
    }

    public function test_item_and_unit_changes_resolve_price_again_and_submission_ignores_tampered_price_state(): void
    {
        $firstItem = $this->seedLocalDependencies();
        $secondItem = AccurateItem::create([
            'accurate_id' => 100230,
            'no' => '1002.30',
            'name' => 'Item Kedua',
            'raw' => [],
        ]);
        AccurateItemUnit::create([
            'accurate_item_id' => $secondItem->id,
            'item_accurate_id' => $secondItem->accurate_id,
            'item_no' => $secondItem->no,
            'item_name' => $secondItem->name,
            'item_unit_accurate_id' => 2,
            'item_unit_name' => 'box',
            'position' => 1,
            'source' => 'accurate_item_detail',
        ]);
        PurchaseItemLatestPrice::create([
            'accurate_item_id' => $secondItem->id,
            'item_accurate_id' => $secondItem->accurate_id,
            'item_no' => $secondItem->no,
            'item_name' => $secondItem->name,
            'item_unit_accurate_id' => 2,
            'item_unit_name' => 'box',
            'unit_price' => '2500.12500000',
            'purchase_order_accurate_id' => 8002,
            'purchase_order_number' => 'PI.2026.08.00002',
            'purchase_order_date' => '2026-08-21',
            'purchase_order_detail_id' => 11,
        ]);

        $data = $this->formData($firstItem);
        $data['items'][0]['quantity'] = '1200';
        $form = Livewire::test(CreatePurchaseRequisition::class)
            ->set('data', $data)
            ->set('data.items.0.accurate_item_id', $secondItem->id);

        $this->assertNull($form->get('data.items.0.item_unit_accurate_id'));
        $this->assertNull($form->get('data.items.0.latest_purchase_unit_price'));

        DB::enableQueryLog();
        DB::flushQueryLog();
        $form->set('data.items.0.item_unit_accurate_id', 2);
        $unitChangeQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $priceLookups = array_filter(
            $unitChangeQueries,
            fn (array $query): bool => str_starts_with($query['query'], 'select * from "purchase_item_latest_prices"'),
        );
        $this->assertCount(1, $priceLookups);
        $this->assertSame('2500.12500000', $form->get('data.items.0.latest_purchase_unit_price'));
        $this->assertSame('3000150.00000000', $form->get('data.items.0.total_price'));
        $this->assertSame('Rp 2.500,13', $form->get('data.items.0.latest_price_display'));
        $this->assertSame('Rp 3.000.150,00', $form->get('data.items.0.total_price_display'));
        $this->assertSame('1200', $form->get('data.items.0.quantity'));

        $form
            ->set('data.items.0.latest_purchase_unit_price', '0.00000001')
            ->set('data.items.0.total_price', '0.00000001')
            ->call('create')
            ->assertHasNoFormErrors();

        $savedItem = PurchaseRequisition::query()->firstOrFail()->items()->firstOrFail();
        $this->assertSame(100230, $savedItem->item_accurate_id);
        $this->assertSame(2, $savedItem->item_unit_accurate_id);
        $this->assertSame('1200.000000', $savedItem->quantity);
        $this->assertSame('2500.12500000', $savedItem->latest_purchase_unit_price);
        $this->assertSame('3000150.00000000', $savedItem->total_price);
        $this->assertSame('PI.2026.08.00002', $savedItem->source_purchase_order_number);
    }

    public function test_unit_change_uses_cost_value_fallback_when_purchase_invoice_is_outside_window(): void
    {
        $item = $this->seedLocalDependencies();
        PurchaseItemLatestPrice::query()->firstOrFail()->update(['purchase_order_date' => '2024-01-01']);
        \App\Models\PurchaseItemCostValue::query()->create([
            'accurate_item_id' => $item->id,
            'item_accurate_id' => $item->accurate_id,
            'item_unit_accurate_id' => 1,
            'unit_price' => '987.65432100',
        ]);
        $data = $this->formData($item);
        $data['items'][0]['quantity'] = '1.5';

        $form = Livewire::test(CreatePurchaseRequisition::class)
            ->set('data', $data)
            ->set('data.items.0.item_unit_accurate_id', null)
            ->set('data.items.0.item_unit_accurate_id', 1);

        $this->assertSame('987.65432100', $form->get('data.items.0.latest_purchase_unit_price'));
        $this->assertSame('1481.48148150', $form->get('data.items.0.total_price'));

        $form->call('create')->assertHasNoFormErrors();

        $savedItem = PurchaseRequisition::query()->firstOrFail()->items()->firstOrFail();
        $this->assertSame('987.65432100', $savedItem->latest_purchase_unit_price);
        $this->assertSame('1481.48148150', $savedItem->total_price);
    }

    public function test_quantity_updates_skip_unchanged_price_resolution_for_single_and_multiple_rows(): void
    {
        $item = $this->seedLocalDependencies();
        $singleRow = $this->formData($item);
        $multiRow = $singleRow;
        $multiRow['items'][] = $multiRow['items'][0];

        $measure = function (array $data): array {
            $form = Livewire::test(CreatePurchaseRequisition::class)->set('data', $data);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $form->set('data.items.0.quantity', '2.5');
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            return $queries;
        };

        $singleQueries = $measure($singleRow);
        $multipleQueries = $measure($multiRow);

        $priceQueries = fn (array $queries): array => array_filter(
            $queries,
            fn (array $query): bool => str_contains($query['query'], 'purchase_item_latest_prices')
                || str_contains($query['query'], 'purchase_item_cost_values'),
        );
        $itemFetchQueries = fn (array $queries): array => array_filter(
            $queries,
            fn (array $query): bool => str_starts_with($query['query'], 'select * from "accurate_items"'),
        );

        $this->assertSame(2, count($singleQueries));
        $this->assertSame(2, count($multipleQueries));
        $this->assertSame([], array_values($priceQueries($singleQueries)));
        $this->assertSame([], array_values($priceQueries($multipleQueries)));
        $this->assertSame([], array_values($itemFetchQueries($singleQueries)));
        $this->assertSame([], array_values($itemFetchQueries($multipleQueries)));
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
                'unit_options_available' => true,
                'item_unit_accurate_id' => 1,
                'quantity' => '1.000000',
                'required_date' => '2026-08-27',
                'note' => 'Tes web',
            ]],
        ];
    }
}
