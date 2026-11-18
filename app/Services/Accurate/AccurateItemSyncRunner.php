<?php

namespace App\Services\Accurate;

use App\Console\Commands\ScheduledAccurateItemsSync;
use App\Jobs\SyncAccurateItemsJob;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;

class AccurateItemSyncRunner
{
    public function run(): bool
    {
        if (! Cache::getStore() instanceof LockProvider) {
            throw new \RuntimeException('Cache store does not support atomic locks for Accurate item sync.');
        }

        $lock = Cache::lock(ScheduledAccurateItemsSync::LOCK_KEY, ScheduledAccurateItemsSync::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            return false;
        }

        try {
            SyncAccurateItemsJob::dispatchSync();

            return true;
        } finally {
            $lock->release();
        }
    }
}
