<?php

namespace App\Console\Commands;

use App\Services\Accurate\AccurateClient;
use Illuminate\Console\Command;

class AccurateTestCommand extends Command
{
    protected $signature = 'accurate:test {--page=1} {--size=}';
    protected $description = 'Test call Accurate item/list.do';

    public function handle(AccurateClient $client): int
    {
        $page = (int) $this->option('page') ?: 1;
        $size = (int) ($this->option('size') ?: config('accurate.page_size'));

        $this->info("Calling item/list.do page={$page} size={$size} ...");

        $q = [
            'fields' => 'id,name,no',
            'page'   => $page,
            'size'   => $size,
        ];

        $r = $client->get('item/list.do', $q);

        $this->line('HTTP ' . $r['status']);
        $this->line(json_encode($r['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $r['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
