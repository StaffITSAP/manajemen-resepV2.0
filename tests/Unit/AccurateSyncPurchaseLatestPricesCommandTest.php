<?php

namespace Tests\Unit;

use App\Console\Commands\AccurateSyncPurchaseLatestPrices;
use App\Services\Accurate\PurchaseInvoiceLatestPriceSyncService;
use PHPUnit\Framework\TestCase;

class AccurateSyncPurchaseLatestPricesCommandTest extends TestCase
{
    public function test_positive_option_values_are_accepted_and_passed_to_service(): void
    {
        $command = new TestableAccurateSyncPurchaseLatestPricesCommand([
            'page' => '1',
            'page-size' => '10',
            'max-pages' => '1',
        ]);
        $service = new FakePurchaseInvoiceLatestPriceSyncService();

        $result = $command->handle($service);

        $this->assertSame(0, $result);
        $this->assertSame([1, 10, 1], $service->lastArgs);
        $this->assertSame([null, 0], $service->lastBatchArgs);
    }

    public function test_zero_value_is_rejected(): void
    {
        $command = new TestableAccurateSyncPurchaseLatestPricesCommand([
            'page' => '0',
            'page-size' => '10',
            'max-pages' => '1',
        ]);
        $service = new FakePurchaseInvoiceLatestPriceSyncService();

        $result = $command->handle($service);

        $this->assertSame(1, $result);
        $this->assertSame([], $service->lastArgs);
        $this->assertStringContainsString('--page', implode("\n", $command->errors));
    }

    public function test_negative_value_is_rejected(): void
    {
        $command = new TestableAccurateSyncPurchaseLatestPricesCommand([
            'page' => '-1',
            'page-size' => '10',
            'max-pages' => '1',
        ]);

        $result = $command->handle(new FakePurchaseInvoiceLatestPriceSyncService());

        $this->assertSame(1, $result);
        $this->assertStringContainsString('--page', implode("\n", $command->errors));
    }

    public function test_non_numeric_value_is_rejected(): void
    {
        $command = new TestableAccurateSyncPurchaseLatestPricesCommand([
            'page' => 'abc',
            'page-size' => '10',
            'max-pages' => '1',
        ]);

        $result = $command->handle(new FakePurchaseInvoiceLatestPriceSyncService());

        $this->assertSame(1, $result);
        $this->assertStringContainsString('--page', implode("\n", $command->errors));
    }

    public function test_page_size_zero_is_rejected(): void
    {
        $command = new TestableAccurateSyncPurchaseLatestPricesCommand([
            'page' => '1',
            'page-size' => '0',
            'max-pages' => '1',
        ]);

        $result = $command->handle(new FakePurchaseInvoiceLatestPriceSyncService());

        $this->assertSame(1, $result);
        $this->assertStringContainsString('--page-size', implode("\n", $command->errors));
    }

    public function test_max_pages_zero_is_rejected(): void
    {
        $command = new TestableAccurateSyncPurchaseLatestPricesCommand([
            'page' => '1',
            'page-size' => '10',
            'max-pages' => '0',
        ]);

        $result = $command->handle(new FakePurchaseInvoiceLatestPriceSyncService());

        $this->assertSame(1, $result);
        $this->assertStringContainsString('--max-pages', implode("\n", $command->errors));
    }

    public function test_safe_bounds_structurally_limit_to_one_page_and_ten_rows(): void
    {
        $command = new TestableAccurateSyncPurchaseLatestPricesCommand([
            'page' => '1',
            'page-size' => '10',
            'max-pages' => '1',
        ]);
        $service = new FakePurchaseInvoiceLatestPriceSyncService();

        $command->handle($service);

        $this->assertSame([1, 10, 1], $service->lastArgs);
    }
}

class TestableAccurateSyncPurchaseLatestPricesCommand extends AccurateSyncPurchaseLatestPrices
{
    public array $errors = [];
    public array $lines = [];

    public function __construct(private array $optionsMap)
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $this->optionsMap[$key] ?? null;
    }

    public function error($string, $verbosity = null): void
    {
        $this->errors[] = (string) $string;
    }

    public function info($string, $verbosity = null): void
    {
        $this->lines[] = (string) $string;
    }

    public function line($string, $style = null, $verbosity = null): void
    {
        $this->lines[] = (string) $string;
    }
}

class FakePurchaseInvoiceLatestPriceSyncService extends PurchaseInvoiceLatestPriceSyncService
{
    public array $lastArgs = [];
    public array $lastBatchArgs = [];

    public function __construct()
    {
    }

    public function sync(int $page = 1, int $pageSize = 10, ?int $maxPages = 1, ?int $maxDetails = null, int $sleepMs = 0, bool $stageOnly = false, int $startRowIndex = 0, ?string $incrementalRunUpperTransDate = null, ?string $incrementalCompletedUpperTransDate = null): array
    {
        $this->lastArgs = [$page, $pageSize, $maxPages];
        $this->lastBatchArgs = [$maxDetails, $sleepMs];

        return [
            'ok' => true,
            'purchase_invoices' => min($pageSize, 10),
            'details_fetched' => 0,
            'lines_processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped_malformed' => 0,
            'failures' => 0,
            'message' => null,
        ];
    }
}
