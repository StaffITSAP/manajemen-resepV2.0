<?php

namespace App\Jobs;

use App\Services\Accurate\AccurateClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncAccurateItemsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?int $maxPages = null; // opsional untuk debug

    public function __construct(?int $maxPages = null)
    {
        $this->maxPages = $maxPages;
    }

    public function handle(AccurateClient $client): void
    {
        $page = 1;
        $size = (int) config('accurate.page_size', 1000);
        $total = 0;

        while (true) {
            if ($this->maxPages && $page > $this->maxPages) {
                Log::info('[AccurateSync] stop by maxPages', ['lastPage' => $page - 1]);
                break;
            }

            $resp = $client->get('item/list.do', [
                'fields'       => 'id,no,name',
                'sp.page'      => $page,   // ← kunci yang benar
                'sp.pageSize'  => $size,   // ← kunci yang benar
            ]);

            if (! $resp['ok']) {
                $msg = is_array($resp['body']) ? json_encode($resp['body']) : (string) $resp['body'];
                throw new \RuntimeException("HTTP {$resp['status']} : {$msg}");
            }

            $payload = $resp['body'];
            if (!is_array($payload) || ($payload['s'] ?? false) !== true) {
                $msg = is_array($payload) ? ($payload['m'] ?? $payload['message'] ?? json_encode($payload)) : (string) $payload;
                throw new \RuntimeException("Accurate s=false: {$msg}");
            }

            $data = $payload['d']  ?? [];
            $sp   = $payload['sp'] ?? ['page'=>$page, 'pageCount'=>$page];
            $count = is_countable($data) ? count($data) : 0;

            if ($count === 0) {
                Log::info('[AccurateSync] halaman kosong', ['page' => $page]);
                break;
            }

            // bulk upsert
            $now  = now();
            $rows = [];
            foreach ($data as $it) {
                $rows[] = [
                    'accurate_id' => (int) ($it['id'] ?? 0),
                    'no'          => $it['no']   ?? null,
                    'name'        => $it['name'] ?? null,
                    'raw'         => json_encode($it),
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }

            DB::table('accurate_items')->upsert(
                $rows,
                ['accurate_id'],
                ['no','name','raw','updated_at']
            );

            $total += $count;
            Log::info('[AccurateSync] upsert', ['page' => $page, 'count' => $count, 'total' => $total]);

            // selesai bila page >= pageCount (meta resmi Accurate)
            if (($sp['page'] ?? $page) >= ($sp['pageCount'] ?? $page)) {
                Log::info('[AccurateSync] reached last page', ['page' => $page, 'pageCount' => $sp['pageCount'] ?? null]);
                break;
            }

            $page++;
        }

        Log::info('[AccurateSync] done', ['totalRows' => $total]);
    }
}
