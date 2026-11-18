<?php

namespace App\Console\Commands;

use App\Services\Accurate\AccurateItemSyncRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScheduledAccurateItemsSync extends Command
{
    public const LOCK_KEY = 'accurate_items_scheduled_sync';
    public const LOCK_TTL_SECONDS = 10800;

    protected $signature = 'accurate:scheduled-sync-items';

    protected $description = 'Run the scheduled Accurate item sync with an application lock.';

    public function handle(AccurateItemSyncRunner $runner): int
    {
        try {
            if (! $runner->run()) {
            Log::info('Scheduled Accurate item sync skipped because a previous run is still active.');
            $this->info('Scheduled Accurate item sync is already running. Skipped.');

            return self::SUCCESS;
            }

            Log::info('Scheduled Accurate item sync started.');
            Log::info('Scheduled Accurate item sync finished.');
            $this->info('Scheduled Accurate item sync finished.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('Scheduled Accurate item sync failed.', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
            $this->error('Scheduled Accurate item sync failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
