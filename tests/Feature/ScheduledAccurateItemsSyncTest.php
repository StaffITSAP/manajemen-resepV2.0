<?php

namespace Tests\Feature;

use App\Console\Commands\ScheduledAccurateItemsSync;
use App\Jobs\SyncAccurateItemsJob;
use App\Services\Accurate\AccurateItemSyncRunner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ScheduledAccurateItemsSyncTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::lock(ScheduledAccurateItemsSync::LOCK_KEY, 1)->forceRelease();
        parent::tearDown();
    }

    public function test_item_sync_is_scheduled_every_three_hours_in_jakarta(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains($event->command ?? '', 'accurate:scheduled-sync-items'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('0 */3 * * *', $event->expression);
        $this->assertSame('Asia/Jakarta', $event->timezone);
    }

    public function test_scheduled_command_reuses_existing_item_sync_job(): void
    {
        Bus::fake();

        $this->artisan('accurate:scheduled-sync-items')->assertExitCode(0);

        Bus::assertDispatchedSync(SyncAccurateItemsJob::class);
    }

    public function test_scheduled_command_skips_when_an_item_sync_lock_is_active(): void
    {
        Bus::fake();
        $lock = Cache::lock(ScheduledAccurateItemsSync::LOCK_KEY, ScheduledAccurateItemsSync::LOCK_TTL_SECONDS);
        $this->assertTrue($lock->get());

        $this->artisan('accurate:scheduled-sync-items')
            ->expectsOutput('Scheduled Accurate item sync is already running. Skipped.')
            ->assertExitCode(0);

        Bus::assertNothingDispatched();
        $lock->release();
    }

    public function test_manual_sync_runner_skips_while_scheduled_sync_holds_the_shared_lock(): void
    {
        Bus::fake();
        $lock = Cache::lock(ScheduledAccurateItemsSync::LOCK_KEY, ScheduledAccurateItemsSync::LOCK_TTL_SECONDS);
        $this->assertTrue($lock->get());

        $this->assertFalse(app(AccurateItemSyncRunner::class)->run());
        Bus::assertNothingDispatched();

        $lock->release();
    }
}
