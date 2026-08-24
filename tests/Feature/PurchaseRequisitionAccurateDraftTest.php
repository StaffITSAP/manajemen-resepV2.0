<?php

namespace Tests\Feature;

use App\Models\AccurateBranch;
use App\Models\AccurateItem;
use App\Models\PurchaseRequisition;
use App\Services\Accurate\AccurateClient;
use App\Services\PurchaseRequisitions\Accurate\PurchaseRequisitionPayloadBuilder;
use App\Services\PurchaseRequisitions\Accurate\PurchaseRequisitionPayloadValidationException;
use App\Services\PurchaseRequisitions\Accurate\PurchaseRequisitionSender;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PurchaseRequisitionAccurateDraftTest extends TestCase
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

    public function test_single_item_payload_has_exact_accurate_draft_structure(): void
    {
        $record = $this->requisition();
        $this->addItem($record, [
            'item_no' => '100069',
            'item_unit_name' => 'pcs',
            'quantity' => '2.000000',
            'required_date' => '2026-08-24',
            'note' => 'web only',
            'latest_purchase_unit_price' => '75000.00000000',
            'total_price' => '150000.00000000',
            'source_purchase_order_accurate_id' => 81350,
            'source_purchase_order_number' => 'PO.2026.08.00170',
            'source_purchase_order_date' => '2026-08-20',
        ]);

        $payload = $this->builder()->build($record);

        $this->assertSame([
            'transDate' => '22/08/2026',
            'branchId' => 50,
            'description' => 'Outlet A',
            'requisitionType' => 'PURCHASE',
            'saveAsStatusType' => 'DRAFT',
            'detailItem' => [[
                'itemNo' => '100069',
                'requiredDate' => '24/08/2026',
                'itemUnitName' => 'pcs',
                'quantity' => '2.000000',
                'unitPrice' => 0,
            ]],
        ], $payload);

        $this->assertArrayNotHasKey('note', $payload['detailItem'][0]);
        $this->assertArrayNotHasKey('latest_purchase_unit_price', $payload['detailItem'][0]);
        $this->assertArrayNotHasKey('total_price', $payload['detailItem'][0]);
        $this->assertArrayNotHasKey('source_purchase_order_accurate_id', $payload['detailItem'][0]);
        $this->assertArrayNotHasKey('source_purchase_order_number', $payload['detailItem'][0]);
        $this->assertArrayNotHasKey('source_purchase_order_date', $payload['detailItem'][0]);
    }

    public function test_multi_item_payload_preserves_saved_snapshots_and_decimal_quantity(): void
    {
        $record = $this->requisition(['branch_accurate_id' => 777]);
        $this->addItem($record, ['item_no' => 'SAVED-001', 'item_unit_name' => 'pcs', 'quantity' => '1.500000', 'required_date' => '2026-08-24']);
        $this->addItem($record, ['item_no' => 'SAVED-002', 'item_unit_name' => 'grm', 'quantity' => '500.250000', 'required_date' => '2026-08-25']);

        $payload = $this->builder()->build($record);

        $this->assertSame(777, $payload['branchId']);
        $this->assertSame('SAVED-001', $payload['detailItem'][0]['itemNo']);
        $this->assertSame('pcs', $payload['detailItem'][0]['itemUnitName']);
        $this->assertSame('1.500000', $payload['detailItem'][0]['quantity']);
        $this->assertSame('24/08/2026', $payload['detailItem'][0]['requiredDate']);
        $this->assertSame('SAVED-002', $payload['detailItem'][1]['itemNo']);
        $this->assertSame('grm', $payload['detailItem'][1]['itemUnitName']);
        $this->assertSame('500.250000', $payload['detailItem'][1]['quantity']);
        $this->assertSame(0, $payload['detailItem'][0]['unitPrice']);
        $this->assertSame(0, $payload['detailItem'][1]['unitPrice']);
    }

    public function test_purchase_requisition_save_draft_uses_json_request_body_with_nested_detail_items(): void
    {
        config()->set('accurate.base_url', 'http://127.0.0.1:9');
        config()->set('accurate.token', 'test-token');
        config()->set('accurate.app_key', 'test-app-key');
        config()->set('accurate.secret', 'test-secret');
        config()->set('accurate.tz', 'Asia/Jakarta');

        $history = [];
        $historyMiddleware = Middleware::history($history);
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['s' => true, 'd' => ['id' => 1, 'number' => 'PR.1']])),
        ]);
        $handler = HandlerStack::create($mock);
        $handler->push($historyMiddleware);

        $client = new AccurateClient();
        $http = new \GuzzleHttp\Client([
            'base_uri' => 'http://127.0.0.1:9/',
            'http_errors' => false,
            'handler' => $handler,
        ]);

        $property = new \ReflectionProperty($client, 'http');
        $property->setAccessible(true);
        $property->setValue($client, $http);

        $payload = [
            'transDate' => '22/08/2026',
            'branchId' => 50,
            'description' => 'test',
            'requisitionType' => 'PURCHASE',
            'saveAsStatusType' => 'DRAFT',
            'detailItem' => [[
                'itemNo' => '100069',
                'requiredDate' => '24/08/2026',
                'itemUnitName' => 'pcs',
                'quantity' => '2.000000',
                'unitPrice' => 0,
            ]],
        ];

        $response = $client->purchaseRequisitionSaveDraft($payload);

        $this->assertTrue($response['ok']);
        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('/purchase-requisition/save.do', $request->getUri()->getPath());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($payload, $body);
        $this->assertSame('100069', $body['detailItem'][0]['itemNo']);
        $this->assertSame('24/08/2026', $body['detailItem'][0]['requiredDate']);
        $this->assertSame('pcs', $body['detailItem'][0]['itemUnitName']);
        $this->assertSame('2.000000', $body['detailItem'][0]['quantity']);
        $this->assertSame(0, $body['detailItem'][0]['unitPrice']);
    }

    public function test_payload_validation_rejects_missing_required_snapshots(): void
    {
        $record = $this->requisition(['branch_accurate_id' => null]);
        $this->addItem($record);

        $this->expectException(PurchaseRequisitionPayloadValidationException::class);

        $this->builder()->build($record);
    }

    public function test_payload_validation_rejects_missing_unit_invalid_quantity_and_empty_details(): void
    {
        $empty = $this->requisition();

        $this->assertValidationFails($empty);

        $missingUnit = $this->requisition();
        $this->addItem($missingUnit, ['item_unit_name' => null]);
        $this->assertValidationFails($missingUnit);

        $zeroQuantity = $this->requisition();
        $this->addItem($zeroQuantity, ['quantity' => '0.000000']);
        $this->assertValidationFails($zeroQuantity);
    }

    public function test_preview_command_outputs_payload_without_resolving_accurate_client(): void
    {
        $this->app->bind(AccurateClient::class, function () {
            throw new \RuntimeException('AccurateClient must not be resolved by preview.');
        });

        $record = $this->requisition();
        $this->addItem($record);

        $this->artisan('accurate:preview-purchase-requisition', ['localId' => $record->id])
            ->expectsOutputToContain('DRY RUN / NO DATA SENT TO ACCURATE')
            ->expectsOutputToContain('"transDate": "22/08/2026"')
            ->assertSuccessful();

        $this->assertSame('pending', $record->fresh()->sync_status);
        $this->assertNull($record->fresh()->payload);
    }

    public function test_sender_maps_simulated_success_response(): void
    {
        $record = $this->requisition();
        $this->addItem($record);

        $sentPayload = null;
        $client = Mockery::mock(AccurateClient::class);
        $client->shouldReceive('purchaseRequisitionSaveDraft')
            ->once()
            ->withArgs(function (array $payload) use (&$sentPayload): bool {
                $sentPayload = $payload;

                return true;
            })
            ->andReturn(['status' => 200, 'ok' => true, 'body' => ['s' => true, 'r' => ['id' => 9001, 'number' => 'DFT.26745', 'approvalStatus' => 'DRAFT', 'statusName' => 'Draf']]]);

        $updated = (new PurchaseRequisitionSender($client, $this->builder()))->sendDraft($record);

        $this->assertSame('synced', $updated->sync_status);
        $this->assertSame(9001, $updated->accurate_id);
        $this->assertSame('DFT.26745', $updated->accurate_number);
        $this->assertSame('DRAFT', $updated->accurate_status);
        $this->assertNull($updated->error_message);
        $this->assertNotNull($updated->synced_at);
        $this->assertSame($sentPayload, $updated->payload);
    }

    public function test_sender_treats_success_without_document_identity_as_ambiguous(): void
    {
        $record = $this->requisition();
        $this->addItem($record);

        $updated = $this->sendWithResponse($record, ['status' => 200, 'ok' => true, 'body' => ['s' => true, 'r' => ['status' => 'DRAFT']]]);

        $this->assertSame('failed', $updated->sync_status);
        $this->assertStringContainsString('AMBIGUOUS_REVIEW_REQUIRED', (string) $updated->error_message);
        $this->assertNull($updated->accurate_id);
        $this->assertNull($updated->accurate_number);
    }

    public function test_sender_records_definite_failure_and_malformed_response_without_deleting_local_data(): void
    {
        $failed = $this->requisition();
        $this->addItem($failed);
        $this->sendWithResponse($failed, ['status' => 400, 'ok' => false, 'body' => ['s' => false, 'd' => ['Invalid unit']]]);

        $failed->refresh();
        $this->assertSame('failed', $failed->sync_status);
        $this->assertStringContainsString('Invalid unit', $failed->error_message);
        $this->assertNull($failed->accurate_id);
        $this->assertSame(1, $failed->items()->count());

        $malformed = $this->requisition();
        $this->addItem($malformed);
        $this->sendWithResponse($malformed, ['status' => 200, 'ok' => true, 'body' => ['unexpected' => true]]);

        $malformed->refresh();
        $this->assertSame('failed', $malformed->sync_status);
        $this->assertStringContainsString('tidak dikenali', $malformed->error_message);
        $this->assertSame(1, $malformed->items()->count());
    }

    public function test_ambiguous_transport_result_is_not_retried_and_requires_review(): void
    {
        $record = $this->requisition();
        $this->addItem($record);

        $client = Mockery::mock(AccurateClient::class);
        $client->shouldReceive('purchaseRequisitionSaveDraft')
            ->once()
            ->andReturn(['status' => 0, 'ok' => false, 'body' => ['error' => 'HTTP_ERROR', 'message' => 'timeout']]);

        $updated = (new PurchaseRequisitionSender($client, $this->builder()))->sendDraft($record);

        $this->assertSame('failed', $updated->sync_status);
        $this->assertStringContainsString('AMBIGUOUS_REVIEW_REQUIRED', (string) $updated->error_message);
        $this->assertNull($updated->accurate_id);
        $this->assertNull($updated->accurate_number);
        $this->assertSame(1, $updated->items()->count());
    }

    public function test_ambiguous_marker_blocks_second_send(): void
    {
        $record = $this->requisition([
            'sync_status' => 'failed',
            'error_message' => 'AMBIGUOUS_REVIEW_REQUIRED: hasil pengiriman ke Accurate tidak pasti; jangan kirim ulang otomatis.',
        ]);
        $this->addItem($record);

        $client = Mockery::mock(AccurateClient::class);
        $client->shouldReceive('purchaseRequisitionSaveDraft')->never();

        $this->expectException(\RuntimeException::class);

        (new PurchaseRequisitionSender($client, $this->builder()))->sendDraft($record);
    }

    public function test_malformed_response_marks_requisition_ambiguous_and_blocks_resend(): void
    {
        $record = $this->requisition();
        $this->addItem($record);

        $this->sendWithResponse($record, ['status' => 200, 'ok' => true, 'body' => ['unexpected' => true]]);

        $record->refresh();
        $this->assertStringContainsString('AMBIGUOUS_REVIEW_REQUIRED', (string) $record->error_message);

        $client = Mockery::mock(AccurateClient::class);
        $client->shouldReceive('purchaseRequisitionSaveDraft')->never();

        $this->expectException(\RuntimeException::class);

        (new PurchaseRequisitionSender($client, $this->builder()))->sendDraft($record);
    }

    public function test_already_synced_requisition_cannot_be_sent_again(): void
    {
        $record = $this->requisition(['sync_status' => 'synced', 'accurate_id' => 9001, 'accurate_number' => 'PR.1']);
        $this->addItem($record);

        $client = Mockery::mock(AccurateClient::class);
        $client->shouldReceive('purchaseRequisitionSaveDraft')->never();

        $this->expectException(\RuntimeException::class);

        (new PurchaseRequisitionSender($client, $this->builder()))->sendDraft($record);
    }

    private function builder(): PurchaseRequisitionPayloadBuilder
    {
        return app(PurchaseRequisitionPayloadBuilder::class);
    }

    private function sendWithResponse(PurchaseRequisition $record, array $response): PurchaseRequisition
    {
        $client = Mockery::mock(AccurateClient::class);
        $client->shouldReceive('purchaseRequisitionSaveDraft')->once()->andReturn($response);

        return (new PurchaseRequisitionSender($client, $this->builder()))->sendDraft($record);
    }

    private function assertValidationFails(PurchaseRequisition $record): void
    {
        try {
            $this->builder()->build($record);
            $this->fail('Expected payload validation to fail.');
        } catch (PurchaseRequisitionPayloadValidationException) {
            $this->assertTrue(true);
        }
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
