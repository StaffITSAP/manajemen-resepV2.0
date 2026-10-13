<?php

namespace App\Console\Commands;

use App\Services\PurchaseRequisitions\SmartSync\PurchaseRequisitionSmartSync;
use Illuminate\Console\Command;

class AccurateSyncPurchaseRequisitionData extends Command
{
    protected $signature = 'accurate:sync-purchase-requisition-data';

    protected $description = 'Jalankan Smart Sync data satuan dan harga Permintaan Barang.';

    public function handle(PurchaseRequisitionSmartSync $smartSync): int
    {
        $result = $smartSync->start();

        if (($result['status'] ?? null) === 'already_running') {
            $this->info('Smart Sync Permintaan Barang masih berjalan; tidak membuat duplikat.');

            return self::SUCCESS;
        }

        $this->info('Smart Sync Permintaan Barang dimulai.');

        return self::SUCCESS;
    }
}
