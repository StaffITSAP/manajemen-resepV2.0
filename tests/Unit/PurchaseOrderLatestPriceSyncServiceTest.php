<?php

namespace Tests\Unit;

use App\Models\PurchaseItemLatestPrice;
use App\Console\Commands\AccurateSyncPurchaseLatestPrices;
use App\Services\Accurate\AccurateClient;
use App\Services\Accurate\PurchaseOrderLatestPriceSyncService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PurchaseOrderLatestPriceSyncServiceTest extends TestCase
{
    private function service(): PurchaseOrderLatestPriceSyncService
    {
        return new PurchaseOrderLatestPriceSyncService(new class extends AccurateClient {
            public function __construct() {}
        });
    }

    public function test_it_uses_detail_item_unit_price_not_nested_item_price(): void
    {
        $candidates = $this->service()->extractLatestPriceCandidates([
            'id' => 1001,
            'number' => 'PO.001',
            'transDate' => '20/08/2026',
            'detailItem' => [
                [
                    'id' => 5001,
                    'item' => ['id' => 790, 'no' => '100069', 'name' => 'Alchemy 200gr', 'unitPrice' => 0],
                    'itemUnit' => ['id' => 50, 'name' => 'pcs'],
                    'unitPrice' => 75000,
                ],
            ],
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame('75000', $candidates[0]['unit_price']);
        $this->assertSame(790, $candidates[0]['item_accurate_id']);
        $this->assertSame(50, $candidates[0]['item_unit_accurate_id']);
    }

    public function test_it_skips_malformed_lines(): void
    {
        $inspection = $this->service()->extractLatestPriceCandidatesWithStats([
            'id' => 1001,
            'number' => 'PO.001',
            'transDate' => '20/08/2026',
            'detailItem' => [
                ['item' => ['id' => 790], 'unitPrice' => 75000],
                ['itemUnit' => ['id' => 50], 'unitPrice' => 75000],
                ['item' => ['id' => 790], 'itemUnit' => ['id' => 50]],
            ],
        ]);

        $this->assertSame([], $inspection['candidates']);
        $this->assertSame(3, $inspection['skipped_malformed']);
    }

    public function test_it_keeps_same_item_prices_separate_by_unit(): void
    {
        $candidates = $this->service()->extractLatestPriceCandidates([
            'id' => 1001,
            'number' => 'PO.001',
            'transDate' => '20/08/2026',
            'detailItem' => [
                [
                    'id' => 5001,
                    'item' => ['id' => 790, 'no' => '100069', 'name' => 'Alchemy 200gr'],
                    'itemUnit' => ['id' => 50, 'name' => 'pcs'],
                    'unitPrice' => 75000,
                ],
                [
                    'id' => 5002,
                    'item' => ['id' => 790, 'no' => '100069', 'name' => 'Alchemy 200gr'],
                    'itemUnit' => ['id' => 51, 'name' => 'grm'],
                    'unitPrice' => 375,
                ],
            ],
        ]);

        $this->assertCount(2, $candidates);
        $this->assertSame([50, 51], array_column($candidates, 'item_unit_accurate_id'));
    }

    public function test_newer_po_replaces_older_cached_price(): void
    {
        $existing = $this->existingLatestPrice('2026-08-19', 1000, 5000);

        $this->assertTrue($this->service()->candidateIsNewerThanExisting([
            'purchase_order_date' => '2026-08-20',
            'purchase_order_accurate_id' => 1001,
            'purchase_order_detail_id' => 5001,
        ], $existing));
    }

    public function test_older_po_cannot_replace_newer_cached_price(): void
    {
        $existing = $this->existingLatestPrice('2026-08-20', 1001, 5001);

        $this->assertFalse($this->service()->candidateIsNewerThanExisting([
            'purchase_order_date' => '2026-08-19',
            'purchase_order_accurate_id' => 1000,
            'purchase_order_detail_id' => 5000,
        ], $existing));
    }

    public function test_same_date_uses_purchase_order_id_as_stable_tie_breaker(): void
    {
        $existing = $this->existingLatestPrice('2026-08-20', 1001, 5001);

        $this->assertTrue($this->service()->candidateIsNewerThanExisting([
            'purchase_order_date' => '2026-08-20',
            'purchase_order_accurate_id' => 1002,
            'purchase_order_detail_id' => 5000,
        ], $existing));
    }

    public function test_same_po_and_same_detail_is_idempotent(): void
    {
        $existing = $this->existingLatestPrice('2026-08-20', 1001, 5001);

        $this->assertFalse($this->service()->candidateIsNewerThanExisting([
            'purchase_order_date' => '2026-08-20',
            'purchase_order_accurate_id' => 1001,
            'purchase_order_detail_id' => 5001,
        ], $existing));
    }

    public function test_unit_price_zero_remains_valid(): void
    {
        $candidates = $this->service()->extractLatestPriceCandidates([
            'id' => 1001,
            'number' => 'PO.001',
            'transDate' => '20/08/2026',
            'detailItem' => [
                [
                    'id' => 5001,
                    'item' => ['id' => 790, 'no' => '100069', 'name' => 'Alchemy 200gr'],
                    'itemUnit' => ['id' => 50, 'name' => 'pcs'],
                    'unitPrice' => 0,
                ],
            ],
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame('0', $candidates[0]['unit_price']);
    }

    public function test_purchase_order_list_params_request_only_validated_lightweight_fields(): void
    {
        $this->assertSame([
            'fields' => 'id,number,transDate,approvalStatus',
            'filter.approvalStatus.val' => 'APPROVED',
            'sp.sort' => 'transDate|desc',
            'sp.page' => 2,
            'sp.pageSize' => 20,
        ], $this->service()->listParams(2, 20));
    }

    public function test_missing_purchase_order_date_is_counted_as_malformed(): void
    {
        $inspection = $this->service()->extractLatestPriceCandidatesWithStats([
            'id' => 1001,
            'number' => 'PO.001',
            'detailItem' => [
                [
                    'id' => 5001,
                    'item' => ['id' => 790],
                    'itemUnit' => ['id' => 50],
                    'unitPrice' => 75000,
                ],
            ],
        ]);

        $this->assertSame([], $inspection['candidates']);
        $this->assertSame(1, $inspection['skipped_malformed']);
    }

    public function test_list_business_failure_is_fatal(): void
    {
        $service = $this->serviceWithClient(new class extends AccurateClient {
            public function __construct() {}
            public function listPurchaseOrders(array $params = []): array
            {
                return ['ok' => true, 'status' => 200, 'body' => ['s' => false, 'm' => 'Daftar gagal']];
            }
        });

        $result = $service->sync(1, 10, 1);

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['failures']);
    }

    public function test_http_list_failure_is_fatal(): void
    {
        $service = $this->serviceWithClient(new class extends AccurateClient {
            public function __construct() {}
            public function listPurchaseOrders(array $params = []): array
            {
                return ['ok' => false, 'status' => 500, 'body' => ['error' => 'HTTP_ERROR']];
            }
        });

        $result = $service->sync(1, 10, 1);

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['failures']);
    }

    public function test_detail_business_failure_counts_failure_and_continues(): void
    {
        $service = $this->serviceWithClient(new class extends AccurateClient {
            private int $detailCalls = 0;
            public function __construct() {}
            public function listPurchaseOrders(array $params = []): array
            {
                return [
                    'ok' => true,
                    'status' => 200,
                    'body' => ['s' => true, 'd' => [['id' => 1001], ['id' => 1002]]],
                ];
            }
            public function detailPurchaseOrder(int|string $id): array
            {
                $this->detailCalls++;
                if ($this->detailCalls === 1) {
                    return ['ok' => true, 'status' => 200, 'body' => ['s' => false, 'm' => 'Detail gagal']];
                }

                return [
                    'ok' => true,
                    'status' => 200,
                    'body' => ['s' => true, 'd' => ['id' => 1002, 'transDate' => '20/08/2026', 'detailItem' => [['unitPrice' => 0]]]],
                ];
            }
        });

        $result = $service->sync(1, 10, 1);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['purchase_orders']);
        $this->assertSame(1, $result['failures']);
        $this->assertSame(1, $result['details_fetched']);
        $this->assertSame(1, $result['skipped_malformed']);
    }

    public function test_http_detail_failure_counts_failure_and_continues(): void
    {
        $service = $this->serviceWithClient(new class extends AccurateClient {
            private int $detailCalls = 0;
            public function __construct() {}
            public function listPurchaseOrders(array $params = []): array
            {
                return [
                    'ok' => true,
                    'status' => 200,
                    'body' => ['s' => true, 'd' => [['id' => 1001], ['id' => 1002]]],
                ];
            }
            public function detailPurchaseOrder(int|string $id): array
            {
                $this->detailCalls++;
                if ($this->detailCalls === 1) {
                    return ['ok' => false, 'status' => 500, 'body' => ['error' => 'HTTP_ERROR']];
                }

                return [
                    'ok' => true,
                    'status' => 200,
                    'body' => ['s' => true, 'd' => ['id' => 1002, 'transDate' => '20/08/2026', 'detailItem' => [['unitPrice' => 0]]]],
                ];
            }
        });

        $result = $service->sync(1, 10, 1);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['failures']);
        $this->assertSame(1, $result['details_fetched']);
        $this->assertSame(1, $result['skipped_malformed']);
    }

    public function test_max_details_limits_purchase_order_detail_gets(): void
    {
        $client = new class extends AccurateClient {
            public array $detailCalls = [];
            public function __construct() {}
            public function listPurchaseOrders(array $params = []): array
            {
                return [
                    'ok' => true,
                    'status' => 200,
                    'body' => ['s' => true, 'd' => [['id' => 1001], ['id' => 1002], ['id' => 1003]]],
                ];
            }
            public function detailPurchaseOrder(int|string $id): array
            {
                $this->detailCalls[] = (int) $id;

                return [
                    'ok' => true,
                    'status' => 200,
                    'body' => ['s' => true, 'd' => ['id' => (int) $id, 'transDate' => '20/08/2026', 'detailItem' => []]],
                ];
            }
        };

        $result = $this->serviceWithClient($client)->sync(1, 10, 1, 2);

        $this->assertSame([1001, 1002], $client->detailCalls);
        $this->assertSame(2, $result['details_fetched']);
        $this->assertTrue($result['max_details_reached']);
    }

    public function test_default_sync_has_no_new_max_details_cap(): void
    {
        $client = new class extends AccurateClient {
            public array $detailCalls = [];
            public function __construct() {}
            public function listPurchaseOrders(array $params = []): array
            {
                return [
                    'ok' => true,
                    'status' => 200,
                    'body' => ['s' => true, 'd' => [['id' => 1001], ['id' => 1002], ['id' => 1003]]],
                ];
            }
            public function detailPurchaseOrder(int|string $id): array
            {
                $this->detailCalls[] = (int) $id;

                return [
                    'ok' => true,
                    'status' => 200,
                    'body' => ['s' => true, 'd' => ['id' => (int) $id, 'transDate' => '20/08/2026', 'detailItem' => []]],
                ];
            }
        };

        $result = $this->serviceWithClient($client)->sync();

        $this->assertSame([1001, 1002, 1003], $client->detailCalls);
        $this->assertSame(3, $result['details_fetched']);
        $this->assertFalse($result['max_details_reached']);
    }

    public function test_sleep_is_applied_between_purchase_order_detail_gets(): void
    {
        $sleepCalls = [];
        $client = new class extends AccurateClient {
            public function __construct() {}
            public function listPurchaseOrders(array $params = []): array
            {
                return [
                    'ok' => true,
                    'status' => 200,
                    'body' => ['s' => true, 'd' => [['id' => 1001], ['id' => 1002]]],
                ];
            }
            public function detailPurchaseOrder(int|string $id): array
            {
                return [
                    'ok' => true,
                    'status' => 200,
                    'body' => ['s' => true, 'd' => ['id' => (int) $id, 'transDate' => '20/08/2026', 'detailItem' => []]],
                ];
            }
        };

        $service = new PurchaseOrderLatestPriceSyncService($client, function (int $sleepMs) use (&$sleepCalls): void {
            $sleepCalls[] = $sleepMs;
        });

        $service->sync(1, 10, 1, 2, 500);

        $this->assertSame([500], $sleepCalls);
    }

    public function test_invalid_purchase_price_batch_options_are_rejected(): void
    {
        $command = new AccurateSyncPurchaseLatestPrices();

        foreach ([
            ['page' => '1', 'page-size' => '10', 'max-pages' => '1', 'max-details' => '0', 'sleep-ms' => '0'],
            ['page' => '1', 'page-size' => '10', 'max-pages' => '1', 'max-details' => '1', 'sleep-ms' => '-1'],
            ['page' => '1', 'page-size' => '10', 'max-pages' => '1', 'max-details' => '1', 'sleep-ms' => '10001'],
        ] as $options) {
            try {
                $command->validatedOptions($options);
                $this->fail('Invalid option was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    private function serviceWithClient(AccurateClient $client): PurchaseOrderLatestPriceSyncService
    {
        return new PurchaseOrderLatestPriceSyncService($client);
    }

    private function existingLatestPrice(string $date, int $poId, int $detailId): PurchaseItemLatestPrice
    {
        $model = new PurchaseItemLatestPrice();
        $model->setRawAttributes([
            'purchase_order_date' => $date,
            'purchase_order_accurate_id' => $poId,
            'purchase_order_detail_id' => $detailId,
        ], true);

        return $model;
    }
}
