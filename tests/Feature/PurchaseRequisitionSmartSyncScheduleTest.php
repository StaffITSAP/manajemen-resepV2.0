<?php

namespace Tests\Feature;

use App\Jobs\PurchaseRequisitions\SyncPurchaseRequisitionItemUnitsBatch;
use App\Services\PurchaseRequisitions\SmartSync\PurchaseRequisitionSmartSync;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseRequisitionSmartSyncScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('purchase_invoice_latest_price_migration_states', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('not_completed');
            $table->string('run_id')->nullable();
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
        Cache::lock(PurchaseRequisitionSmartSync::LOCK_KEY, 1)->forceRelease();
        Cache::forget(PurchaseRequisitionSmartSync::STATUS_KEY);
        Schema::dropIfExists('purchase_invoice_latest_price_migration_states');

        parent::tearDown();
    }

    public function test_scheduler_is_daily_at_0330_wib_with_overlap_protection(): void
    {
        Artisan::call('schedule:list');

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'accurate:sync-purchase-requisition-data'));

        $this->assertNotNull($event);
        $this->assertSame('30 3 * * *', $event->expression);
        $this->assertSame('Asia/Jakarta', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_scheduled_command_invokes_the_same_smart_sync_coordinator_as_manual_path(): void
    {
        Queue::fake();

        Artisan::call('accurate:sync-purchase-requisition-data');

        Queue::assertPushed(SyncPurchaseRequisitionItemUnitsBatch::class, 1);
    }

    public function test_scheduled_command_does_not_dispatch_duplicate_when_smart_sync_is_running(): void
    {
        Queue::fake();
        $service = app(PurchaseRequisitionSmartSync::class);
        $service->start();

        Artisan::call('accurate:sync-purchase-requisition-data');

        Queue::assertPushed(SyncPurchaseRequisitionItemUnitsBatch::class, 1);
    }
}
