<?php

namespace Tests\Feature;

use App\Jobs\PurchaseRequisitions\SyncPurchaseRequisitionItemUnitsBatch;
use App\Models\PurchaseInvoiceLatestPriceMigrationState;
use App\Services\Accurate\AccurateClient;
use App\Services\Accurate\PurchaseInvoiceLatestPriceSyncService;
use App\Services\PurchaseRequisitions\SmartSync\PurchaseRequisitionSmartSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseInvoiceMigrationInfrastructureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('purchase_invoice_latest_price_migration_states');
        Schema::create('purchase_invoice_latest_price_migration_states', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('not_completed')->index();
            $table->string('run_id')->nullable()->unique();
            $table->unsignedInteger('current_page')->default(1);
            $table->unsignedInteger('current_row_index')->default(0);
            $table->unsignedInteger('incremental_page')->default(1);
            $table->unsignedInteger('incremental_row_index')->default(0);
            $table->json('candidates')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Cache::lock(PurchaseRequisitionSmartSync::LOCK_KEY, 1)->forceRelease();
        Cache::forget(PurchaseRequisitionSmartSync::STATUS_KEY);
        Schema::dropIfExists('purchase_invoice_latest_price_migration_states');
        parent::tearDown();
    }

    public function test_invoice_service_processes_fake_list_and_detail_without_http(): void
    {
        $client = new InfrastructureFakeAccurateClient();
        $service = new PurchaseInvoiceLatestPriceSyncService($client);

        $result = $service->sync(1, 100, 1, 50, 0, true, 0);

        $this->assertTrue($result['ok']);
        $this->assertSame(100, $result['purchase_invoices']);
        $this->assertSame(50, $result['details_fetched']);
        $this->assertCount(1, $result['candidates']);
        $this->assertSame(5550, $result['candidates'][0]['item_accurate_id']);
        $this->assertSame(51, $result['candidates'][0]['item_unit_accurate_id']);
        $this->assertSame(50, $client->detailCalls);
        $this->assertSame(['filter.approvalStatus.val' => 'APPROVED'], array_intersect_key(
            $client->listParams,
            ['filter.approvalStatus.val' => true],
        ));
        $this->assertDatabaseCount('purchase_invoice_latest_price_migration_states', 0);
    }

    public function test_smart_sync_start_creates_running_state_and_fakes_first_job(): void
    {
        Queue::fake();

        $result = app(PurchaseRequisitionSmartSync::class)->start();
        $state = PurchaseInvoiceLatestPriceMigrationState::query()->firstOrFail();

        $this->assertSame('started', $result['status']);
        $this->assertSame('running', $state->status);
        $this->assertSame(1, $state->current_page);
        $this->assertSame(0, $state->current_row_index);
        $this->assertSame([], $state->candidates ?? []);
        $this->assertSame(1, $state->incremental_page);
        $this->assertSame(0, $state->incremental_row_index);
        Queue::assertPushed(SyncPurchaseRequisitionItemUnitsBatch::class, function ($job): bool {
            return $job->connection === PurchaseRequisitionSmartSync::QUEUE_CONNECTION
                && $job->queue === PurchaseRequisitionSmartSync::QUEUE_NAME;
        });
    }
}

final class InfrastructureFakeAccurateClient extends AccurateClient
{
    public int $detailCalls = 0;
    public array $listParams = [];

    public function __construct() {}

    public function listPurchaseInvoices(array $params = []): array
    {
        $this->listParams = $params;
        return ['ok' => true, 'body' => ['d' => array_map(
            fn (int $id): array => ['id' => $id, 'number' => "PI.$id", 'transDate' => '2026-08-20', 'approvalStatus' => 'APPROVED'],
            range(1, 100),
        )]];
    }

    public function detailPurchaseInvoice(int|string $id): array
    {
        $this->detailCalls++;
        return ['ok' => true, 'body' => ['d' => [
            'id' => (int) $id,
            'number' => "PI.$id",
            'transDate' => '2026-08-20',
            'approvalStatus' => 'APPROVED',
            'detailItem' => [[
                'id' => 10000 + (int) $id,
                'item' => ['id' => 5550, 'no' => 'ITEM-5550', 'name' => 'Test item'],
                'itemUnit' => ['id' => 51, 'name' => 'grm'],
                'unitPrice' => '100.500000',
            ]],
        ]]];
    }
}
