<?php

namespace App\Console\Commands;

use App\Services\PurchaseRequisitions\Accurate\PurchaseRequisitionPayloadBuilder;
use App\Services\PurchaseRequisitions\Accurate\PurchaseRequisitionPayloadValidationException;
use Illuminate\Console\Command;

class AccuratePreviewPurchaseRequisition extends Command
{
    protected $signature = 'accurate:preview-purchase-requisition {localId : ID lokal Permintaan Barang}';

    protected $description = 'Preview payload draft Purchase Requisition Accurate tanpa mengirim data.';

    public function handle(PurchaseRequisitionPayloadBuilder $builder): int
    {
        $this->warn('DRY RUN / NO DATA SENT TO ACCURATE');

        try {
            $payload = $builder->build((int) $this->argument('localId'));
        } catch (PurchaseRequisitionPayloadValidationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
