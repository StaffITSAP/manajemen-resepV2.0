<?php

namespace Tests\Feature;

use App\Jobs\SyncAccurateItemsJob;
use App\Models\AccurateItem;
use App\Services\Accurate\AccurateClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SyncAccurateItemsJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('accurate_items');
        Schema::create('accurate_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('accurate_id')->unique();
            $table->string('no')->nullable();
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('raw')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('accurate_items');

        parent::tearDown();
    }

    public function test_sync_maps_accurate_suspended_status_to_local_activity_status(): void
    {
        $client = new class extends AccurateClient {
            public array $queries = [];

            public function __construct()
            {
            }

            public function get(string $path, array $query = []): array
            {
                $this->queries[] = ['path' => $path, 'query' => $query];

                return [
                    'ok' => true,
                    'status' => 200,
                    'body' => [
                        's' => true,
                        'd' => [
                            ['id' => 790, 'no' => 'ACTIVE', 'name' => 'Barang Aktif', 'suspended' => false],
                            ['id' => 791, 'no' => 'INACTIVE', 'name' => 'Barang Nonaktif', 'suspended' => true],
                        ],
                        'sp' => ['page' => 1, 'pageCount' => 1],
                    ],
                ];
            }

            public function detailItemById(int|string $accurateItemId): array
            {
                throw new \RuntimeException('Item detail must not be requested during item sync.');
            }
        };

        (new SyncAccurateItemsJob())->handle($client);

        $this->assertTrue(AccurateItem::query()->where('accurate_id', 790)->firstOrFail()->is_active);
        $this->assertFalse(AccurateItem::query()->where('accurate_id', 791)->firstOrFail()->is_active);
        $this->assertSame([[
            'path' => 'item/list.do',
            'query' => [
                'fields' => 'id,no,name,suspended',
                'sp.page' => 1,
                'sp.pageSize' => (int) config('accurate.page_size', 1000),
            ],
        ]], $client->queries);
    }
}
