<?php

namespace Tests\Feature;

use App\Models\AccurateBranch;
use App\Models\AccurateItem;
use App\Models\PurchaseRequisition;
use App\Services\PurchaseRequisitions\Accurate\PurchaseRequisitionSender;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class SendPurchaseRequisitionCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('purchase_requisition_items');
        Schema::dropIfExists('purchase_requisitions');
        Schema::dropIfExists('accurate_items');
        Schema::dropIfExists('accurate_branches');

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

        Schema::create('purchase_requisitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->date('trans_date')->nullable();
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
            $table->string('item_no')->nullable();
            $table->string('item_name')->nullable();
            $table->unsignedBigInteger('item_unit_accurate_id');
            $table->string('item_unit_name')->nullable();
            $table->decimal('quantity', 24, 6)->default(0);
            $table->date('required_date')->nullable();
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
        Schema::dropIfExists('accurate_items');
        Schema::dropIfExists('accurate_branches');

        parent::tearDown();
    }

    public function test_missing_local_id_fails_safely(): void
    {
        $sender = Mockery::mock(PurchaseRequisitionSender::class);
        $sender->shouldReceive('sendDraft')->never();
        $this->app->instance(PurchaseRequisitionSender::class, $sender);

        $this->artisan('accurate:send-purchase-requisition', ['localId' => 999])
            ->expectsOutputToContain('Permintaan Barang lokal tidak ditemukan.')
            ->assertFailed();
    }

    public function test_user_declining_confirmation_does_not_call_sender(): void
    {
        $record = $this->requisition();
        $this->addItem($record);

        $sender = Mockery::mock(PurchaseRequisitionSender::class);
        $sender->shouldReceive('sendDraft')->never();
        $this->app->instance(PurchaseRequisitionSender::class, $sender);

        $this->artisan('accurate:send-purchase-requisition', ['localId' => $record->id])
            ->expectsQuestion('This will create ONE Purchase Requisition DRAFT in Accurate.', false)
            ->expectsOutputToContain('Pengiriman dibatalkan. Tidak ada data yang dikirim.')
            ->assertSuccessful();
    }

    public function test_user_confirming_calls_sender_exactly_once(): void
    {
        $record = $this->requisition();
        $this->addItem($record);
        $updated = $record->replicate()->fill([
            'id' => $record->id,
            'sync_status' => 'synced',
            'accurate_id' => 9001,
            'accurate_number' => 'PR.2026.08.00001',
            'accurate_status' => 'Draft',
        ]);
        $updated->exists = true;

        $sender = Mockery::mock(PurchaseRequisitionSender::class);
        $sender->shouldReceive('sendDraft')->once()->withArgs(fn($arg) => $arg instanceof PurchaseRequisition && $arg->id === $record->id)->andReturn($updated);
        $this->app->instance(PurchaseRequisitionSender::class, $sender);

        $this->artisan('accurate:send-purchase-requisition', ['localId' => $record->id])
            ->expectsQuestion('This will create ONE Purchase Requisition DRAFT in Accurate.', true)
            ->expectsOutputToContain('Pengiriman berhasil.')
            ->assertSuccessful();
    }

    public function test_already_synced_record_is_not_resent(): void
    {
        $record = $this->requisition([
            'sync_status' => 'synced',
            'accurate_id' => 9001,
            'accurate_number' => 'PR.1',
        ]);
        $this->addItem($record);

        $sender = Mockery::mock(PurchaseRequisitionSender::class);
        $sender->shouldReceive('sendDraft')->once()->andThrow(new \RuntimeException('Permintaan Barang sudah tersinkron ke Accurate.'));
        $this->app->instance(PurchaseRequisitionSender::class, $sender);

        $this->artisan('accurate:send-purchase-requisition', ['localId' => $record->id])
            ->expectsQuestion('This will create ONE Purchase Requisition DRAFT in Accurate.', true)
            ->expectsOutputToContain('Permintaan Barang sudah tersinkron ke Accurate.')
            ->assertFailed();
    }

    public function test_ambiguous_record_is_not_resent(): void
    {
        $record = $this->requisition([
            'sync_status' => 'failed',
            'error_message' => 'AMBIGUOUS_REVIEW_REQUIRED: hasil pengiriman ke Accurate tidak pasti; jangan kirim ulang otomatis.',
        ]);
        $this->addItem($record);

        $sender = Mockery::mock(PurchaseRequisitionSender::class);
        $sender->shouldReceive('sendDraft')->once()->andThrow(new \RuntimeException('Permintaan Barang memerlukan review operator karena hasil kirim sebelumnya ambigu.'));
        $this->app->instance(PurchaseRequisitionSender::class, $sender);

        $this->artisan('accurate:send-purchase-requisition', ['localId' => $record->id])
            ->expectsQuestion('This will create ONE Purchase Requisition DRAFT in Accurate.', true)
            ->expectsOutputToContain('REVIEW REQUIRED')
            ->expectsOutputToContain('DO NOT RESEND AUTOMATICALLY')
            ->assertFailed();
    }

    public function test_successful_result_is_displayed_safely_without_credentials(): void
    {
        $record = $this->requisition(['description' => 'test']);
        $this->addItem($record);
        $updated = $record->replicate()->fill([
            'id' => $record->id,
            'sync_status' => 'synced',
            'accurate_id' => 9001,
            'accurate_number' => 'PR.2026.08.00001',
            'accurate_status' => 'Draft',
        ]);
        $updated->exists = true;

        $sender = Mockery::mock(PurchaseRequisitionSender::class);
        $sender->shouldReceive('sendDraft')->once()->andReturn($updated);
        $this->app->instance(PurchaseRequisitionSender::class, $sender);

        $this->artisan('accurate:send-purchase-requisition', ['localId' => $record->id])
            ->expectsQuestion('This will create ONE Purchase Requisition DRAFT in Accurate.', true)
            ->expectsOutputToContain('Local ID: ' . $record->id)
            ->expectsOutputToContain('Sync Result: synced')
            ->expectsOutputToContain('Accurate ID: 9001')
            ->expectsOutputToContain('Accurate Number: PR.2026.08.00001')
            ->expectsOutputToContain('Accurate Status: Draft')
            ->doesntExpectOutputToContain('Authorization')
            ->doesntExpectOutputToContain('Bearer')
            ->doesntExpectOutputToContain('X-Api-AppKey')
            ->doesntExpectOutputToContain('test-token')
            ->assertSuccessful();
    }

    private function requisition(array $overrides = []): PurchaseRequisition
    {
        $branch = AccurateBranch::firstOrCreate(['accurate_id' => 50], ['name' => 'Kantor Pusat']);

        return PurchaseRequisition::create(array_merge([
            'trans_date' => '2026-08-22',
            'requisition_type' => 'PURCHASE',
            'description' => 'Outlet A',
            'accurate_branch_id' => $branch->id,
            'branch_accurate_id' => 50,
            'branch_name' => 'Kantor Pusat',
            'status' => 'draft',
            'sync_status' => 'pending',
        ], $overrides));
    }

    private function addItem(PurchaseRequisition $record, array $overrides = []): void
    {
        $item = AccurateItem::firstOrCreate(
            ['accurate_id' => $overrides['item_accurate_id'] ?? 790],
            ['no' => $overrides['item_no'] ?? '100069', 'name' => $overrides['item_name'] ?? 'Alchemy 200gr']
        );

        $record->items()->create(array_merge([
            'accurate_item_id' => $item->id,
            'item_accurate_id' => 790,
            'item_no' => '100069',
            'item_name' => 'Alchemy 200gr',
            'item_unit_accurate_id' => 50,
            'item_unit_name' => 'pcs',
            'quantity' => '2.000000',
            'required_date' => '2026-08-24',
            'latest_purchase_unit_price' => '75000.00000000',
            'total_price' => '150000.00000000',
        ], $overrides));
    }
}
