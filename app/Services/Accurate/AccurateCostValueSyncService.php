<?php

namespace App\Services\Accurate;

use App\Models\AccurateItem;
use App\Models\PurchaseItemCostValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AccurateCostValueSyncService
{
    public function syncFromItemDetailResponse(AccurateItem $item, array $response): array
    {
        $detail = $this->detailFromResponse($response);
        $balanceUnitCost = $this->positiveNumber($detail['balanceUnitCost'] ?? null);

        if ($balanceUnitCost === null) {
            return $this->removeRowsForItem($item);
        }

        $rows = $this->rowsFromDetail($item, $detail, $balanceUnitCost);

        return $this->reconcileRowsForItem($item, $rows);
    }

    public function syncItem(AccurateItem $item, AccurateClient $client): array
    {
        return $this->syncFromItemDetailResponse($item, $client->detailItemById((int) $item->accurate_id));
    }

    private function detailFromResponse(array $response): array
    {
        if (! ($response['ok'] ?? false)) {
            Log::warning('[AccurateCostValue] item detail failed', [
                'status' => $response['status'] ?? null,
            ]);

            throw new RuntimeException('Gagal mengambil detail item dari Accurate.');
        }

        $body = $response['body'] ?? null;
        if (! is_array($body)) {
            throw new RuntimeException('Response detail item Accurate tidak valid.');
        }

        if (($body['s'] ?? null) === false) {
            $message = trim((string) ($body['m'] ?? $body['message'] ?? ''));
            throw new RuntimeException($message !== '' ? $message : 'Accurate mengembalikan business error pada detail item.');
        }

        $detail = $body['d'] ?? $body['r'] ?? $body;
        if (! is_array($detail)) {
            throw new RuntimeException('Payload detail item Accurate tidak berbentuk array.');
        }

        return $detail;
    }

    private function rowsFromDetail(AccurateItem $item, array $detail, string $balanceUnitCost): array
    {
        $rows = [];
        $balanceTotalCost = $this->numberOrNull($detail['balanceTotalCost'] ?? null);

        for ($position = 1; $position <= 5; $position++) {
            $unit = $this->unitFromDetail($detail, $position);
            if ($position === 1 && $unit === null) {
                throw new RuntimeException('Payload detail item tidak memiliki unit1 yang valid.');
            }

            if ($unit === null) {
                continue;
            }

            $ratio = $position === 1 ? null : $this->positiveNumber($detail["ratio{$position}"] ?? null);
            if ($position > 1 && $ratio === null) {
                continue;
            }

            $unitPrice = $position === 1
                ? $balanceUnitCost
                : $this->multiply($balanceUnitCost, (string) $ratio, 8);

            if ((float) $unitPrice <= 0.0) {
                continue;
            }

            $rows[] = [
                'accurate_item_id' => $item->id,
                'item_accurate_id' => (int) $item->accurate_id,
                'item_no' => $detail['no'] ?? $item->no,
                'item_name' => $detail['name'] ?? $item->name,
                'item_unit_accurate_id' => $unit['id'],
                'item_unit_name' => $unit['name'],
                'unit_position' => $position,
                'unit_price' => $this->formatDecimal($unitPrice, 8),
                'balance_unit_cost' => $this->formatDecimal($balanceUnitCost, 8),
                'ratio' => $ratio === null ? null : $this->formatDecimal($ratio, 12),
                'balance_total_cost' => $balanceTotalCost === null ? null : $this->formatDecimal($balanceTotalCost, 8),
                'source_hash' => sha1(json_encode([
                    (int) $item->accurate_id,
                    $unit['id'],
                    $position,
                    $unitPrice,
                    $balanceUnitCost,
                    $ratio,
                    $balanceTotalCost,
                ], JSON_THROW_ON_ERROR)),
                'synced_at' => now(),
            ];
        }

        return $rows;
    }

    private function unitFromDetail(array $detail, int $position): ?array
    {
        $unit = $detail["unit{$position}"] ?? null;
        if (! is_array($unit)) {
            return null;
        }

        $id = (int) ($unit['id'] ?? 0);
        $name = trim((string) ($unit['name'] ?? $unit['unitName'] ?? $unit['uomName'] ?? ''));

        return $id > 0 && $name !== '' ? ['id' => $id, 'name' => $name] : null;
    }

    private function reconcileRowsForItem(AccurateItem $item, array $rows): array
    {
        return DB::transaction(function () use ($item, $rows): array {
            $result = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'stale_removed' => 0];
            $unitIds = [];

            foreach ($rows as $row) {
                $unitIds[] = (int) $row['item_unit_accurate_id'];
                $existing = PurchaseItemCostValue::query()
                    ->where('item_accurate_id', $row['item_accurate_id'])
                    ->where('item_unit_accurate_id', $row['item_unit_accurate_id'])
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    PurchaseItemCostValue::create($row);
                    $result['inserted']++;
                    continue;
                }

                if ($this->rowMatches($existing, $row)) {
                    $existing->update(['synced_at' => $row['synced_at']]);
                    $result['unchanged']++;
                    continue;
                }

                $existing->update($row);
                $result['updated']++;
            }

            $stale = PurchaseItemCostValue::query()
                ->where('item_accurate_id', (int) $item->accurate_id)
                ->lockForUpdate();

            if ($unitIds !== []) {
                $stale->whereNotIn('item_unit_accurate_id', $unitIds);
            }

            $result['stale_removed'] = $stale->delete();

            return $result;
        });
    }

    private function removeRowsForItem(AccurateItem $item): array
    {
        return DB::transaction(function () use ($item): array {
            $removed = PurchaseItemCostValue::query()
                ->where('item_accurate_id', (int) $item->accurate_id)
                ->lockForUpdate()
                ->delete();

            return ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'stale_removed' => $removed];
        });
    }

    private function rowMatches(PurchaseItemCostValue $existing, array $row): bool
    {
        foreach (array_diff(array_keys($row), ['synced_at']) as $key) {
            if ((string) $existing->{$key} !== (string) $row[$key]) {
                return false;
            }
        }

        return true;
    }

    private function positiveNumber(mixed $value): ?string
    {
        $number = $this->numberOrNull($value);

        return $number !== null && (float) $number > 0.0 ? $number : null;
    }

    private function numberOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (string) $value;
    }

    private function multiply(string $left, string $right, int $scale): string
    {
        if (function_exists('bcmul')) {
            return bcmul($left, $right, $scale);
        }

        return number_format(((float) $left) * ((float) $right), $scale, '.', '');
    }

    private function formatDecimal(string $value, int $scale): string
    {
        return number_format((float) $value, $scale, '.', '');
    }
}
