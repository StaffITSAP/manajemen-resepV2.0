<?php

namespace App\Services\Accurate;

use App\Models\AccurateItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class AccurateItemUnitService
{
    public function __construct(private AccurateClient $client) {}

    /**
     * Return normalized Accurate units for one local item.
     *
     * @return array<int, array{id:int, name:string, position:int, source:string}>
     */
    public function unitsForItem(AccurateItem $item, int $ttlSeconds = 1200): array
    {
        return $this->unitsForItemId((int) $item->accurate_id, $ttlSeconds);
    }

    /**
     * Return normalized Accurate units for one Accurate item id.
     *
     * @return array<int, array{id:int, name:string, position:int, source:string}>
     */
    public function unitsForItemId(int|string $accurateItemId, int $ttlSeconds = 1200): array
    {
        $id = (string) $accurateItemId;
        $cacheKey = "accurate:item:{$id}:units";

        return Cache::remember($cacheKey, $ttlSeconds, function () use ($accurateItemId) {
            return $this->extractUnitsFromResponse(
                $this->client->detailItemById($accurateItemId),
                $accurateItemId,
            );
        });
    }

    /**
     * @param array{ok?:bool,status?:int,body?:mixed} $response
     * @return array<int, array{id:int, name:string, position:int, source:string}>
     */
    public function extractUnitsFromResponse(array $response, int|string|null $accurateItemId = null): array
    {
        if (! ($response['ok'] ?? false)) {
            Log::warning('[AccurateItemUnit] failed to fetch item detail', [
                'accurate_item_id' => $accurateItemId,
                'status'           => $response['status'] ?? null,
            ]);

            throw new RuntimeException('Gagal mengambil detail item dari Accurate.');
        }

        $detail = $this->extractSuccessfulDetailPayload($response['body'] ?? []);

        return $this->normalizeUnitsFromDetail($detail);
    }

    /**
     * Normalize only populated unit1..unit5.
     *
     * @return array<int, array{id:int, name:string, position:int, source:string}>
     */
    public function normalizeUnitsFromDetail(array $detail): array
    {
        $units = [];
        $seenIds = [];
        $seenNames = [];

        for ($i = 1; $i <= 5; $i++) {
            $unit = $detail["unit{$i}"] ?? null;
            $normalized = $this->normalizeUnit($unit, $i, "unit{$i}");

            if ($normalized === null) {
                continue;
            }

            $this->appendUniqueUnit($units, $seenIds, $seenNames, $normalized);
        }

        return array_values($units);
    }

    private function extractSuccessfulDetailPayload(mixed $body): array
    {
        if (! is_array($body)) {
            throw new RuntimeException('Response detail item Accurate tidak valid.');
        }

        if (($body['s'] ?? null) === false) {
            $message = trim((string) ($body['m'] ?? $body['message'] ?? ''));
            throw new RuntimeException($message !== '' ? $message : 'Accurate mengembalikan business error pada detail item.');
        }

        $payload = $body['d'] ?? $body['r'] ?? $body;

        if (! is_array($payload)) {
            throw new RuntimeException('Payload detail item Accurate tidak berbentuk array.');
        }

        return $payload;
    }

    /**
     * @return array{id:int, name:string, position:int, source:string}|null
     */
    private function normalizeUnit(mixed $unit, int $position, string $source): ?array
    {
        if (! is_array($unit)) {
            return null;
        }

        $id = (int) ($unit['id'] ?? 0);
        $name = trim((string) ($unit['name'] ?? $unit['unitName'] ?? $unit['uomName'] ?? ''));

        if ($id <= 0 || $name === '') {
            return null;
        }

        return [
            'id'       => $id,
            'name'     => $name,
            'position' => $position,
            'source'   => $source,
        ];
    }

    /**
     * @param array<int, array{id:int, name:string, position:int, source:string}> $units
     * @param array<int, bool> $seenIds
     * @param array<string, bool> $seenNames
     * @param array{id:int, name:string, position:int, source:string} $unit
     */
    private function appendUniqueUnit(array &$units, array &$seenIds, array &$seenNames, array $unit): void
    {
        $normalizedName = Str::of($unit['name'])
            ->lower()
            ->replace(['.', '  '], ['', ' '])
            ->trim()
            ->value();

        if (isset($seenIds[$unit['id']]) || isset($seenNames[$normalizedName])) {
            return;
        }

        $seenIds[$unit['id']] = true;
        $seenNames[$normalizedName] = true;
        $units[] = $unit;
    }
}
