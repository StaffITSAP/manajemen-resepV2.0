<?php

namespace Tests\Feature;

use App\Services\Accurate\AccurateClient;
use App\Services\Accurate\PurchaseInvoiceLatestPriceSyncService;
use PHPUnit\Framework\TestCase;

class PurchaseInvoiceFirstMigrationCursorTest extends TestCase
{
    public function test_first_batch_processes_only_rows_zero_to_forty_nine(): void
    {
        $client = new CursorFakeAccurateClient();
        $result = $this->service($client)->syncSmartUnprocessedPurchaseInvoiceBatch(1, 100, 50, 0, [], 'quick', true, 0);

        $this->assertSame(range(1, 50), $client->detailIds);
        $this->assertCount(50, $result['candidates']);
        $this->assertFalse($result['page_complete']);
        $this->assertFalse($result['stage_complete']);
        $this->assertSame(50, $result['next_row_index']);
        $this->assertSame(1, $result['next_page']);
        $this->assertEmpty($client->reconcileCalls);
    }

    public function test_second_batch_resumes_same_page_at_row_fifty(): void
    {
        $client = new CursorFakeAccurateClient();
        $result = $this->service($client)->syncSmartUnprocessedPurchaseInvoiceBatch(1, 100, 50, 0, [], 'quick', true, 50);

        $this->assertSame(range(51, 100), $client->detailIds);
        $this->assertSame(50, $result['details_fetched']);
        $this->assertTrue($result['page_complete']);
        $this->assertFalse($result['stage_complete']);
        $this->assertSame(0, $result['next_row_index']);
        $this->assertSame(2, $result['next_page']);
        $this->assertEmpty($client->reconcileCalls);
    }

    public function test_staged_candidates_are_merged_without_resetting_cursor(): void
    {
        $state = [
            'status' => 'running',
            'current_page' => 1,
            'current_row_index' => 50,
            'candidates' => $this->candidateSet(1, 50),
        ];
        $client = new CursorFakeAccurateClient();
        $result = $this->service($client)->syncSmartUnprocessedPurchaseInvoiceBatch(
            $state['current_page'], 100, 50, 0, [], 'quick', true, $state['current_row_index'],
        );
        $merged = array_merge($state['candidates'], $result['candidates']);

        $this->assertSame(50, $state['current_row_index']);
        $this->assertSame(range(51, 100), $client->detailIds);
        $this->assertCount(100, $merged);
        $this->assertSame(1, $merged[0]['purchase_order_accurate_id']);
        $this->assertSame(100, $merged[99]['purchase_order_accurate_id']);
        $this->assertFalse($result['stage_complete']);
        $this->assertEmpty($client->reconcileCalls);
    }

    public function test_completed_page_transitions_to_page_two_row_zero(): void
    {
        $client = new CursorFakeAccurateClient();
        $result = $this->service($client)->syncSmartUnprocessedPurchaseInvoiceBatch(2, 100, 50, 0, [], 'quick', true, 0);

        $this->assertSame(range(101, 150), $client->detailIds);
        $this->assertSame(2, $client->requestedPages[0]);
        $this->assertFalse($result['page_complete']);
        $this->assertSame(50, $result['next_row_index']);
        $this->assertSame(2, $result['next_page']);
        $this->assertEmpty($client->reconcileCalls);
    }

    private function service(CursorFakeAccurateClient $client): PurchaseInvoiceLatestPriceSyncService
    {
        return new PurchaseInvoiceLatestPriceSyncService($client);
    }

    private function candidateSet(int $from, int $to): array
    {
        return array_map(fn (int $id): array => [
            'item_accurate_id' => 5000 + $id,
            'item_unit_accurate_id' => 51,
            'purchase_order_accurate_id' => $id,
            'purchase_order_date' => '2026-08-20',
            'purchase_order_detail_id' => 10000 + $id,
        ], range($from, $to));
    }
}

final class CursorFakeAccurateClient extends AccurateClient
{
    public array $detailIds = [];
    public array $requestedPages = [];
    public array $reconcileCalls = [];

    public function __construct() {}

    public function listPurchaseInvoices(array $params = []): array
    {
        $page = (int) ($params['sp.page'] ?? 1);
        $this->requestedPages[] = $page;
        $start = (($page - 1) * 100) + 1;
        return ['ok' => true, 'body' => ['d' => array_map(
            fn (int $id): array => ['id' => $id, 'number' => "PI.$id", 'transDate' => '2026-08-20', 'approvalStatus' => 'APPROVED'],
            range($start, $start + 99),
        )]];
    }

    public function detailPurchaseInvoice(int|string $id): array
    {
        $id = (int) $id;
        $this->detailIds[] = $id;
        return ['ok' => true, 'body' => ['d' => [
            'id' => $id,
            'number' => "PI.$id",
            'transDate' => '2026-08-20',
            'approvalStatus' => 'APPROVED',
            'detailItem' => [[
                'id' => 10000 + $id,
                'item' => ['id' => 5000 + $id, 'no' => "ITEM-$id", 'name' => "Item $id"],
                'itemUnit' => ['id' => 51, 'name' => 'grm'],
                'unitPrice' => (string) (100 + $id),
            ]],
        ]]];
    }
}
