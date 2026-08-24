<?php

namespace Tests\Unit;

use App\Services\Accurate\AccurateClient;
use App\Services\Accurate\AccurateItemUnitService;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class AccurateItemUnitServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Log::swap(new NullLogger());
    }

    private function service(): AccurateItemUnitService
    {
        return new AccurateItemUnitService(new class extends AccurateClient {
            public function __construct() {}
        });
    }

    public function test_it_reads_one_populated_unit(): void
    {
        $units = $this->service()->normalizeUnitsFromDetail([
            'hasMultiUnit' => true,
            'unit1' => ['id' => 50, 'name' => 'pcs'],
            'unit2' => null,
            'unit3' => null,
            'unit4' => null,
            'unit5' => null,
        ]);

        $this->assertSame([
            ['id' => 50, 'name' => 'pcs', 'position' => 1, 'source' => 'unit1'],
        ], $units);
    }

    public function test_it_reads_multiple_populated_units_without_using_has_multi_unit(): void
    {
        $units = $this->service()->normalizeUnitsFromDetail([
            'hasMultiUnit' => false,
            'unit1' => ['id' => 50, 'name' => 'pcs'],
            'unit2' => ['id' => 51, 'name' => 'grm'],
            'unit3' => null,
            'unit4' => null,
            'unit5' => null,
            'ratio2' => 0.005,
        ]);

        $this->assertCount(2, $units);
        $this->assertSame(50, $units[0]['id']);
        $this->assertSame('pcs', $units[0]['name']);
        $this->assertSame(51, $units[1]['id']);
        $this->assertSame('grm', $units[1]['name']);
    }

    public function test_vendor_unit_duplicate_does_not_add_another_dropdown_unit(): void
    {
        $units = $this->service()->normalizeUnitsFromDetail([
            'unit1' => ['id' => 50, 'name' => 'pcs'],
            'unit2' => null,
            'unit3' => null,
            'unit4' => null,
            'unit5' => null,
            'vendorUnit' => ['id' => 50, 'name' => 'pcs'],
        ]);

        $this->assertCount(1, $units);
        $this->assertSame('unit1', $units[0]['source']);
    }

    public function test_non_duplicate_vendor_unit_is_not_exposed(): void
    {
        $units = $this->service()->normalizeUnitsFromDetail([
            'unit1' => ['id' => 50, 'name' => 'pcs'],
            'unit2' => null,
            'unit3' => null,
            'unit4' => null,
            'unit5' => null,
            'vendorUnit' => ['id' => 52, 'name' => 'box'],
        ]);

        $this->assertCount(1, $units);
        $this->assertSame('unit1', $units[0]['source']);
    }

    public function test_it_extracts_units_from_real_like_success_wrapper(): void
    {
        $units = $this->service()->extractUnitsFromResponse([
            'ok' => true,
            'status' => 200,
            'body' => [
                's' => true,
                'd' => [
                    'hasMultiUnit' => true,
                    'unit1' => ['id' => 50, 'name' => 'pcs'],
                    'unit2' => ['id' => 51, 'name' => 'grm'],
                ],
            ],
        ], 790);

        $this->assertCount(2, $units);
        $this->assertSame([50, 51], array_column($units, 'id'));
    }

    public function test_it_throws_on_business_failure_response(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service()->extractUnitsFromResponse([
            'ok' => true,
            'status' => 200,
            'body' => [
                's' => false,
                'm' => 'Item tidak ditemukan',
            ],
        ], 790);
    }

    public function test_it_throws_on_http_client_failure(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service()->extractUnitsFromResponse([
            'ok' => false,
            'status' => 500,
            'body' => [
                'error' => 'HTTP_ERROR',
            ],
        ], 790);
    }
}
