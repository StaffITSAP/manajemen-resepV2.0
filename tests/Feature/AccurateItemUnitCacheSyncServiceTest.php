<?php

namespace Tests\Feature;

use App\Console\Commands\AccurateSyncItemUnits;
use App\Models\AccurateItem;
use App\Models\AccurateItemUnit;
use App\Services\Accurate\AccurateClient;
use App\Services\Accurate\AccurateItemUnitCacheSyncService;
use App\Services\Accurate\AccurateItemUnitService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AccurateItemUnitCacheSyncServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('accurate_item_units');
        Schema::dropIfExists('accurate_items');

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

            $table->unique(['item_accurate_id', 'item_unit_accurate_id'], 'test_aiu_item_unit_unique');
            $table->unique(['item_accurate_id', 'position'], 'test_aiu_item_position_unique');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('accurate_item_units');
        Schema::dropIfExists('accurate_items');

        parent::tearDown();
    }

    public function test_one_item_with_unit1_only_is_saved_with_snapshots(): void
    {
        $item = $this->createItem(790, '100069', 'Alchemy 200gr');

        $result = $this->service([
            790 => $this->successDetail([
                'unit1' => ['id' => 50, 'name' => 'pcs'],
                'unit2' => null,
                'unit3' => null,
                'unit4' => null,
                'unit5' => null,
            ]),
        ])->sync(10);

        $this->assertSame(1, $result['units_inserted']);
        $this->assertDatabaseHas('accurate_item_units', [
            'accurate_item_id' => $item->id,
            'item_accurate_id' => 790,
            'item_no' => '100069',
            'item_name' => 'Alchemy 200gr',
            'item_unit_accurate_id' => 50,
            'item_unit_name' => 'pcs',
            'position' => 1,
            'source' => 'accurate_item_detail',
        ]);
    }

    public function test_one_item_with_unit1_and_unit2_saves_correct_positions_and_ignores_null_units(): void
    {
        $this->createItem(790, '100069', 'Alchemy 200gr');

        $this->service([
            790 => $this->successDetail([
                'unit1' => ['id' => 50, 'name' => 'pcs'],
                'unit2' => ['id' => 51, 'name' => 'grm'],
                'unit3' => null,
                'unit4' => null,
                'unit5' => null,
            ]),
        ])->sync(10);

        $this->assertSame([1, 2], AccurateItemUnit::orderBy('position')->pluck('position')->all());
        $this->assertSame([50, 51], AccurateItemUnit::orderBy('position')->pluck('item_unit_accurate_id')->all());
        $this->assertSame(2, AccurateItemUnit::count());
    }

    public function test_vendor_unit_is_ignored(): void
    {
        $this->createItem(790, '100069', 'Alchemy 200gr');

        $this->service([
            790 => $this->successDetail([
                'unit1' => ['id' => 50, 'name' => 'pcs'],
                'vendorUnit' => ['id' => 99, 'name' => 'box'],
            ]),
        ])->sync(10);

        $this->assertDatabaseMissing('accurate_item_units', [
            'item_unit_accurate_id' => 99,
        ]);
        $this->assertSame(1, AccurateItemUnit::count());
    }

    public function test_http_failure_does_not_delete_existing_cached_units(): void
    {
        $item = $this->createItem(790, '100069', 'Alchemy 200gr');
        $this->createCachedUnit($item, 51, 'grm', 2);

        $result = $this->service([
            790 => ['ok' => false, 'status' => 500, 'body' => ['error' => 'HTTP_ERROR']],
        ])->sync(10);

        $this->assertSame(1, $result['failures']);
        $this->assertDatabaseHas('accurate_item_units', [
            'item_accurate_id' => 790,
            'item_unit_accurate_id' => 51,
        ]);
    }

    public function test_business_failure_does_not_delete_existing_cached_units(): void
    {
        $item = $this->createItem(790, '100069', 'Alchemy 200gr');
        $this->createCachedUnit($item, 51, 'grm', 2);

        $result = $this->service([
            790 => ['ok' => true, 'status' => 200, 'body' => ['s' => false, 'm' => 'Gagal']],
        ])->sync(10);

        $this->assertSame(1, $result['failures']);
        $this->assertDatabaseHas('accurate_item_units', [
            'item_accurate_id' => 790,
            'item_unit_accurate_id' => 51,
        ]);
    }

    public function test_successful_response_reconciles_stale_local_units(): void
    {
        $item = $this->createItem(790, '100069', 'Alchemy 200gr');
        $this->createCachedUnit($item, 50, 'pcs', 1);
        $this->createCachedUnit($item, 51, 'grm', 2);

        $result = $this->service([
            790 => $this->successDetail([
                'unit1' => ['id' => 50, 'name' => 'pcs'],
            ]),
        ])->sync(10);

        $this->assertSame(1, $result['stale_units_removed']);
        $this->assertDatabaseHas('accurate_item_units', ['item_unit_accurate_id' => 50]);
        $this->assertDatabaseMissing('accurate_item_units', ['item_unit_accurate_id' => 51]);
    }

    public function test_rerunning_unchanged_data_is_idempotent(): void
    {
        $this->createItem(790, '100069', 'Alchemy 200gr');
        $service = $this->service([
            790 => $this->successDetail([
                'unit1' => ['id' => 50, 'name' => 'pcs'],
                'unit2' => ['id' => 51, 'name' => 'grm'],
            ]),
        ]);

        $first = $service->sync(10);
        $second = $service->sync(10);

        $this->assertSame(2, $first['units_inserted']);
        $this->assertSame(0, $second['units_inserted']);
        $this->assertSame(0, $second['units_updated']);
        $this->assertSame(2, $second['units_unchanged']);
        $this->assertSame(2, AccurateItemUnit::count());
    }

    public function test_duplicate_item_unit_is_not_created(): void
    {
        $this->createItem(790, '100069', 'Alchemy 200gr');
        $service = $this->service([
            790 => $this->successDetail([
                'unit1' => ['id' => 50, 'name' => 'pcs'],
            ]),
        ]);

        $service->sync(10);
        $service->sync(10);

        $this->assertSame(1, AccurateItemUnit::where('item_accurate_id', 790)->where('item_unit_accurate_id', 50)->count());
    }

    public function test_different_items_may_reuse_the_same_unit_id(): void
    {
        $this->createItem(790, '100069', 'Alchemy 200gr');
        $this->createItem(791, '100070', 'Bahan Lain');

        $this->service([
            790 => $this->successDetail(['unit1' => ['id' => 50, 'name' => 'pcs']]),
            791 => $this->successDetail(['unit1' => ['id' => 50, 'name' => 'pcs']]),
        ])->sync(10);

        $this->assertSame(2, AccurateItemUnit::where('item_unit_accurate_id', 50)->count());
    }

    public function test_limit_one_selects_at_most_one_local_item(): void
    {
        $this->createItem(790, '100069', 'Alchemy 200gr');
        $this->createItem(791, '100070', 'Bahan Lain');

        $client = new FakeItemDetailClient([
            790 => $this->successDetail(['unit1' => ['id' => 50, 'name' => 'pcs']]),
            791 => $this->successDetail(['unit1' => ['id' => 51, 'name' => 'grm']]),
        ]);

        $this->serviceWithClient($client)->sync(1);

        $this->assertSame([790], $client->detailCalls);
        $this->assertSame(1, AccurateItemUnit::count());
    }

    public function test_default_selection_keeps_original_remote_accurate_id_ordering(): void
    {
        $this->createItem(791, '100070', 'Bahan Lain');
        $this->createItem(790, '100069', 'Alchemy 200gr');

        $client = new FakeItemDetailClient([
            790 => $this->successDetail(['unit1' => ['id' => 50, 'name' => 'pcs']]),
            791 => $this->successDetail(['unit1' => ['id' => 51, 'name' => 'grm']]),
        ]);

        $this->serviceWithClient($client)->sync(1);

        $this->assertSame([790], $client->detailCalls);
    }

    public function test_offset_resumes_item_selection_by_original_accurate_id_order(): void
    {
        $this->createItem(791, '100070', 'Bahan Lain');
        $this->createItem(790, '100069', 'Alchemy 200gr');
        $this->createItem(792, '100071', 'Bahan Ketiga');

        $client = new FakeItemDetailClient([
            790 => $this->successDetail(['unit1' => ['id' => 50, 'name' => 'pcs']]),
            791 => $this->successDetail(['unit1' => ['id' => 51, 'name' => 'grm']]),
            792 => $this->successDetail(['unit1' => ['id' => 52, 'name' => 'box']]),
        ]);

        $this->serviceWithClient($client)->sync(1, null, 1);

        $this->assertSame([791], $client->detailCalls);
    }

    public function test_without_only_missing_cached_items_are_still_selected_as_before(): void
    {
        $cached = $this->createItem(790, '100069', 'Alchemy 200gr');
        $this->createCachedUnit($cached, 50, 'pcs', 1);
        $this->createItem(791, '100070', 'Bahan Lain');

        $client = new FakeItemDetailClient([
            790 => $this->successDetail(['unit1' => ['id' => 50, 'name' => 'pcs']]),
            791 => $this->successDetail(['unit1' => ['id' => 51, 'name' => 'grm']]),
        ]);

        $this->serviceWithClient($client)->sync(1);

        $this->assertSame([790], $client->detailCalls);
    }

    public function test_only_missing_skips_items_that_already_have_cached_units(): void
    {
        $cached = $this->createItem(790, '100069', 'Alchemy 200gr');
        $this->createCachedUnit($cached, 50, 'pcs', 1);
        $this->createItem(791, '100070', 'Bahan Lain');

        $client = new FakeItemDetailClient([
            791 => $this->successDetail(['unit1' => ['id' => 51, 'name' => 'grm']]),
        ]);

        $this->serviceWithClient($client)->sync(10, null, 0, true);

        $this->assertSame([791], $client->detailCalls);
    }

    public function test_sleep_is_applied_between_batch_item_requests(): void
    {
        $this->createItem(790, '100069', 'Alchemy 200gr');
        $this->createItem(791, '100070', 'Bahan Lain');
        $sleepCalls = [];

        $client = new FakeItemDetailClient([
            790 => $this->successDetail(['unit1' => ['id' => 50, 'name' => 'pcs']]),
            791 => $this->successDetail(['unit1' => ['id' => 51, 'name' => 'grm']]),
        ]);

        $service = $this->serviceWithClient($client, function (int $sleepMs) use (&$sleepCalls): void {
            $sleepCalls[] = $sleepMs;
        });

        $service->sync(2, null, 0, false, 500);

        $this->assertSame([500], $sleepCalls);
    }

    public function test_sleep_is_not_applied_for_single_item_target(): void
    {
        $this->createItem(790, '100069', 'Alchemy 200gr');
        $sleepCalls = [];

        $service = $this->serviceWithClient(new FakeItemDetailClient([
            790 => $this->successDetail(['unit1' => ['id' => 50, 'name' => 'pcs']]),
        ]), function (int $sleepMs) use (&$sleepCalls): void {
            $sleepCalls[] = $sleepMs;
        });

        $service->sync(10, 790, 0, false, 500);

        $this->assertSame([], $sleepCalls);
    }

    public function test_item_id_targets_remote_accurate_item_id(): void
    {
        $this->createItem(790, '100069', 'Alchemy 200gr');
        $this->createItem(791, '100070', 'Bahan Lain');

        $client = new FakeItemDetailClient([
            791 => $this->successDetail(['unit1' => ['id' => 51, 'name' => 'grm']]),
        ]);

        $this->serviceWithClient($client)->sync(10, 791);

        $this->assertSame([791], $client->detailCalls);
        $this->assertDatabaseHas('accurate_item_units', [
            'item_accurate_id' => 791,
            'item_unit_accurate_id' => 51,
        ]);
        $this->assertDatabaseMissing('accurate_item_units', [
            'item_accurate_id' => 790,
        ]);
    }

    public function test_invalid_limit_is_rejected(): void
    {
        $command = new AccurateSyncItemUnits();

        $this->expectException(InvalidArgumentException::class);

        $command->validatedOptions(['limit' => '0', 'item-id' => null]);
    }

    public function test_new_item_command_options_default_to_noop_values(): void
    {
        $command = new AccurateSyncItemUnits();

        $this->assertSame([
            'limit' => 10,
            'offset' => 0,
            'only_missing' => false,
            'sleep_ms' => 0,
            'item_id' => null,
        ], $command->validatedOptions(['limit' => '10', 'item-id' => null]));
    }

    public function test_invalid_item_id_is_rejected(): void
    {
        $command = new AccurateSyncItemUnits();

        $this->expectException(InvalidArgumentException::class);

        $command->validatedOptions(['limit' => '1', 'item-id' => 'abc']);
    }

    public function test_invalid_offset_and_sleep_are_rejected(): void
    {
        $command = new AccurateSyncItemUnits();

        foreach ([
            ['limit' => '1', 'offset' => '-1', 'sleep-ms' => '0', 'item-id' => null],
            ['limit' => '1', 'offset' => '0', 'sleep-ms' => '-1', 'item-id' => null],
            ['limit' => '1', 'offset' => '0', 'sleep-ms' => '10001', 'item-id' => null],
        ] as $options) {
            try {
                $command->validatedOptions($options);
                $this->fail('Invalid option was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_no_post_or_write_accurate_method_is_called(): void
    {
        $this->createItem(790, '100069', 'Alchemy 200gr');

        $client = new FakeItemDetailClient([
            790 => $this->successDetail(['unit1' => ['id' => 50, 'name' => 'pcs']]),
        ]);

        $this->serviceWithClient($client)->sync(10);

        $this->assertSame(0, $client->writeCalls);
        $this->assertSame([790], $client->detailCalls);
    }

    public function test_successful_empty_response_reconciles_item_to_zero_units(): void
    {
        $item = $this->createItem(790, '100069', 'Alchemy 200gr');
        $this->createCachedUnit($item, 50, 'pcs', 1);

        $result = $this->service([
            790 => $this->successDetail([
                'unit1' => null,
                'unit2' => null,
                'unit3' => null,
                'unit4' => null,
                'unit5' => null,
            ]),
        ])->sync(10);

        $this->assertSame(1, $result['items_with_no_populated_units']);
        $this->assertSame(1, $result['stale_units_removed']);
        $this->assertSame(0, AccurateItemUnit::count());
    }

    private function service(array $responses): AccurateItemUnitCacheSyncService
    {
        return $this->serviceWithClient(new FakeItemDetailClient($responses));
    }

    private function serviceWithClient(FakeItemDetailClient $client, ?callable $sleeper = null): AccurateItemUnitCacheSyncService
    {
        return new AccurateItemUnitCacheSyncService(
            $client,
            new AccurateItemUnitService(new class extends AccurateClient {
                public function __construct() {}
            }),
            $sleeper,
        );
    }

    private function successDetail(array $detail): array
    {
        return [
            'ok' => true,
            'status' => 200,
            'body' => [
                's' => true,
                'd' => $detail,
            ],
        ];
    }

    private function createItem(int $accurateId, string $no, string $name): AccurateItem
    {
        return AccurateItem::create([
            'accurate_id' => $accurateId,
            'no' => $no,
            'name' => $name,
            'raw' => [],
        ]);
    }

    private function createCachedUnit(AccurateItem $item, int $unitId, string $unitName, int $position): AccurateItemUnit
    {
        return AccurateItemUnit::create([
            'accurate_item_id' => $item->id,
            'item_accurate_id' => (int) $item->accurate_id,
            'item_no' => $item->no,
            'item_name' => $item->name,
            'item_unit_accurate_id' => $unitId,
            'item_unit_name' => $unitName,
            'position' => $position,
            'source' => 'accurate_item_detail',
            'synced_at' => now(),
        ]);
    }
}

class FakeItemDetailClient extends AccurateClient
{
    public array $detailCalls = [];
    public int $writeCalls = 0;

    public function __construct(private array $responses) {}

    public function detailItemById(int|string $accurateItemId): array
    {
        $id = (int) $accurateItemId;
        $this->detailCalls[] = $id;

        return $this->responses[$id] ?? ['ok' => false, 'status' => 404, 'body' => ['error' => 'NOT_FOUND']];
    }

    public function postJson(string $path, array $body = [], array $query = []): array
    {
        $this->writeCalls++;

        throw new \RuntimeException('POST must not be called by item unit sync.');
    }

    public function itemAdjustmentSave(array $payload): array
    {
        $this->writeCalls++;

        throw new \RuntimeException('Accurate write method must not be called by item unit sync.');
    }
}
