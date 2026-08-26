<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseRequisitionResource\Pages\ViewPurchaseRequisition;
use App\Models\AccurateBranch;
use App\Models\AccurateItem;
use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Services\PurchaseRequisitions\Accurate\PurchaseRequisitionSender;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class PurchaseRequisitionRetryAccurateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('purchase_requisition_items');
        Schema::dropIfExists('purchase_requisitions');
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

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs(User::create([
            'name' => 'Tester',
            'email' => 'tester@example.com',
            'password' => 'secret',
            'username' => 'tester',
        ]));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('purchase_requisition_items');
        Schema::dropIfExists('purchase_requisitions');
        Schema::dropIfExists('accurate_items');
        Schema::dropIfExists('accurate_branches');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_retry_action_visible_only_for_definite_failed_record(): void
    {
        Livewire::test(ViewPurchaseRequisition::class, ['record' => $this->requisition(['sync_status' => 'failed', 'error_message' => 'Invalid unit'])->getRouteKey()])
            ->assertActionVisible('retryAccurate')
            ->assertActionHasLabel('retryAccurate', 'Kirim Ulang ke Accurate');
    }

    public function test_retry_action_hidden_for_pending_synced_accurate_identity_and_ambiguous_records(): void
    {
        $cases = [
            $this->requisition(['sync_status' => 'pending', 'error_message' => null]),
            $this->requisition(['sync_status' => 'synced', 'accurate_id' => 9001, 'accurate_number' => 'DFT.1', 'error_message' => null]),
            $this->requisition(['sync_status' => 'failed', 'accurate_id' => 9001, 'error_message' => 'Invalid unit']),
            $this->requisition(['sync_status' => 'failed', 'accurate_number' => 'DFT.1', 'error_message' => 'Invalid unit']),
            $this->requisition(['sync_status' => 'failed', 'error_message' => 'AMBIGUOUS_REVIEW_REQUIRED: hasil pengiriman tidak pasti.']),
        ];

        foreach ($cases as $record) {
            Livewire::test(ViewPurchaseRequisition::class, ['record' => $record->getRouteKey()])
                ->assertActionHidden('retryAccurate');
        }
    }

    public function test_view_displays_synced_draft_local_and_accurate_draft_statuses_separately(): void
    {
        $record = $this->requisition([
            'status' => 'draft',
            'sync_status' => 'synced',
            'accurate_status' => 'DRAFT',
            'accurate_id' => 102650,
            'accurate_number' => 'DFT.26870',
            'synced_at' => '2026-08-26 15:03:39',
        ]);

        Livewire::test(ViewPurchaseRequisition::class, ['record' => $record->getRouteKey()])
            ->assertSee('Draft Lokal')
            ->assertSee('Terkirim ke Accurate')
            ->assertSee('DRAFT')
            ->assertSee('DFT.26870')
            ->assertDontSee('Belum Dikirim ke Accurate');
    }

    public function test_view_displays_pending_sync_as_not_sent(): void
    {
        $record = $this->requisition([
            'sync_status' => 'pending',
            'accurate_status' => null,
            'accurate_id' => null,
            'accurate_number' => null,
        ]);

        Livewire::test(ViewPurchaseRequisition::class, ['record' => $record->getRouteKey()])
            ->assertSee('Draft Lokal')
            ->assertSee('Belum Dikirim ke Accurate')
            ->assertSee('-');
    }

    public function test_view_displays_definite_failed_sync_as_failed_send(): void
    {
        $record = $this->requisition([
            'sync_status' => 'failed',
            'accurate_status' => null,
            'accurate_id' => null,
            'accurate_number' => null,
            'error_message' => 'Invalid unit',
        ]);

        Livewire::test(ViewPurchaseRequisition::class, ['record' => $record->getRouteKey()])
            ->assertSee('Gagal Dikirim ke Accurate')
            ->assertDontSee('Terkirim ke Accurate');
    }

    public function test_view_displays_ambiguous_failed_sync_as_review_required(): void
    {
        $record = $this->requisition([
            'sync_status' => 'failed',
            'accurate_status' => null,
            'accurate_id' => null,
            'accurate_number' => null,
            'error_message' => 'AMBIGUOUS_REVIEW_REQUIRED: hasil pengiriman ke Accurate tidak pasti; jangan kirim ulang otomatis.',
        ]);

        Livewire::test(ViewPurchaseRequisition::class, ['record' => $record->getRouteKey()])
            ->assertSee('Perlu Pemeriksaan')
            ->assertDontSee('Terkirim ke Accurate')
            ->assertDontSee('Belum Dikirim ke Accurate');
    }

    public function test_confirmation_decline_does_not_call_sender(): void
    {
        $record = $this->requisition(['sync_status' => 'failed', 'error_message' => 'Invalid unit']);

        $sender = Mockery::mock(PurchaseRequisitionSender::class);
        $sender->shouldReceive('sendDraft')->never();
        $this->app->instance(PurchaseRequisitionSender::class, $sender);

        Livewire::test(ViewPurchaseRequisition::class, ['record' => $record->getRouteKey()])
            ->mountAction('retryAccurate')
            ->assertSee('Konfirmasi Kirim Ulang')
            ->assertSee('Pastikan pengiriman sebelumnya benar-benar gagal')
            ->unmountAction();

        $this->assertSame('failed', $record->fresh()->sync_status);
    }

    public function test_confirmation_calls_sender_exactly_once_and_success_hides_retry_action(): void
    {
        $record = $this->requisition(['sync_status' => 'failed', 'error_message' => 'Invalid unit']);
        $this->addItem($record);

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
                    'payload' => ['verified' => true],
                    'response' => ['ok' => true],
                    'error_message' => null,
                    'synced_at' => now(),
                ]);

                return $record->fresh(['items']);
            });
        $this->app->instance(PurchaseRequisitionSender::class, $sender);

        Livewire::test(ViewPurchaseRequisition::class, ['record' => $record->getRouteKey()])
            ->callAction('retryAccurate')
            ->assertHasNoActionErrors()
            ->assertNotified(Notification::make()
                ->success()
                ->title('Permintaan Barang berhasil dikirim ke Accurate.')
                ->body("Nomor Accurate: DFT.26745\nStatus Accurate: DRAFT"))
            ->assertActionHidden('retryAccurate')
            ->assertDontSee('Bearer')
            ->assertDontSee('X-Api-AppKey')
            ->assertDontSee('test-token');

        $fresh = $record->fresh();
        $this->assertSame('synced', $fresh->sync_status);
        $this->assertSame(102200, $fresh->accurate_id);
        $this->assertSame('DFT.26745', $fresh->accurate_number);
        $this->assertNotNull($fresh->synced_at);
    }

    public function test_repeat_definite_failure_preserves_local_parent_and_details_without_second_call(): void
    {
        $record = $this->requisition(['sync_status' => 'failed', 'error_message' => 'Invalid unit']);
        $this->addItem($record);

        $sender = Mockery::mock(PurchaseRequisitionSender::class);
        $sender->shouldReceive('sendDraft')
            ->once()
            ->andReturnUsing(function (PurchaseRequisition $record): PurchaseRequisition {
                $record->update([
                    'sync_status' => 'failed',
                    'error_message' => 'Accurate menolak Permintaan Barang.',
                ]);

                return $record->fresh(['items']);
            });
        $this->app->instance(PurchaseRequisitionSender::class, $sender);

        Livewire::test(ViewPurchaseRequisition::class, ['record' => $record->getRouteKey()])
            ->callAction('retryAccurate')
            ->assertHasNoActionErrors()
            ->assertNotified(Notification::make()
                ->danger()
                ->title('Permintaan Barang belum berhasil dikirim ke Accurate.')
                ->body('Data lokal tetap tersimpan. Silakan tinjau status pengiriman sebelum mencoba lagi.'))
            ->assertActionVisible('retryAccurate');

        $fresh = $record->fresh(['items']);
        $this->assertSame('failed', $fresh->sync_status);
        $this->assertSame(1, PurchaseRequisition::count());
        $this->assertSame(1, $fresh->items->count());
    }

    public function test_ambiguous_retry_result_blocks_future_resend_without_second_call(): void
    {
        $record = $this->requisition(['sync_status' => 'failed', 'error_message' => 'Invalid unit']);
        $this->addItem($record);

        $sender = Mockery::mock(PurchaseRequisitionSender::class);
        $sender->shouldReceive('sendDraft')
            ->once()
            ->andReturnUsing(function (PurchaseRequisition $record): PurchaseRequisition {
                $record->update([
                    'sync_status' => 'failed',
                    'error_message' => 'AMBIGUOUS_REVIEW_REQUIRED: hasil pengiriman ke Accurate tidak pasti; jangan kirim ulang otomatis.',
                ]);

                return $record->fresh(['items']);
            });
        $this->app->instance(PurchaseRequisitionSender::class, $sender);

        Livewire::test(ViewPurchaseRequisition::class, ['record' => $record->getRouteKey()])
            ->callAction('retryAccurate')
            ->assertHasNoActionErrors()
            ->assertNotified(Notification::make()
                ->warning()
                ->title('Status pengiriman ke Accurate perlu diperiksa.')
                ->body('Jangan kirim ulang sebelum memastikan dokumen di Accurate.'))
            ->assertActionHidden('retryAccurate');

        $this->assertStringContainsString('AMBIGUOUS_REVIEW_REQUIRED', (string) $record->fresh()->error_message);
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

    private function addItem(PurchaseRequisition $record): void
    {
        $item = AccurateItem::firstOrCreate(
            ['accurate_id' => 790],
            ['no' => '100069', 'name' => 'Alchemy 200gr']
        );

        $record->items()->create([
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
        ]);
    }
}
