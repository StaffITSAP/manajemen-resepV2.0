<?php

namespace App\Console\Commands;

use App\Services\Accurate\AccurateItemUnitCacheSyncService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class AccurateSyncItemUnits extends Command
{
    protected $signature = 'accurate:sync-item-units
                            {--limit=10 : Jumlah item lokal yang diproses, gunakan kecil untuk uji awal}
                            {--offset=0 : Lewati sejumlah item lokal untuk batch berikutnya}
                            {--only-missing : Proses hanya item yang belum memiliki satuan di cache lokal}
                            {--sleep-ms=0 : Jeda antar GET item/detail.do dalam milidetik}
                            {--item-id= : Remote Accurate item ID spesifik, bukan local accurate_items.id}';

    protected $description = 'Sinkron cache satuan item Accurate dari endpoint GET item/detail.do.';

    public function handle(AccurateItemUnitCacheSyncService $service): int
    {
        try {
            ['limit' => $limit, 'offset' => $offset, 'only_missing' => $onlyMissing, 'sleep_ms' => $sleepMs, 'item_id' => $itemId] = $this->validatedOptions();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info('Mulai sinkron cache satuan item Accurate.');
        $this->line('limit=' . $limit . ', offset=' . $offset . ', only-missing=' . ($onlyMissing ? 'yes' : 'no') . ', sleep-ms=' . $sleepMs . ', item-id=' . ($itemId ?? '-'));

        try {
            $result = $service->sync($limit, $itemId, $offset, $onlyMissing, $sleepMs);
        } catch (Throwable $e) {
            $this->error('Gagal sinkron: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->line('Items selected: ' . $result['items_selected']);
        $this->line('Items fetched: ' . $result['items_fetched']);
        $this->line('Units inserted: ' . $result['units_inserted']);
        $this->line('Units updated: ' . $result['units_updated']);
        $this->line('Units unchanged: ' . $result['units_unchanged']);
        $this->line('Stale units removed: ' . $result['stale_units_removed']);
        $this->line('Items with no populated units: ' . $result['items_with_no_populated_units']);
        $this->line('Skipped local items: ' . $result['skipped_local_items']);
        $this->line('Failures: ' . $result['failures']);

        if (($result['message'] ?? null) !== null) {
            $this->warn((string) $result['message']);
        }

        $this->info('Sinkron cache satuan item Accurate selesai.');
        return self::SUCCESS;
    }

    /**
     * @param array{limit?:mixed,offset?:mixed,only-missing?:mixed,sleep-ms?:mixed,item-id?:mixed}|null $options
     * @return array{limit:int,offset:int,only_missing:bool,sleep_ms:int,item_id:int|null}
     */
    public function validatedOptions(?array $options = null): array
    {
        $options ??= [
            'limit' => $this->option('limit'),
            'offset' => $this->option('offset'),
            'only-missing' => $this->option('only-missing'),
            'sleep-ms' => $this->option('sleep-ms'),
            'item-id' => $this->option('item-id'),
        ];

        $itemId = $options['item-id'] ?? null;

        return [
            'limit' => $this->validatePositiveIntegerOption('limit', $options['limit'] ?? null),
            'offset' => $this->validateNonNegativeIntegerOption('offset', $options['offset'] ?? 0),
            'only_missing' => (bool) ($options['only-missing'] ?? false),
            'sleep_ms' => $this->validateNonNegativeIntegerOption('sleep-ms', $options['sleep-ms'] ?? 0, 10000),
            'item_id' => blank($itemId) ? null : $this->validatePositiveIntegerOption('item-id', $itemId),
        ];
    }

    public function validatePositiveIntegerOption(string $name, mixed $value): int
    {
        $stringValue = trim((string) $value);

        if ($stringValue === '' || ! preg_match('/^[1-9]\d*$/', $stringValue)) {
            throw new InvalidArgumentException("Opsi --{$name} harus berupa bilangan bulat positif.");
        }

        return (int) $stringValue;
    }

    public function validateNonNegativeIntegerOption(string $name, mixed $value, int $max = PHP_INT_MAX): int
    {
        $stringValue = trim((string) $value);

        if ($stringValue === '' || ! preg_match('/^\d+$/', $stringValue)) {
            throw new InvalidArgumentException("Opsi --{$name} harus berupa bilangan bulat 0 atau lebih.");
        }

        $integerValue = (int) $stringValue;
        if ($integerValue > $max) {
            throw new InvalidArgumentException("Opsi --{$name} tidak boleh lebih dari {$max}.");
        }

        return $integerValue;
    }
}
