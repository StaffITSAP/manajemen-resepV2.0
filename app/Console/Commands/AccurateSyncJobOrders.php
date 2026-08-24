<?php

namespace App\Console\Commands;

use App\Jobs\Accurate\FullSyncJobOrders;
use App\Services\Accurate\JobOrderSyncService;
use Illuminate\Console\Command;

class AccurateSyncJobOrders extends Command
{
    protected $signature = 'accurate:sync-job-orders 
                            {--dispatch : Dispatch ke queue (disarankan)}
                            {--max-page= : Batasi halaman saat full sync}';

    protected $description = 'Sinkronisasi job orders dari Accurate.';

    public function handle(JobOrderSyncService $svc): int
    {
        $maxPage = $this->option('max-page') ? (int) $this->option('max-page') : null;

        if ($this->option('dispatch')) {
            FullSyncJobOrders::dispatch($maxPage);
            $this->info('Job full sync didispatch ke queue.');
            $this->line('Jalankan worker: php artisan queue:work --queue=default --tries=2');
            return self::SUCCESS;
        }

        $this->warn('Menjalankan full sync langsung (blocking) …');
        $result = $svc->fullSync($maxPage);
        $this->info('Selesai: '.json_encode($result));
        return self::SUCCESS;
    }
}
