<?php

namespace App\Console\Commands;

use App\Services\Accurate\AccurateClient;
use Illuminate\Console\Command;

class AccurateItemsProbe extends Command
{
    protected $signature = 'accurate:probe {--page=1} {--size=20}';
    protected $description = 'Probe Accurate item/list.do';

    public function handle(AccurateClient $client): int
    {
        $r = $client->get('item/list.do', [
            'fields' => 'id,name,no',
            'page'   => (int)$this->option('page'),
            'size'   => (int)$this->option('size'),
        ]);

        $this->line('HTTP '.$r['status']);
        $this->line(json_encode($r['body'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

        return $r['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
