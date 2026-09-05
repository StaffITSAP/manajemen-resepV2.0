<?php

namespace App\Services\Accurate;

use App\Models\AccurateItem;
use App\Models\AccurateItemUnit;
use App\Models\AccurateItemUnitSyncState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class AccurateItemUnitCacheSyncService
{
    public function __construct(
        private AccurateClient $client,
        private AccurateItemUnitService $unitService,
        private mixed $costValueService = null,
        private mixed $sleeper = null,
    ) {
        if (! $this->costValueService instanceof AccurateCostValueSyncService) {
            if ($this->costValueService !== null && $this->sleeper === null) {
                $this->sleeper = $this->costValueService;
            }

            $this->costValueService = app(AccurateCostValueSyncService::class);
        }
    }

    /**
     * Populate accurate_item_units from local accurate_items and Accurate item/detail.do.
     *
     * @return array<string, int|bool|null|string>
     */
    public function sync(int $limit = 10, ?int $itemAccurateId = null, int $offset = 0, bool $onlyMissing = false, int $sleepMs = 0): array
    {
        $stats = [
            'ok' => true,
            'items_selected' => 0,
            'items_fetched' => 0,
            'units_inserted' => 0,
            'units_updated' => 0,
            'units_unchanged' => 0,
            'stale_units_removed' => 0,
            'items_with_no_populated_units' => 0,
            'cost_values_inserted' => 0,
            'cost_values_updated' => 0,
            'cost_values_unchanged' => 0,
            'stale_cost_values_removed' => 0,
            'skipped_local_items' => 0,
            'failures' => 0,
            'message' => null,
            'next_offset' => null,
        ];

        $items = $this->sourceItems($limit, $itemAccurateId, $offset, $onlyMissing);
        $stats['items_selected'] = $items->count();
        $stats['next_offset'] = $itemAccurateId === null ? $offset + $items->count() : null;
        $stats = $this->syncSelectedItems($items, $stats, $sleepMs, $itemAccurateId === null);

        return $stats;
    }

    /**
     * Process the first deterministic batch of items without success-state rows.
     *
     * @return array<string, int|bool|null|string>
     */
    public function syncSmartMissingStateBatch(int $limit = 50, int $sleepMs = 500, ?int $afterAccurateId = null): array
    {
        $limit = max(1, min($limit, 50));
        $afterAccurateId = $afterAccurateId === null ? null : max(0, $afterAccurateId);

        $stats = [
            'ok' => true,
            'items_selected' => 0,
            'items_fetched' => 0,
            'units_inserted' => 0,
            'units_updated' => 0,
            'units_unchanged' => 0,
            'stale_units_removed' => 0,
            'items_with_no_populated_units' => 0,
            'cost_values_inserted' => 0,
            'cost_values_updated' => 0,
            'cost_values_unchanged' => 0,
            'stale_cost_values_removed' => 0,
            'skipped_local_items' => 0,
            'failures' => 0,
            'message' => null,
            'remaining_candidates' => 0,
            'stage_complete' => false,
            'next_item_accurate_id' => $afterAccurateId,
        ];

        $items = $this->smartMissingStateItems($limit, $afterAccurateId);
        $stats['items_selected'] = $items->count();
        $stats = $this->syncSelectedItems($items, $stats, $sleepMs, true);
        $lastItem = $items->last();
        $stats['next_item_accurate_id'] = $lastItem !== null
            ? (int) $lastItem->accurate_id
            : $afterAccurateId;
        $stats['remaining_candidates'] = $this->smartMissingStateCandidateCount($stats['next_item_accurate_id']);
        $stats['stage_complete'] = $stats['remaining_candidates'] === 0;

        return $stats;
    }

    private function syncSelectedItems($items, array $stats, int $sleepMs, bool $sleepBetweenRequests): array
    {
        $requestCount = 0;

        foreach ($items as $item) {
            if ((int) $item->accurate_id <= 0) {
                $stats['skipped_local_items']++;
                continue;
            }

            if ($requestCount > 0 && $sleepMs > 0 && $sleepBetweenRequests) {
                $this->sleep($sleepMs);
            }

            $response = $this->client->detailItemById((int) $item->accurate_id);
            $requestCount++;

            try {
                $units = $this->unitService->extractUnitsFromResponse($response, $item->accurate_id);
            } catch (RuntimeException $e) {
                $stats['failures']++;
                $this->logWarning('[AccurateItemUnitCache] item detail failed', [
                    'accurate_item_id' => $item->accurate_id,
                    'message' => $e->getMessage(),
                ]);
                continue;
            } catch (Throwable $e) {
                $stats['failures']++;
                $this->logWarning('[AccurateItemUnitCache] unexpected item detail failure', [
                    'accurate_item_id' => $item->accurate_id,
                    'message' => $e->getMessage(),
                ]);
                continue;
            }

            $stats['items_fetched']++;

            if ($units === []) {
                $stats['items_with_no_populated_units']++;
            }

            $result = $this->reconcileItemUnits($item, $units);
            $this->recordSuccessfulSyncState($item, count($units));
            $costValueResult = $this->syncCostValues($item, $response, $stats);

            $stats['units_inserted'] += $result['inserted'];
            $stats['units_updated'] += $result['updated'];
            $stats['units_unchanged'] += $result['unchanged'];
            $stats['stale_units_removed'] += $result['stale_removed'];
            $stats['cost_values_inserted'] += $costValueResult['inserted'];
            $stats['cost_values_updated'] += $costValueResult['updated'];
            $stats['cost_values_unchanged'] += $costValueResult['unchanged'];
            $stats['stale_cost_values_removed'] += $costValueResult['stale_removed'];
        }

        if ($stats['failures'] > 0) {
            $stats['message'] = 'Sinkron selesai dengan sebagian item gagal.';
        }

        return $stats;
    }

    private function sourceItems(int $limit, ?int $itemAccurateId, int $offset = 0, bool $onlyMissing = false)
    {
        $query = AccurateItem::query()
            ->whereNotNull('accurate_id')
            ->where('accurate_id', '>', 0)
            ->orderBy('accurate_id');

        if ($itemAccurateId !== null) {
            $query->where('accurate_id', $itemAccurateId);
        } else {
            if ($onlyMissing) {
                $query->whereNotExists(function ($subquery) {
                    $subquery->selectRaw('1')
                        ->from('accurate_item_units')
                        ->whereColumn('accurate_item_units.item_accurate_id', 'accurate_items.accurate_id');
                });
            }

            if ($offset > 0) {
                $query->offset($offset);
            }

            $query->limit($limit);
        }

        return $query->get();
    }

    private function smartMissingStateItems(int $limit, ?int $afterAccurateId = null)
    {
        $query = AccurateItem::query()
            ->whereNotNull('accurate_id')
            ->where('accurate_id', '>', 0)
            ->whereNotExists(function ($subquery) {
                $subquery->selectRaw('1')
                    ->from('accurate_item_unit_sync_states')
                    ->whereColumn('accurate_item_unit_sync_states.item_accurate_id', 'accurate_items.accurate_id');
            });

        if ($afterAccurateId !== null) {
            $query->where('accurate_id', '>', $afterAccurateId);
        }

        return $query->orderBy('accurate_id')
            ->limit($limit)
            ->get();
    }

    private function smartMissingStateCandidateCount(?int $afterAccurateId = null): int
    {
        $query = AccurateItem::query()
            ->whereNotNull('accurate_id')
            ->where('accurate_id', '>', 0)
            ->whereNotExists(function ($subquery) {
                $subquery->selectRaw('1')
                    ->from('accurate_item_unit_sync_states')
                    ->whereColumn('accurate_item_unit_sync_states.item_accurate_id', 'accurate_items.accurate_id');
            });

        if ($afterAccurateId !== null) {
            $query->where('accurate_id', '>', $afterAccurateId);
        }

        return $query->count();
    }

    private function sleep(int $sleepMs): void
    {
        if (is_callable($this->sleeper)) {
            ($this->sleeper)($sleepMs);
            return;
        }

        usleep($sleepMs * 1000);
    }

    private function syncCostValues(AccurateItem $item, array $response, array &$stats): array
    {
        if (! Schema::hasTable('purchase_item_cost_values')) {
            return ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'stale_removed' => 0];
        }

        try {
            return $this->costValueService->syncFromItemDetailResponse($item, $response);
        } catch (RuntimeException $e) {
            $stats['failures']++;
            $this->logWarning('[AccurateItemUnitCache] cost value refresh failed', [
                'accurate_item_id' => $item->accurate_id,
                'message' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            $stats['failures']++;
            $this->logWarning('[AccurateItemUnitCache] unexpected cost value refresh failure', [
                'accurate_item_id' => $item->accurate_id,
                'message' => $e->getMessage(),
            ]);
        }

        return ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'stale_removed' => 0];
    }

    /**
     * @param array<int, array{id:int, name:string, position:int, source:string}> $units
     * @return array{inserted:int, updated:int, unchanged:int, stale_removed:int}
     */
    private function reconcileItemUnits(AccurateItem $item, array $units): array
    {
        return DB::transaction(function () use ($item, $units) {
            $result = [
                'inserted' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'stale_removed' => 0,
            ];

            $unitIds = array_map(fn(array $unit): int => (int) $unit['id'], $units);

            foreach ($units as $unit) {
                $data = [
                    'accurate_item_id' => $item->id,
                    'item_accurate_id' => (int) $item->accurate_id,
                    'item_no' => $item->no,
                    'item_name' => $item->name,
                    'item_unit_accurate_id' => (int) $unit['id'],
                    'item_unit_name' => $unit['name'],
                    'position' => (int) $unit['position'],
                    'source' => 'accurate_item_detail',
                ];

                $existing = AccurateItemUnit::query()
                    ->where('item_accurate_id', $item->accurate_id)
                    ->where('item_unit_accurate_id', $unit['id'])
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    AccurateItemUnit::create($data + ['synced_at' => now()]);
                    $result['inserted']++;
                    continue;
                }

                if ($this->unitRowMatches($existing, $data)) {
                    $result['unchanged']++;
                    continue;
                }

                $existing->update($data + ['synced_at' => now()]);
                $result['updated']++;
            }

            $staleQuery = AccurateItemUnit::query()
                ->where('item_accurate_id', $item->accurate_id)
                ->lockForUpdate();

            if ($unitIds !== []) {
                $staleQuery->whereNotIn('item_unit_accurate_id', $unitIds);
            }

            $result['stale_removed'] = $staleQuery->delete();

            return $result;
        });
    }

    private function unitRowMatches(AccurateItemUnit $existing, array $data): bool
    {
        foreach ($data as $key => $value) {
            if ((string) $existing->{$key} !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    private function recordSuccessfulSyncState(AccurateItem $item, int $unitCount): void
    {
        if (Facade::getFacadeApplication() === null || ! Facade::getFacadeApplication()->bound('db.schema')) {
            return;
        }

        if (! Schema::hasTable('accurate_item_unit_sync_states')) {
            return;
        }

        AccurateItemUnitSyncState::query()->updateOrCreate(
            ['item_accurate_id' => (int) $item->accurate_id],
            [
                'accurate_item_id' => $item->id,
                'unit_count' => $unitCount,
                'last_synced_at' => now(),
            ],
        );
    }

    private function logWarning(string $message, array $context = []): void
    {
        if (Facade::getFacadeApplication() === null) {
            return;
        }

        Log::warning($message, $context);
    }
}
