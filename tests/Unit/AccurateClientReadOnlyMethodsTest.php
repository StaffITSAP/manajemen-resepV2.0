<?php

namespace Tests\Unit;

use App\Services\Accurate\AccurateClient;
use PHPUnit\Framework\TestCase;

class AccurateClientReadOnlyMethodsTest extends TestCase
{
    public function test_detail_item_by_id_uses_item_detail_endpoint(): void
    {
        $client = $this->spyClient();

        $client->detailItemById(790);

        $this->assertSame('item/detail.do', $client->calls[0]['path']);
        $this->assertSame(['id' => 790], $client->calls[0]['query']);
    }

    public function test_list_purchase_orders_uses_read_only_list_endpoint(): void
    {
        $client = $this->spyClient();

        $client->listPurchaseOrders(['filter.approvalStatus.val' => 'APPROVED']);

        $this->assertSame('purchase-order/list.do', $client->calls[0]['path']);
        $this->assertSame(['filter.approvalStatus.val' => 'APPROVED'], $client->calls[0]['query']);
    }

    public function test_detail_purchase_order_uses_read_only_detail_endpoint(): void
    {
        $client = $this->spyClient();

        $client->detailPurchaseOrder(1001);

        $this->assertSame('purchase-order/detail.do', $client->calls[0]['path']);
        $this->assertSame(['id' => 1001], $client->calls[0]['query']);
    }

    private function spyClient(): AccurateClient
    {
        return new class extends AccurateClient {
            public array $calls = [];

            public function __construct() {}

            public function get(string $path, array $query = []): array
            {
                $this->calls[] = [
                    'path' => $path,
                    'query' => $query,
                ];

                return [
                    'status' => 200,
                    'ok' => true,
                    'body' => [],
                ];
            }
        };
    }
}
