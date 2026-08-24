<?php

namespace App\Services\Accurate;

use App\Models\AccurateBranch;
use Illuminate\Support\Facades\DB;

class BranchSyncService
{
    public function __construct(private AccurateClient $client) {}

    /**
     * Sinkron semua cabang dari Accurate.
     */
    public function sync(): array
    {
        $resp = $this->client->get('branch/list.do');
        if (!($resp['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'Gagal ambil data cabang dari Accurate'];
        }

        $body = $resp['body'];
        $branches = $body['d'] ?? [];
        $count = 0;

        DB::transaction(function () use ($branches, &$count) {
            foreach ($branches as $b) {
                AccurateBranch::updateOrCreate(
                    ['accurate_id' => $b['id']],
                    [
                        'name'         => $b['name'] ?? null,
                        'description'  => $b['description'] ?? null,
                        'location_code'=> $b['code'] ?? null,
                    ]
                );
                $count++;
            }
        });

        return ['ok' => true, 'total' => $count];
    }
}
