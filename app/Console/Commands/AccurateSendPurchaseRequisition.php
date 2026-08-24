<?php

namespace App\Console\Commands;

use App\Models\PurchaseRequisition;
use App\Services\PurchaseRequisitions\Accurate\PurchaseRequisitionPayloadBuilder;
use App\Services\PurchaseRequisitions\Accurate\PurchaseRequisitionPayloadValidationException;
use App\Services\PurchaseRequisitions\Accurate\PurchaseRequisitionSender;
use Illuminate\Console\Command;
use RuntimeException;

class AccurateSendPurchaseRequisition extends Command
{
    protected $signature = 'accurate:send-purchase-requisition {localId : ID lokal Permintaan Barang}';

    protected $description = 'Kirim satu draft Purchase Requisition lokal ke Accurate melalui jalur terkontrol.';

    public function handle(
        PurchaseRequisitionSender $sender,
        PurchaseRequisitionPayloadBuilder $builder,
    ): int {
        $record = PurchaseRequisition::query()->with('items')->find((int) $this->argument('localId'));

        if (! $record) {
            $this->error('Permintaan Barang lokal tidak ditemukan.');

            return self::FAILURE;
        }

        try {
            $builder->build($record);
        } catch (PurchaseRequisitionPayloadValidationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Ringkasan Permintaan Barang:');
        $this->line('Local ID: ' . $record->id);
        $this->line('Tanggal: ' . optional($record->trans_date)->format('d/m/Y'));
        $this->line('Cabang: ' . ($record->branch_name ?: '-') . ' / ' . ($record->branch_accurate_id ?: '-'));
        $this->line('Deskripsi: ' . ($record->description ?: '-'));
        $this->line('Jumlah Detail: ' . $record->items->count());
        $this->line('Status Lokal: ' . $record->status);
        $this->line('Status Sinkronisasi: ' . $record->sync_status);

        if (! $this->confirm('This will create ONE Purchase Requisition DRAFT in Accurate.', false)) {
            $this->warn('Pengiriman dibatalkan. Tidak ada data yang dikirim.');

            return self::SUCCESS;
        }

        try {
            $updated = $sender->sendDraft($record);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'review operator')) {
                $this->error('REVIEW REQUIRED');
                $this->error('DO NOT RESEND AUTOMATICALLY');
            }

            $this->error($message);

            return self::FAILURE;
        }

        if (str_contains((string) $updated->error_message, 'AMBIGUOUS_REVIEW_REQUIRED')) {
            $this->error('REVIEW REQUIRED');
            $this->error('DO NOT RESEND AUTOMATICALLY');
            $this->line('Local ID: ' . $updated->id);
            $this->line('Sync Result: ' . $updated->sync_status);

            return self::FAILURE;
        }

        if ($updated->sync_status !== 'synced') {
            $this->error('Pengiriman gagal.');
            $this->line('Local ID: ' . $updated->id);
            $this->line('Sync Result: ' . $updated->sync_status);
            $this->line('Pesan: ' . ($updated->error_message ?: '-'));

            return self::FAILURE;
        }

        $this->info('Pengiriman berhasil.');
        $this->line('Local ID: ' . $updated->id);
        $this->line('Sync Result: ' . $updated->sync_status);
        $this->line('Accurate ID: ' . ($updated->accurate_id ?: '-'));
        $this->line('Accurate Number: ' . ($updated->accurate_number ?: '-'));
        $this->line('Accurate Status: ' . ($updated->accurate_status ?: '-'));

        return self::SUCCESS;
    }
}
