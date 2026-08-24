<?php

namespace App\Console\Commands;

use App\Services\Accurate\BranchSyncService;
use Illuminate\Console\Command;
use Throwable;

class AccurateSyncBranches extends Command
{
    protected $signature = 'accurate:sync-branches';
    protected $description = 'Sinkron seluruh cabang dari Accurate Online ke tabel local accurate_branches';

    public function handle(BranchSyncService $service): int
    {
        $this->info('Memulai sinkron cabang Accurate...');

        try {
            $res = $service->sync();
            $total = (int) ($res['total'] ?? 0);

            $this->info("Selesai. Tersinkron: {$total} cabang.");
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Gagal sinkron: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
