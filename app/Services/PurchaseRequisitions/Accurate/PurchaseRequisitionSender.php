<?php

namespace App\Services\PurchaseRequisitions\Accurate;

use App\Models\PurchaseRequisition;
use App\Services\Accurate\AccurateClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PurchaseRequisitionSender
{
    private const AMBIGUOUS_REVIEW_REQUIRED = 'AMBIGUOUS_REVIEW_REQUIRED';

    public function __construct(
        private readonly AccurateClient $client,
        private readonly PurchaseRequisitionPayloadBuilder $payloadBuilder,
    ) {
    }

    public function sendDraft(PurchaseRequisition|int $requisition): PurchaseRequisition
    {
        $record = $requisition instanceof PurchaseRequisition
            ? $requisition
            : PurchaseRequisition::query()->with('items')->findOrFail($requisition);

        $this->guardAgainstResend($record);

        $payload = $this->payloadBuilder->build($record);
        $response = $this->client->purchaseRequisitionSaveDraft($payload);

        return DB::transaction(function () use ($record, $payload, $response): PurchaseRequisition {
            $fresh = PurchaseRequisition::query()->lockForUpdate()->findOrFail($record->id);

            $this->guardAgainstResend($fresh);

            if (($response['status'] ?? null) === 0) {
                $fresh->update([
                    'payload' => $payload,
                    'response' => $response,
                    'sync_status' => 'failed',
                    'error_message' => self::AMBIGUOUS_REVIEW_REQUIRED . ': hasil pengiriman ke Accurate tidak pasti; jangan kirim ulang otomatis.',
                ]);

                return $fresh->fresh('items');
            }

            $body = $response['body'] ?? null;
            if (! is_array($body) || ! array_key_exists('s', $body)) {
                $fresh->update([
                    'payload' => $payload,
                    'response' => $response,
                    'sync_status' => 'failed',
                    'error_message' => self::AMBIGUOUS_REVIEW_REQUIRED . ': respons Accurate tidak dikenali; jangan kirim ulang otomatis.',
                ]);

                return $fresh->fresh('items');
            }

            if (($response['ok'] ?? false) && $body['s'] === true) {
                $data = is_array($body['r'] ?? null) ? $body['r'] : null;
                $accurateId = $this->scalarOrNull($data['id'] ?? null);
                $accurateNumber = $this->scalarOrNull($data['number'] ?? $data['no'] ?? null);
                $accurateStatus = $this->scalarOrNull($data['approvalStatus'] ?? $data['status'] ?? $data['statusName'] ?? null);

                if (! is_array($data) || blank($accurateId) || blank($accurateNumber)) {
                    $fresh->update([
                        'payload' => $payload,
                        'response' => $response,
                        'sync_status' => 'failed',
                        'error_message' => self::AMBIGUOUS_REVIEW_REQUIRED . ': respons sukses Accurate tidak memuat identitas dokumen yang cukup; jangan kirim ulang otomatis.',
                    ]);

                    return $fresh->fresh('items');
                }

                $fresh->update([
                    'payload' => $payload,
                    'response' => $response,
                    'sync_status' => 'synced',
                    'accurate_id' => $accurateId,
                    'accurate_number' => $accurateNumber,
                    'accurate_status' => $accurateStatus,
                    'error_message' => null,
                    'synced_at' => now(),
                ]);

                return $fresh->fresh('items');
            }

            $fresh->update([
                'payload' => $payload,
                'response' => $response,
                'sync_status' => 'failed',
                'error_message' => $this->failureMessage($body),
            ]);

            return $fresh->fresh('items');
        });
    }

    private function scalarOrNull(mixed $value): string|int|null
    {
        return is_scalar($value) ? $value : null;
    }

    private function guardAgainstResend(PurchaseRequisition $record): void
    {
        if (
            filled($record->accurate_id)
            || filled($record->accurate_number)
            || $record->sync_status === 'synced'
        ) {
            throw new RuntimeException('Permintaan Barang sudah tersinkron ke Accurate.');
        }

        if (str_contains((string) $record->error_message, self::AMBIGUOUS_REVIEW_REQUIRED)) {
            throw new RuntimeException('Permintaan Barang memerlukan review operator karena hasil kirim sebelumnya ambigu.');
        }
    }

    private function failureMessage(array $body): string
    {
        $message = $body['d'][0] ?? $body['d'] ?? $body['message'] ?? 'Accurate menolak Permintaan Barang.';

        return is_scalar($message) ? (string) $message : 'Accurate menolak Permintaan Barang.';
    }
}
