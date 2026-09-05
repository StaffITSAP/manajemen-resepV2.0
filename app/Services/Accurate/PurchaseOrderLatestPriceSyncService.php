<?php

namespace App\Services\Accurate;

use App\Models\AccuratePurchaseOrderSyncState;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class PurchaseOrderLatestPriceSyncService
{
    public const SCAN_MODE_QUICK = 'quick';
    public const SCAN_MODE_FULL = 'full';
    public const QUICK_SYNC_OVERLAP_DAYS = 30;

    public function __construct(private AccurateClient $client, private mixed $sleeper = null) {}

    /**
     * Sync newest APPROVED Purchase Order detail prices into a local item+unit cache.
     */
    public function sync(int $page = 1, int $pageSize = 10, ?int $maxPages = 1, ?int $maxDetails = null, int $sleepMs = 0): array
    {
        $page = max(1, $page);
        $pageSize = max(1, min($pageSize, 100));
        $maxPages = $maxPages === null ? null : max(1, $maxPages);
        $maxDetails = $maxDetails === null ? null : max(1, $maxDetails);

        $stats = [
            'ok'                => true,
            'pages_requested'   => 0,
            'purchase_orders'   => 0,
            'details_fetched'   => 0,
            'lines_processed'   => 0,
            'inserted'          => 0,
            'updated'           => 0,
            'unchanged'         => 0,
            'skipped_malformed' => 0,
            'failures'          => 0,
            'message'           => null,
            'next_page'          => $page,
            'max_details_reached'=> false,
        ];

        $currentPage = $page;
        $processedPages = 0;

        while ($maxPages === null || $processedPages < $maxPages) {
            $resp = $this->client->listPurchaseOrders($this->listParams($currentPage, $pageSize));
            $stats['pages_requested']++;

            if (! ($resp['ok'] ?? false)) {
                $stats['ok'] = false;
                $stats['failures']++;
                $stats['message'] = 'Gagal mengambil daftar Purchase Order dari Accurate.';
                $this->logWarning('[AccuratePOLatestPrice] list failed', [
                    'page'   => $currentPage,
                    'status' => $resp['status'] ?? null,
                ]);
                break;
            }

            $body = $resp['body'] ?? [];

            try {
                $rows = $this->extractListRowsOrFail($body);
            } catch (RuntimeException $e) {
                $stats['ok'] = false;
                $stats['failures']++;
                $stats['message'] = $e->getMessage();
                $this->logWarning('[AccuratePOLatestPrice] invalid list payload', [
                    'page'    => $currentPage,
                    'message' => $e->getMessage(),
                ]);
                break;
            }

            $stats['purchase_orders'] += count($rows);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                if ($maxDetails !== null && $stats['details_fetched'] >= $maxDetails) {
                    $stats['max_details_reached'] = true;
                    break 2;
                }

                $poId = (int) Arr::get($row, 'id', 0);
                if ($poId <= 0) {
                    $stats['skipped_malformed']++;
                    continue;
                }

                if ($stats['details_fetched'] > 0 && $sleepMs > 0) {
                    $this->sleep($sleepMs);
                }

                $detailResp = $this->client->detailPurchaseOrder($poId);
                if (! ($detailResp['ok'] ?? false)) {
                    $stats['failures']++;
                    $this->logWarning('[AccuratePOLatestPrice] detail failed', [
                        'purchase_order_id' => $poId,
                        'status'            => $detailResp['status'] ?? null,
                    ]);
                    continue;
                }

                try {
                    $detail = $this->extractSuccessfulDetailPayload($detailResp['body'] ?? []);
                    $inspection = $this->extractLatestPriceCandidatesWithStats($detail, $row);
                } catch (RuntimeException $e) {
                    $stats['failures']++;
                    $this->logWarning('[AccuratePOLatestPrice] invalid detail payload', [
                        'purchase_order_id' => $poId,
                        'message'           => $e->getMessage(),
                    ]);
                    continue;
                }

                $stats['details_fetched']++;
                $stats['skipped_malformed'] += $inspection['skipped_malformed'];

                $stats['lines_processed'] += count($inspection['candidates']);

                $this->recordSuccessfulSyncState($detail, $row);
            }

            $processedPages++;
            $stats['next_page'] = $currentPage + 1;

            $sp = is_array($body) ? ($body['sp'] ?? []) : [];
            $pageCount = isset($sp['pageCount']) ? (int) $sp['pageCount'] : null;
            if ($pageCount !== null && $currentPage >= $pageCount) {
                break;
            }

            if (count($rows) < $pageSize) {
                break;
            }

            $currentPage++;
        }

        return $stats;
    }

    public function listParams(int $page, int $pageSize): array
    {
        return [
            'fields'                    => 'id,number,transDate,approvalStatus',
            'filter.approvalStatus.val' => 'APPROVED',
            'sp.sort'                   => 'transDate|desc',
            'sp.page'                   => $page,
            'sp.pageSize'               => $pageSize,
        ];
    }

    /**
     * Discover one Approved PO list page and detail-process at most 50 unknown PO IDs.
     *
     * @return array<string, int|bool|null|string>
     */
    public function syncSmartUnprocessedPurchaseOrderBatch(
        int $page = 1,
        int $pageSize = 100,
        int $maxDetails = 50,
        int $sleepMs = 500,
        array $attemptedPurchaseOrderIds = [],
        string $scanMode = self::SCAN_MODE_QUICK,
    ): array
    {
        $page = max(1, $page);
        $pageSize = max(1, min($pageSize, 100));
        $maxDetails = max(1, min($maxDetails, 50));
        $scanMode = $scanMode === self::SCAN_MODE_FULL ? self::SCAN_MODE_FULL : self::SCAN_MODE_QUICK;
        $attemptedPurchaseOrderIds = array_values(array_unique(array_filter(
            array_map('intval', $attemptedPurchaseOrderIds),
            fn(int $poId): bool => $poId > 0,
        )));
        $attemptedLookup = array_fill_keys($attemptedPurchaseOrderIds, true);

        $stats = [
            'ok' => true,
            'pages_requested' => 0,
            'purchase_orders' => 0,
            'known_skipped' => 0,
            'details_fetched' => 0,
            'detail_requests' => 0,
            'lines_processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped_malformed' => 0,
            'workflow_attempted_skipped' => 0,
            'failures' => 0,
            'message' => null,
            'next_page' => $page,
            'stage_complete' => false,
            'max_details_reached' => false,
            'attempted_purchase_order_ids' => $attemptedPurchaseOrderIds,
            'scan_mode' => $scanMode,
            'quick_boundary_reached' => false,
        ];

        $resp = $this->client->listPurchaseOrders($this->listParams($page, $pageSize));
        $stats['pages_requested']++;

        if (! ($resp['ok'] ?? false)) {
            $stats['ok'] = false;
            $stats['failures']++;
            $stats['message'] = 'Gagal mengambil daftar Purchase Order dari Accurate.';
            return $stats;
        }

        $body = $resp['body'] ?? [];

        try {
            $rows = $this->extractListRowsOrFail($body);
        } catch (RuntimeException $e) {
            $stats['ok'] = false;
            $stats['failures']++;
            $stats['message'] = $e->getMessage();
            return $stats;
        }

        $stats['purchase_orders'] = count($rows);

        if ($rows === []) {
            $stats['stage_complete'] = true;
            return $stats;
        }

        foreach ($rows as $row) {
            $poId = (int) Arr::get($row, 'id', 0);
            if ($poId <= 0) {
                $stats['skipped_malformed']++;
                continue;
            }

            if ($this->purchaseOrderAlreadyProcessed($poId)) {
                $stats['known_skipped']++;
                continue;
            }

            if (isset($attemptedLookup[$poId])) {
                $stats['workflow_attempted_skipped']++;
                continue;
            }

            if ($stats['detail_requests'] >= $maxDetails) {
                $stats['max_details_reached'] = true;
                $stats['next_page'] = $page;
                return $stats;
            }

            if ($stats['detail_requests'] > 0 && $sleepMs > 0) {
                $this->sleep($sleepMs);
            }

            $stats['detail_requests']++;
            $stats['attempted_purchase_order_ids'][] = $poId;
            $attemptedLookup[$poId] = true;
            $this->processPurchaseOrderDetail($poId, $row, $stats);
        }

        $sp = is_array($body) ? ($body['sp'] ?? []) : [];
        $pageCount = isset($sp['pageCount']) ? (int) $sp['pageCount'] : null;

        if ($scanMode === self::SCAN_MODE_QUICK && $this->quickScanBoundaryReached($rows)) {
            $stats['stage_complete'] = true;
            $stats['quick_boundary_reached'] = true;
            return $stats;
        }

        if (($pageCount !== null && $page >= $pageCount) || count($rows) < $pageSize) {
            $stats['stage_complete'] = true;
            return $stats;
        }

        $stats['next_page'] = $page + 1;

        return $stats;
    }

    private function quickScanBoundaryReached(array $rows): bool
    {
        if ($rows === []) {
            return false;
        }

        $cutoff = Carbon::now(config('app.timezone'))->subDays(self::QUICK_SYNC_OVERLAP_DAYS)->startOfDay();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                return false;
            }

            $date = $this->parseDate($row['transDate'] ?? null);

            if ($date === null || $date->gte($cutoff)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract valid latest-price candidates from one PO detail response.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractLatestPriceCandidates(array $purchaseOrderDetail, array $purchaseOrderListRow = []): array
    {
        return $this->extractLatestPriceCandidatesWithStats($purchaseOrderDetail, $purchaseOrderListRow)['candidates'];
    }

    /**
     * @return array{candidates: array<int, array<string, mixed>>, skipped_malformed: int}
     */
    public function extractLatestPriceCandidatesWithStats(array $purchaseOrderDetail, array $purchaseOrderListRow = []): array
    {
        $poId = (int) ($purchaseOrderDetail['id'] ?? $purchaseOrderListRow['id'] ?? 0);
        $poNumber = $purchaseOrderDetail['number'] ?? $purchaseOrderListRow['number'] ?? null;
        $poDate = $this->parseDate($purchaseOrderDetail['transDate'] ?? $purchaseOrderListRow['transDate'] ?? null);
        $sourceUpdatedAt = $this->parseDateTime(
            $purchaseOrderDetail['lastUpdate'] ??
            $purchaseOrderDetail['lastUpdated'] ??
            $purchaseOrderDetail['modifiedTime'] ??
            null
        );

        $detailItems = $purchaseOrderDetail['detailItem'] ?? null;
        if (! is_array($detailItems)) {
            throw new RuntimeException('Payload detail Purchase Order tidak memiliki detailItem yang valid.');
        }

        $candidates = [];
        $skippedMalformed = 0;

        foreach ($detailItems as $line) {
            if (! is_array($line)) {
                $skippedMalformed++;
                continue;
            }

            $itemId = (int) data_get($line, 'item.id', data_get($line, 'itemId', 0));
            $unitId = (int) data_get($line, 'itemUnit.id', data_get($line, 'itemUnitId', 0));
            $unitPrice = $this->normalizeNumberString($line['unitPrice'] ?? null);

            if ($poId <= 0 || $itemId <= 0 || $unitId <= 0 || $unitPrice === null || $poDate === null) {
                $skippedMalformed++;
                continue;
            }

            $candidates[] = [
                'item_accurate_id'          => $itemId,
                'item_no'                   => data_get($line, 'item.no'),
                'item_name'                 => data_get($line, 'item.name'),
                'item_unit_accurate_id'     => $unitId,
                'item_unit_name'            => data_get($line, 'itemUnit.name'),
                'unit_price'                => $unitPrice,
                'purchase_order_accurate_id'=> $poId,
                'purchase_order_number'     => $poNumber,
                'purchase_order_date'       => $poDate,
                'purchase_order_detail_id'  => isset($line['id']) ? (int) $line['id'] : null,
                'source_updated_at'         => $sourceUpdatedAt,
            ];
        }

        return [
            'candidates'         => $candidates,
            'skipped_malformed'  => $skippedMalformed,
        ];
    }

    public function candidateIsNewerThanExisting(array $candidate, mixed $existing): bool
    {
        if ($existing === null) {
            return true;
        }

        $candidateDate = $this->parseDate($candidate['purchase_order_date'] ?? null);
        $existingDate = $this->parseDate($existing->getRawOriginal('purchase_order_date') ?: $existing->getAttributeFromArray('purchase_order_date'));

        if ($candidateDate === null) {
            return false;
        }

        if ($existingDate === null) {
            return true;
        }

        if ($candidateDate->gt($existingDate)) {
            return true;
        }

        if ($candidateDate->lt($existingDate)) {
            return false;
        }

        $candidatePoId = (int) ($candidate['purchase_order_accurate_id'] ?? 0);
        $existingPoId = (int) ($existing->getRawOriginal('purchase_order_accurate_id') ?: $existing->getAttributeFromArray('purchase_order_accurate_id') ?: 0);

        if ($candidatePoId !== $existingPoId) {
            return $candidatePoId > $existingPoId;
        }

        $candidateDetailId = (int) ($candidate['purchase_order_detail_id'] ?? 0);
        $existingDetailId = (int) ($existing->getRawOriginal('purchase_order_detail_id') ?: $existing->getAttributeFromArray('purchase_order_detail_id') ?: 0);

        return $candidateDetailId > $existingDetailId;
    }

    private function logWarning(string $message, array $context = []): void
    {
        if (Facade::getFacadeApplication() === null) {
            return;
        }

        try {
            Log::warning($message, $context);
        } catch (\Throwable) {
            //
        }
    }

    private function sleep(int $sleepMs): void
    {
        if (is_callable($this->sleeper)) {
            ($this->sleeper)($sleepMs);
            return;
        }

        usleep($sleepMs * 1000);
    }


    private function purchaseOrderAlreadyProcessed(int $poId): bool
    {
        if (Facade::getFacadeApplication() === null || ! Facade::getFacadeApplication()->bound('db.schema')) {
            return false;
        }

        if (! Schema::hasTable('accurate_purchase_order_sync_states')) {
            return false;
        }

        return AccuratePurchaseOrderSyncState::query()
            ->where('purchase_order_accurate_id', $poId)
            ->exists();
    }

    private function processPurchaseOrderDetail(int $poId, array $row, array &$stats): void
    {
        $detailResp = $this->client->detailPurchaseOrder($poId);
        if (! ($detailResp['ok'] ?? false)) {
            $stats['failures']++;
            $this->logWarning('[AccuratePOLatestPrice] detail failed', [
                'purchase_order_id' => $poId,
                'status' => $detailResp['status'] ?? null,
            ]);
            return;
        }

        try {
            $detail = $this->extractSuccessfulDetailPayload($detailResp['body'] ?? []);
            $inspection = $this->extractLatestPriceCandidatesWithStats($detail, $row);
        } catch (RuntimeException $e) {
            $stats['failures']++;
            $this->logWarning('[AccuratePOLatestPrice] invalid detail payload', [
                'purchase_order_id' => $poId,
                'message' => $e->getMessage(),
            ]);
            return;
        }

        $stats['details_fetched']++;
        $stats['skipped_malformed'] += $inspection['skipped_malformed'];

        $stats['lines_processed'] += count($inspection['candidates']);

        $this->recordSuccessfulSyncState($detail, $row);
    }

    private function recordSuccessfulSyncState(array $purchaseOrderDetail, array $purchaseOrderListRow): void
    {
        if (Facade::getFacadeApplication() === null || ! Facade::getFacadeApplication()->bound('db.schema')) {
            return;
        }

        if (! Schema::hasTable('accurate_purchase_order_sync_states')) {
            return;
        }

        $poId = (int) ($purchaseOrderDetail['id'] ?? $purchaseOrderListRow['id'] ?? 0);
        if ($poId <= 0) {
            return;
        }

        AccuratePurchaseOrderSyncState::query()->updateOrCreate(
            ['purchase_order_accurate_id' => $poId],
            [
                'purchase_order_number' => $purchaseOrderDetail['number'] ?? $purchaseOrderListRow['number'] ?? null,
                'purchase_order_date' => $this->parseDate($purchaseOrderDetail['transDate'] ?? $purchaseOrderListRow['transDate'] ?? null)?->toDateString(),
                'last_synced_at' => now(),
            ],
        );
    }

    private function extractListRowsOrFail(mixed $body): array
    {
        if (! is_array($body)) {
            throw new RuntimeException('Response daftar Purchase Order Accurate tidak valid.');
        }

        if (($body['s'] ?? null) === false) {
            throw new RuntimeException($this->extractBusinessErrorMessage($body, 'Accurate mengembalikan business error pada daftar Purchase Order.'));
        }

        if (array_key_exists('d', $body)) {
            $rows = $body['d'];
        } elseif (array_key_exists('data', $body)) {
            $rows = $body['data'];
        } elseif (array_key_exists('items', $body)) {
            $rows = $body['items'];
        } else {
            throw new RuntimeException('Payload daftar Purchase Order tidak memiliki data list yang valid.');
        }

        if (! is_array($rows)) {
            throw new RuntimeException('Data daftar Purchase Order Accurate tidak berbentuk array.');
        }

        return $rows;
    }

    private function extractSuccessfulDetailPayload(mixed $body): array
    {
        if (! is_array($body)) {
            throw new RuntimeException('Response detail Purchase Order Accurate tidak valid.');
        }

        if (($body['s'] ?? null) === false) {
            throw new RuntimeException($this->extractBusinessErrorMessage($body, 'Accurate mengembalikan business error pada detail Purchase Order.'));
        }

        $payload = $body['d'] ?? $body['r'] ?? $body;

        if (! is_array($payload)) {
            throw new RuntimeException('Payload detail Purchase Order tidak berbentuk array.');
        }

        return $payload;
    }

    private function extractBusinessErrorMessage(array $body, string $fallback): string
    {
        $message = trim((string) ($body['m'] ?? $body['message'] ?? ''));

        return $message !== '' ? $message : $fallback;
    }

    private function normalizeNumberString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        $clean = preg_replace('/[^\d,\.\-]/', '', (string) $value);
        if ($clean === '' || $clean === null) {
            return null;
        }

        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            $clean = str_replace(',', '', $clean);
        } elseif (str_contains($clean, ',')) {
            $clean = str_replace(',', '.', $clean);
        }

        return is_numeric($clean) ? (string) $clean : null;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->startOfDay();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->startOfDay();
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            if (preg_match('~^\d{1,2}/\d{1,2}/\d{4}$~', $value)) {
                return Carbon::createFromFormat('d/m/Y', $value)->startOfDay();
            }

            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseDateTime(mixed $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
