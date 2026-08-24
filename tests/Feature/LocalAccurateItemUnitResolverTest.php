<?php

namespace Tests\Feature;

use App\Models\AccurateItem;
use App\Models\AccurateItemUnit;
use App\Services\Accurate\LocalAccurateItemUnitResolver;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class LocalAccurateItemUnitResolverTest extends TestCase
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

            $table->unique(['item_accurate_id', 'item_unit_accurate_id'], 'test_item_unit_unique');
            $table->unique(['item_accurate_id', 'position'], 'test_item_position_unique');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('accurate_item_units');
        Schema::dropIfExists('accurate_items');

        parent::tearDown();
    }

    public function test_it_returns_multiple_units_for_one_accurate_item_ordered_by_position(): void
    {
        $item = $this->createItem(790, '100069', 'Alchemy 200gr');

        $this->createUnit($item, 790, 51, 'grm', 2);
        $this->createUnit($item, 790, 50, 'pcs', 1);

        $units = app(LocalAccurateItemUnitResolver::class)->unitsForItemAccurateId(790);

        $this->assertSame([50, 51], $units->pluck('item_unit_accurate_id')->all());
        $this->assertSame(['pcs', 'grm'], $units->pluck('item_unit_name')->all());
    }

    public function test_same_item_may_have_pcs_and_grm(): void
    {
        $item = $this->createItem(790, '100069', 'Alchemy 200gr');

        $this->createUnit($item, 790, 50, 'pcs', 1);
        $this->createUnit($item, 790, 51, 'grm', 2);

        $this->assertDatabaseHas('accurate_item_units', [
            'item_accurate_id' => 790,
            'item_unit_accurate_id' => 50,
            'item_unit_name' => 'pcs',
        ]);
        $this->assertDatabaseHas('accurate_item_units', [
            'item_accurate_id' => 790,
            'item_unit_accurate_id' => 51,
            'item_unit_name' => 'grm',
        ]);
    }

    public function test_different_items_may_use_the_same_unit_id(): void
    {
        $first = $this->createItem(790, '100069', 'Alchemy 200gr');
        $second = $this->createItem(791, '100070', 'Bahan Lain');

        $this->createUnit($first, 790, 50, 'pcs', 1);
        $this->createUnit($second, 791, 50, 'pcs', 1);

        $this->assertSame(2, AccurateItemUnit::where('item_unit_accurate_id', 50)->count());
    }

    public function test_duplicate_item_and_unit_is_prevented(): void
    {
        $item = $this->createItem(790, '100069', 'Alchemy 200gr');

        $this->createUnit($item, 790, 50, 'pcs', 1);

        $this->expectException(QueryException::class);

        $this->createUnit($item, 790, 50, 'pcs', 2);
    }

    public function test_duplicate_item_and_position_is_prevented(): void
    {
        $item = $this->createItem(790, '100069', 'Alchemy 200gr');

        $this->createUnit($item, 790, 50, 'pcs', 1);

        $this->expectException(QueryException::class);

        $this->createUnit($item, 790, 51, 'grm', 1);
    }

    public function test_position_must_be_between_one_and_five(): void
    {
        $item = $this->createItem(790, '100069', 'Alchemy 200gr');

        $this->expectException(InvalidArgumentException::class);

        $this->createUnit($item, 790, 50, 'pcs', 6);
    }

    public function test_empty_cache_returns_empty_collection(): void
    {
        $units = app(LocalAccurateItemUnitResolver::class)->unitsForItemAccurateId(790);

        $this->assertTrue($units->isEmpty());
    }

    public function test_local_resolver_performs_no_http_or_accurate_client_call(): void
    {
        $this->app->bind(\App\Services\Accurate\AccurateClient::class, function () {
            return new class {
                public function __call(string $name, array $arguments): never
                {
                    throw new \RuntimeException("AccurateClient should not be called: {$name}");
                }
            };
        });

        $item = $this->createItem(790, '100069', 'Alchemy 200gr');
        $this->createUnit($item, 790, 50, 'pcs', 1);

        $units = app(LocalAccurateItemUnitResolver::class)->unitsForItemAccurateId(790);

        $this->assertCount(1, $units);
        $this->assertSame(50, $units->first()->item_unit_accurate_id);
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

    private function createUnit(
        AccurateItem $item,
        int $itemAccurateId,
        int $unitAccurateId,
        string $unitName,
        int $position,
    ): AccurateItemUnit {
        return AccurateItemUnit::create([
            'accurate_item_id' => $item->id,
            'item_accurate_id' => $itemAccurateId,
            'item_no' => $item->no,
            'item_name' => $item->name,
            'item_unit_accurate_id' => $unitAccurateId,
            'item_unit_name' => $unitName,
            'position' => $position,
            'source' => "unit{$position}",
        ]);
    }
}
