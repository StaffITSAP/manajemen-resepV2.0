<?php

namespace App\Console\Commands;

use App\Services\Accurate\PurchaseOrderLatestPriceSyncService;
use InvalidArgumentException;
use Illuminate\Console\Command;
use Throwable;

class AccurateSyncPurchaseLatestPrices extends Command
{
    protected $signature = 'accurate:sync-purchase-latest-prices
                            {--page=1 : Halaman awal purchase-order/list.do}
                            {--page-size=10 : Jumlah PO per halaman, maksimum 100}
                            {--max-pages=1 : Jumlah maksimum halaman yang diproses}
                            {--max-details= : Jumlah maksimum detail PO yang diambil per invocation, kosong berarti tanpa cap tambahan}
                            {--sleep-ms=0 : Jeda antar GET purchase-order/detail.do dalam milidetik}';

    protected $description = 'Sinkron cache harga beli terakhir dari Approved Purchase Order Accurate.';

    public function handle(PurchaseOrderLatestPriceSyncService $service): int
    {
        try {
            ['page' => $page, 'page_size' => $pageSize, 'max_pages' => $maxPages, 'max_details' => $maxDetails, 'sleep_ms' => $sleepMs] = $this->validatedOptions();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info('Mulai sinkron cache harga beli terakhir dari Accurate.');
        $this->line("page={$page}, page-size={$pageSize}, max-pages={$maxPages}, max-details={$maxDetails}, sleep-ms={$sleepMs}");

        try {
            $result = $service->sync($page, $pageSize, $maxPages, $maxDetails, $sleepMs);
        } catch (Throwable $e) {
            $this->error('Gagal sinkron: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->line('PO list rows: ' . $result['purchase_orders']);
        $this->line('PO details fetched: ' . $result['details_fetched']);
        $this->line('Lines processed: ' . $result['lines_processed']);
        $this->line('Latest prices inserted: ' . $result['inserted']);
        $this->line('Latest prices updated: ' . $result['updated']);
        $this->line('Unchanged: ' . $result['unchanged']);
        $this->line('Skipped/malformed lines: ' . $result['skipped_malformed']);
        $this->line('Failures: ' . $result['failures']);

        if (! ($result['ok'] ?? false)) {
            $this->error($result['message'] ?: 'Sinkron selesai dengan error fatal.');
            return self::FAILURE;
        }

        $this->info('Sinkron cache harga beli terakhir selesai.');
        return self::SUCCESS;
    }

    /**
     * @param array{page?:mixed,page-size?:mixed,max-pages?:mixed,max-details?:mixed,sleep-ms?:mixed}|null $options
     * @return array{page:int,page_size:int,max_pages:int,max_details:int|null,sleep_ms:int}
     */
    public function validatedOptions(?array $options = null): array
    {
        $options ??= [
            'page'      => $this->option('page'),
            'page-size' => $this->option('page-size'),
            'max-pages' => $this->option('max-pages'),
            'max-details' => $this->option('max-details'),
            'sleep-ms' => $this->option('sleep-ms'),
        ];

        return [
            'page'      => $this->validatePositiveIntegerOption('page', $options['page'] ?? null),
            'page_size' => $this->validatePositiveIntegerOption('page-size', $options['page-size'] ?? null),
            'max_pages' => $this->validatePositiveIntegerOption('max-pages', $options['max-pages'] ?? null),
            'max_details' => blank($options['max-details'] ?? null)
                ? null
                : $this->validatePositiveIntegerOption('max-details', $options['max-details']),
            'sleep_ms' => $this->validateNonNegativeIntegerOption('sleep-ms', $options['sleep-ms'] ?? 0, 10000),
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
