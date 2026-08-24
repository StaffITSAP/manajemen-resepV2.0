<?php

namespace App\Jobs\Accurate;

use App\Services\Accurate\JobOrderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class FullSyncJobOrders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** JANGAN pakai typed property di job yg diserialisasi */
    public $maxPage = null;

    public int $timeout = 1800; // 30 menit
    public int $tries   = 3;

    public function __construct($maxPage = null)
    {
        $this->maxPage = $maxPage;   // bisa null
        $this->onQueue('default');
    }

    public function handle(JobOrderSyncService $svc): void
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        $maxPage = $this->maxPage ?? null;

        Log::info('[AccurateSyncJO] FullSync start', ['maxPage' => $maxPage]);

        $result = $svc->fullSync($maxPage);

        Log::info('[AccurateSyncJO] FullSync done', $result);
    }

    public function failed(Throwable $e): void
    {
        Log::error('[AccurateSyncJO] FullSync FAILED', [
            'error' => $e->getMessage(),
        ]);
    }
}
