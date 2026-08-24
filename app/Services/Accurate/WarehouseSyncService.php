<?php

namespace App\Services\Accurate;

use App\Models\AccurateWarehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class WarehouseSyncService
{
    public function __construct(private AccurateClient $client) {}

    /**
     * Full sync (untuk CLI) – ambil semua halaman sampai habis.
     * Mengembalikan ['ok'=>bool,'total'=>int]
     */
    public function syncAll(): array
    {
        $pageSize = (int) config('accurate.page_size', AccurateClient::DEFAULT_PAGE_SIZE);
        $page = 1;
        $totalInsertedOrUpdated = 0;

        while (true) {
            $res = $this->client->listWarehouses($page, $pageSize);
            $list = (array) Arr::get($res, 'd', Arr::get($res, 'data', [])); // fallback sesuai pola respons

            if (empty($list)) {
                break; // selesai
            }

            DB::transaction(function () use ($list, &$totalInsertedOrUpdated) {
                foreach ($list as $wh) {
                    // Pastikan punya id; ambil detail untuk informasi lengkap
                    $id = (int) ($wh['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }

                    // Panggil detail (biasanya lebih lengkap)
                    $detail = $this->client->detailWarehouse($id);
                    $payload = (array) Arr::get($detail, 'd', $detail);

                    $row = [
                        'accurate_id' => $id,
                        'code'        => $payload['code'] ?? ($wh['code'] ?? null),
                        'name'        => $payload['name'] ?? ($wh['name'] ?? null),
                        'active'      => (bool) ($payload['active'] ?? $payload['isActive'] ?? true),
                        'phone'       => $payload['phone'] ?? null,
                        'email'       => $payload['email'] ?? null,
                        'contact_person' => $payload['contact'] ?? $payload['contactPerson'] ?? null,
                        'address'     => $payload['address'] ?? null,
                        'city'        => $payload['city'] ?? null,
                        'zip'         => $payload['zip'] ?? null,
                        'province'    => $payload['province'] ?? null,
                        'country'     => $payload['country'] ?? null,
                        'raw'         => $payload ?: $wh,
                    ];

                    // Jika ada lastUpdated dari Accurate, simpan ke remote_updated_at
                    if (!empty($payload['lastUpdated'])) {
                        $row['remote_updated_at'] = CarbonImmutable::parse($payload['lastUpdated']);
                    } elseif (!empty($payload['modifiedTime'])) {
                        $row['remote_updated_at'] = CarbonImmutable::parse($payload['modifiedTime']);
                    }

                    AccurateWarehouse::updateOrCreate(
                        ['accurate_id' => $id],
                        $row
                    );

                    $totalInsertedOrUpdated++;
                }
            });

            $page++;
        }

        return ['ok' => true, 'total' => $totalInsertedOrUpdated];
    }

    /**
     * Quick sync (untuk dipanggil dari UI) – hanya n halaman terakhir.
     * Tujuannya supaya eksekusi < 30s di request web.
     */
    public function syncLatest(int $lastNPages = null): array
    {
        $pageSize = (int) config('accurate.page_size', AccurateClient::DEFAULT_PAGE_SIZE);
        $n = $lastNPages ?? (int) config('accurate.sync_latest_limit', 15);

        // Narik N halaman terakhir berarti kita coba mundur dari page 1? (API Accurate tidak expose total page)
        // Strategi aman: tarik page 1..N saja (biasanya list sudah diurut DESC created/updated).
        // Bila diperlukan, bisa ditambah parameter sort di client.
        $total = 0;

        for ($page = 1; $page <= $n; $page++) {
            $res = $this->client->listWarehouses($page, $pageSize);
            $list = (array) Arr::get($res, 'd', Arr::get($res, 'data', []));
            if (empty($list)) break;

            DB::transaction(function () use ($list, &$total) {
                foreach ($list as $wh) {
                    $id = (int) ($wh['id'] ?? 0);
                    if ($id <= 0) continue;

                    $detail = $this->client->detailWarehouse($id);
                    $payload = (array) ($detail['d'] ?? $detail);

                    $row = [
                        'accurate_id' => $id,
                        'code'        => $payload['code'] ?? ($wh['code'] ?? null),
                        'name'        => $payload['name'] ?? ($wh['name'] ?? null),
                        'active'      => (bool) ($payload['active'] ?? $payload['isActive'] ?? true),
                        'phone'       => $payload['phone'] ?? null,
                        'email'       => $payload['email'] ?? null,
                        'contact_person' => $payload['contact'] ?? $payload['contactPerson'] ?? null,
                        'address'     => $payload['address'] ?? null,
                        'city'        => $payload['city'] ?? null,
                        'zip'         => $payload['zip'] ?? null,
                        'province'    => $payload['province'] ?? null,
                        'country'     => $payload['country'] ?? null,
                        'raw'         => $payload ?: $wh,
                    ];

                    if (!empty($payload['lastUpdated'])) {
                        $row['remote_updated_at'] = CarbonImmutable::parse($payload['lastUpdated']);
                    } elseif (!empty($payload['modifiedTime'])) {
                        $row['remote_updated_at'] = CarbonImmutable::parse($payload['modifiedTime']);
                    }

                    AccurateWarehouse::updateOrCreate(['accurate_id' => $id], $row);
                    $total++;
                }
            });
        }

        return ['ok' => true, 'total' => $total];
    }
}
