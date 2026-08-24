<?php

namespace App\Services\Accurate;

use App\Models\AccurateBranch;
use App\Models\AccurateJobOrder;
use App\Models\AccurateJobOrderItem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class JobOrderSyncService
{
    public function __construct(private AccurateClient $client) {}

    /** Cache mapping accurate_branch_id => branches.id agar tidak query berulang. */
    protected ?array $branchIdMap = null;

    /** Lazy load branch map (accurate_id => id). */
    protected function getBranchIdMap(): array
    {
        if ($this->branchIdMap === null) {
            $this->branchIdMap = AccurateBranch::query()
                ->pluck('id', 'accurate_id')  // [accurate_id => id]
                ->toArray();
        }
        return $this->branchIdMap;
    }

    /** Helper: parse tanggal "dd/MM/yyyy" atau "yyyy-MM-dd" -> "Y-m-d" */
    protected function parseDate(?string $src): ?string
    {
        if (!$src) return null;

        // Jika format "dd/MM/yyyy"
        if (preg_match('~^\d{2}/\d{2}/\d{4}$~', $src)) {
            $ts = strtotime(str_replace('/', '-', $src)); // dd-mm-yyyy
            return $ts ? date('Y-m-d', $ts) : null;
        }

        // Biarkan parser PHP coba deteksi
        $ts = strtotime($src);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    /** Helper: normalisasi angka ke string (agar aman disimpan sebagai DECIMAL/string) */
    protected function numToString(mixed $v): string
    {
        if ($v === null || $v === '') return '0';
        if (is_numeric($v)) return (string) $v;
        // jika 1.234,56 atau 1,234.56 dll, bersihkan
        $clean = preg_replace('/[^\d\.\-]/', '', (string) $v);
        return $clean === '' ? '0' : $clean;
    }

    /**
     * Simpan 1 Job Order (detail.do) secara idempotent.
     */
    public function fetchAndStoreOne(int $accurateId): ?\App\Models\AccurateJobOrder
    {
        try {
            $detail = $this->client->detailJobOrder($accurateId);
            if (!($detail['ok'] ?? false)) {
                Log::warning('[AccurateSyncJO] gagal ambil detail', ['id' => $accurateId, 'status' => $detail['status'] ?? null]);
                return null;
            }

            $payload = $detail['body'] ?? [];
            $d = $payload['d'] ?? $payload;
            if (!$d || !is_array($d)) {
                Log::warning('[AccurateSyncJO] payload kosong/invalid', ['id' => $accurateId]);
                return null;
            }

            // ==== siapkan helper lokal ====
            $parseDate = function (?string $src): ?string {
                if (!$src) return null;
                if (preg_match('~^\d{2}/\d{2}/\d{4}$~', $src)) {
                    $ts = strtotime(str_replace('/', '-', $src));
                    return $ts ? date('Y-m-d', $ts) : null;
                }
                $ts = strtotime($src);
                return $ts ? date('Y-m-d', $ts) : null;
            };
            $numStr = function ($v): string {
                if ($v === null || $v === '') return '0';
                if (is_numeric($v)) return (string) $v;
                $clean = preg_replace('/[^\d\.\-]/', '', (string) $v);
                return $clean === '' ? '0' : $clean;
            };

            // branch map (optional; boleh dihapus kalau tidak pakai branch_id)
            static $branchMap;
            if ($branchMap === null) {
                $branchMap = \App\Models\AccurateBranch::query()->pluck('id', 'accurate_id')->toArray();
            }
            $branchModelId = null;
            $branchAccId   = (int)($d['branchId'] ?? 0);
            if ($branchAccId) $branchModelId = $branchMap[$branchAccId] ?? null;

            $now = now();

            // ==== transaksi agar header+detail konsisten ====
            return DB::transaction(function () use ($d, $parseDate, $numStr, $now, $branchModelId) {

                // ---------- HEADER: updateOrInsert ----------
                $headerWhere = ['accurate_id' => (int)($d['id'])];
                $headerData  = [
                    'number'                => $d['number'] ?? null,
                    'trans_date'            => $parseDate($d['transDate'] ?? null),
                    'status'                => $d['status'] ?? null,
                    'status_name'           => $d['statusName'] ?? null,
                    'rollover_number'       => $d['rollOver']['number'] ?? null,
                    'warehouse_name'        => $d['detailItem'][0]['warehouse']['name'] ?? null,
                    'total_item'            => $numStr($d['totalItem'] ?? 0),
                    'total_amount'          => $numStr($d['totalAmount'] ?? 0),
                    'job_account_no'        => $d['jobAccount']['no']        ?? null,
                    'difference_account_no' => $d['differenceAccount']['no'] ?? null,
                    'raw'                   => json_encode($d, JSON_UNESCAPED_UNICODE),
                    'branch_id'             => $branchModelId,
                    'updated_at'            => $now,
                ];

                // Jika insert baru, tambahkan created_at
                $existed = DB::table('accurate_job_orders')->where($headerWhere)->exists();
                if (!$existed) {
                    $headerData['created_at'] = $now;
                }

                DB::table('accurate_job_orders')->updateOrInsert($headerWhere, $headerData);

                // Ambil ID model untuk relasi detail
                $joId = DB::table('accurate_job_orders')->where($headerWhere)->value('id');

                // ---------- DETAIL: upsert ----------
                $detailItems = $d['detailItem'] ?? [];
                $upsertRows  = [];
                $newDetailIds = [];

                foreach ($detailItems as $it) {
                    $detailId = (int)($it['id'] ?? 0);
                    if ($detailId <= 0) continue;
                    $newDetailIds[] = $detailId;

                    $upsertRows[] = [
                        'job_order_id'       => $joId,
                        'accurate_detail_id' => $detailId,
                        'item_id'            => $it['itemId'] ?? ($it['item']['id'] ?? null),
                        'item_no'            => $it['item']['no'] ?? null,
                        'item_name'          => $it['item']['name'] ?? ($it['detailName'] ?? null),
                        'unit_name'          => $it['itemUnit']['name'] ?? null,
                        'warehouse_name'     => $it['warehouse']['name'] ?? null,
                        'quantity'           => $numStr($it['quantity'] ?? 0),
                        'amount'             => $numStr($it['amount'] ?? 0),
                        'raw'                => json_encode($it, JSON_UNESCAPED_UNICODE),
                        'created_at'         => $now,
                        'updated_at'         => $now,
                    ];
                }

                // Pastikan ada UNIQUE key: (job_order_id, accurate_detail_id)
                if (!empty($upsertRows)) {
                    DB::table('accurate_job_order_items')->upsert(
                        $upsertRows,
                        ['job_order_id', 'accurate_detail_id'],
                        [
                            'item_id',
                            'item_no',
                            'item_name',
                            'unit_name',
                            'warehouse_name',
                            'quantity',
                            'amount',
                            'raw',
                            'updated_at',
                        ]
                    );
                }

                // Hapus detail yang sudah tidak ada di Accurate (sinkron replace)
                $existingDetailIds = DB::table('accurate_job_order_items')
                    ->where('job_order_id', $joId)
                    ->pluck('accurate_detail_id')
                    ->map(fn($v) => (int)$v)
                    ->all();

                $toDelete = array_diff($existingDetailIds, $newDetailIds);
                if (!empty($toDelete)) {
                    DB::table('accurate_job_order_items')
                        ->where('job_order_id', $joId)
                        ->whereIn('accurate_detail_id', $toDelete)
                        ->delete();
                }

                // --- LOG ringkas untuk verifikasi ---
                Log::info('[AccurateSyncJO] stored one', [
                    'accurate_id'   => (int)($d['id']),
                    'jo_id'         => $joId,
                    'items_upsert'  => count($upsertRows),
                    'items_deleted' => count($toDelete ?? []),
                    'is_new'        => !$existed,
                ]);

                // Kembalikan instance model (opsional)
                return \App\Models\AccurateJobOrder::find($joId);
            });
        } catch (Throwable $e) {
            Log::error('[AccurateSyncJO] fetchAndStoreOne error', [
                'id'  => $accurateId,
                'msg' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Full Sync — aman anti timeout: ambil per 100, iterasi sampai last page,
     * pecah permintaan detail per chunk kecil, jeda antar chunk.
     */
    public function fullSync(?int $maxPage = null, ?int $pageSize = null): array
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        // Paksa maksimum 100 untuk stabilitas (bisa kurang via config)
        $cfgSize  = (int) config('accurate.page_size', 100);
        $pageSize = (int) ($pageSize ?: $cfgSize);
        if ($pageSize > 100) $pageSize = 100;

        $page        = 1;
        $chunkSize   = (int) config('accurate.sync_per_loop', 20); // jumlah JO detail per batch
        if ($chunkSize < 1) $chunkSize = 10;
        $delaySec    = (int) config('accurate.delay_per_loop', 1); // jeda antar batch

        $created = 0;
        $updated = 0;
        $scanned = 0;
        $lastPage = null;

        Log::info('[AccurateSyncJO] mulai fullSync', [
            'pageSize' => $pageSize,
            'maxPage'  => $maxPage,
            'chunk'    => $chunkSize,
            'delay'    => $delaySec,
        ]);

        while (true) {
            if ($maxPage && $page > $maxPage) {
                Log::info('[AccurateSyncJO] stop karena melewati maxPage', compact('page', 'maxPage'));
                break;
            }

            $resp = $this->client->listJobOrders($page, $pageSize);
            if (!($resp['ok'] ?? false)) {
                Log::warning('[AccurateSyncJO] gagal ambil list', ['page' => $page, 'status' => $resp['status'] ?? null]);
                break;
            }

            $body      = $resp['body'] ?? [];
            $sp        = Arr::get($body, 'sp', []);
            $listData  = Arr::get($body, 'd', Arr::get($body, 'data', Arr::get($body, 'items', [])));
            $listData  = is_array($listData) ? $listData : [];

            $ids = [];
            foreach ($listData as $row) {
                $id = (int) Arr::get($row, 'id', 0);
                if ($id > 0) $ids[] = $id;
            }

            $currPage  = (int) ($sp['page'] ?? $page);
            $pageCount = isset($sp['pageCount']) ? (int) $sp['pageCount'] : null;

            $scanned += count($ids);

            Log::info('[AccurateSyncJO] page fetched', [
                'page'      => $currPage,
                'count'     => count($ids),
                'pageSize'  => (int) ($sp['pageSize'] ?? $pageSize),
                'pageCount' => $pageCount,
            ]);

            if (empty($ids)) {
                $lastPage = $currPage;
                break;
            }

            // ==== Batch cek eksistensi sekali, bukan per-ID ====
            $existingMap = AccurateJobOrder::query()
                ->whereIn('accurate_id', $ids)
                ->pluck('id', 'accurate_id') // [accurate_id => id]
                ->toArray();

            // ==== Pecah jadi beberapa chunk kecil untuk panggil detail ====
            foreach (array_chunk($ids, $chunkSize) as $chunk) {
                foreach ($chunk as $id) {
                    $isExisting = array_key_exists($id, $existingMap);

                    $jo = $this->fetchAndStoreOne($id);
                    if ($jo) {
                        if ($isExisting) $updated++;
                        else $created++;
                    }
                }

                if ($delaySec > 0) {
                    sleep($delaySec); // jeda kecil untuk tepis rate limit / spike
                }
            }

            // ==== Deteksi last page ====
            if ($pageCount !== null) {
                if ($currPage >= $pageCount) {
                    $lastPage = $currPage;
                    break;
                }
            } else {
                // fallback: jika jumlah item < pageSize maka last page
                if (count($ids) < $pageSize) {
                    $lastPage = $currPage;
                    break;
                }
            }

            $page++;
        }

        $result = [
            'created'   => $created,
            'updated'   => $updated,
            'total_ids' => $scanned,
            'last_page' => $lastPage ?? ($page - 1),
        ];
        Log::info('[AccurateSyncJO] selesai fullSync', $result);
        return $result;
    }

    /**
     * Sinkron cepat (UI button) — ambil halaman 1 saja, fetch detail untuk JO baru.
     */
    public function syncLatest(): array
    {
        // Halaman 1 dengan size kecil untuk responsiveness
        $listSize = min(50, (int) config('accurate.page_size', 100));

        $resp = $this->client->listJobOrders(1, $listSize);
        if (!($resp['ok'] ?? false)) {
            return ['created' => 0, 'updated' => 0, 'total' => 0];
        }

        $body = $resp['body'] ?? [];
        $rows = Arr::get($body, 'd', Arr::get($body, 'data', []));
        $rows = is_array($rows) ? $rows : [];

        $ids = [];
        foreach ($rows as $r) {
            $id = (int) Arr::get($r, 'id', 0);
            if ($id > 0) $ids[] = $id;
        }

        $existing = AccurateJobOrder::query()
            ->whereIn('accurate_id', $ids)
            ->pluck('accurate_id')
            ->all();

        $existingSet = array_fill_keys(array_map('intval', $existing), true);

        $limit   = (int) config('accurate.sync_latest_limit', 15);
        $created = 0;
        $updated = count($existing);
        $total   = count($ids);

        foreach ($ids as $id) {
            if (isset($existingSet[$id])) {
                continue;
            }
            if ($created >= $limit) break;

            $jo = $this->fetchAndStoreOne($id);
            if ($jo) $created++;

            usleep(300_000); // 0.3 detik jeda aman
        }

        return compact('created', 'updated', 'total');
    }
}
