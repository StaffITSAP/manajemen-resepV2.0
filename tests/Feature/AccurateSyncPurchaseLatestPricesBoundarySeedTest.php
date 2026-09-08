<?php

namespace Tests\Feature;

use App\Console\Commands\AccurateSyncPurchaseLatestPrices;
use App\Models\PurchaseInvoiceLatestPriceMigrationState;
use App\Services\Accurate\PurchaseInvoiceLatestPriceSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccurateSyncPurchaseLatestPricesBoundarySeedTest extends TestCase
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
            $table->date('incremental_run_upper_trans_date')->nullable();
            $table->date('incremental_completed_upper_trans_date')->nullable();
            $table->json('candidates')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('purchase_invoice_latest_price_migration_states');

        parent::tearDown();
    }

    public function test_successful_complete_direct_full_rebuild_seeds_incremental_boundary_from_latest_pi_cache(): void
    {
        $state = $this->completedState();
        $command = new BoundarySeedTestCommand([
            'page' => '1',
            'page-size' => '100',
            'max-pages' => null,
            'max-details' => null,
            'sleep-ms' => '0',
        ]);

        $result = $command->handle(new BoundarySeedFakePurchaseInvoiceLatestPriceSyncService(latestDate: '2026-09-07'));

        $state = $state->fresh();
        $this->assertSame(0, $result);
        $this->assertSame('completed', $state->status);
        $this->assertSame(11, $state->current_page);
        $this->assertSame(22, $state->current_row_index);
        $this->assertSame(1, $state->incremental_page);
        $this->assertSame(0, $state->incremental_row_index);
        $this->assertNull($state->incremental_run_upper_trans_date);
        $this->assertSame('2026-09-07', $state->incremental_completed_upper_trans_date->toDateString());
        $this->assertNull($state->error_message);
        $this->assertSame('2026-09-01 12:00:00', $state->completed_at->format('Y-m-d H:i:s'));
    }

    public function test_bounded_max_pages_run_does_not_seed_boundary(): void
    {
        $state = $this->completedState();
        $command = new BoundarySeedTestCommand([
            'page' => '1',
            'page-size' => '100',
            'max-pages' => '1',
            'max-details' => null,
            'sleep-ms' => '0',
        ]);

        $command->handle(new BoundarySeedFakePurchaseInvoiceLatestPriceSyncService(latestDate: '2026-09-07'));

        $this->assertNull($state->fresh()->incremental_completed_upper_trans_date);
    }

    public function test_bounded_max_details_run_does_not_seed_boundary(): void
    {
        $state = $this->completedState();
        $command = new BoundarySeedTestCommand([
            'page' => '1',
            'page-size' => '100',
            'max-pages' => null,
            'max-details' => '10',
            'sleep-ms' => '0',
        ]);

        $command->handle(new BoundarySeedFakePurchaseInvoiceLatestPriceSyncService(latestDate: '2026-09-07'));

        $this->assertNull($state->fresh()->incremental_completed_upper_trans_date);
    }

    public function test_failed_full_run_does_not_seed_boundary(): void
    {
        $state = $this->completedState();
        $command = new BoundarySeedTestCommand([
            'page' => '1',
            'page-size' => '100',
            'max-pages' => null,
            'max-details' => null,
            'sleep-ms' => '0',
        ]);

        $result = $command->handle(new BoundarySeedFakePurchaseInvoiceLatestPriceSyncService(ok: false, failures: 1, latestDate: '2026-09-07'));

        $this->assertSame(1, $result);
        $this->assertNull($state->fresh()->incremental_completed_upper_trans_date);
    }

    public function test_empty_authoritative_pi_cache_does_not_invent_boundary(): void
    {
        $state = $this->completedState();
        $command = new BoundarySeedTestCommand([
            'page' => '1',
            'page-size' => '100',
            'max-pages' => null,
            'max-details' => null,
            'sleep-ms' => '0',
        ]);

        $result = $command->handle(new BoundarySeedFakePurchaseInvoiceLatestPriceSyncService(latestDate: null));

        $this->assertSame(0, $result);
        $this->assertNull($state->fresh()->incremental_completed_upper_trans_date);
    }

    private function completedState(): PurchaseInvoiceLatestPriceMigrationState
    {
        return PurchaseInvoiceLatestPriceMigrationState::query()->create([
            'status' => 'completed',
            'run_id' => 'existing-completed-run',
            'current_page' => 11,
            'current_row_index' => 22,
            'incremental_page' => 9,
            'incremental_row_index' => 8,
            'incremental_run_upper_trans_date' => '2026-09-08',
            'incremental_completed_upper_trans_date' => null,
            'error_message' => 'missing baseline',
            'completed_at' => '2026-09-01 12:00:00',
        ]);
    }
}

class BoundarySeedTestCommand extends AccurateSyncPurchaseLatestPrices
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

class BoundarySeedFakePurchaseInvoiceLatestPriceSyncService extends PurchaseInvoiceLatestPriceSyncService
{
    public function __construct(
        private bool $ok = true,
        private int $failures = 0,
        private ?string $latestDate = '2026-09-07',
    ) {
    }

    public function sync(int $page = 1, int $pageSize = 10, ?int $maxPages = 1, ?int $maxDetails = null, int $sleepMs = 0, bool $stageOnly = false, int $startRowIndex = 0, ?string $incrementalRunUpperTransDate = null, ?string $incrementalCompletedUpperTransDate = null): array
    {
        return [
            'ok' => $this->ok,
            'purchase_invoices' => $this->ok ? 15541 : 100,
            'details_fetched' => $this->ok ? 15541 : 50,
            'lines_processed' => $this->ok ? 50010 : 100,
            'inserted' => 183,
            'updated' => 295,
            'unchanged' => 515,
            'skipped_malformed' => 0,
            'failures' => $this->failures,
            'message' => $this->ok ? null : 'Gagal mengambil detail Purchase Invoice.',
            'legacy_deleted' => 0,
        ];
    }

    public function latestCachedPurchaseInvoiceTransDate(): ?string
    {
        return $this->latestDate;
    }
}
