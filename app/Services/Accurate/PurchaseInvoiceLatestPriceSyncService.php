<?php

namespace App\Services\Accurate;

use App\Models\AccurateItem;
use App\Models\PurchaseItemLatestPrice;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Invoice-backed implementation of the existing latest-price cache contract. */
class PurchaseInvoiceLatestPriceSyncService
{
    public const SCAN_MODE_QUICK = 'quick';
    public const SCAN_MODE_FULL = 'full';

    public function __construct(private AccurateClient $client, private mixed $sleeper = null) {}

    public function sync(int $page = 1, int $pageSize = 10, ?int $maxPages = 1, ?int $maxDetails = null, int $sleepMs = 0, bool $stageOnly = false, int $startRowIndex = 0, ?string $incrementalRunUpperTransDate = null, ?string $incrementalCompletedUpperTransDate = null): array
    {
        $stats = ['ok'=>true,'pages_requested'=>0,'purchase_invoices'=>0,'details_fetched'=>0,'rows_consumed'=>0,'lines_processed'=>0,'inserted'=>0,'updated'=>0,'unchanged'=>0,'skipped_malformed'=>0,'failures'=>0,'message'=>null];
        $full = $maxPages === null || $stageOnly; $dataset = [];
        $pageSize = max(1, min($pageSize, 100)); $processed = 0;
        while ($maxPages === null || $processed < max(1, $maxPages)) {
            $response = $this->client->listPurchaseInvoices($this->listParams($page, $pageSize)); $stats['pages_requested']++;
            if (!($response['ok'] ?? false)) { $stats['ok']=false; $stats['failures']++; $stats['message']='Gagal mengambil daftar Purchase Invoice dari Accurate.'; break; }
            $body = $response['body'] ?? []; $rows = $this->rows($body); $stats['purchase_invoices'] += count($rows);
            if ($rows === []) break;
            $rows = array_values(array_slice($rows, $startRowIndex));
            foreach ($rows as $row) {
                if ($maxDetails !== null && $stats['details_fetched'] >= $maxDetails) break 2;
                $id = (int) Arr::get($row, 'id', 0); if ($id <= 0) { $stats['skipped_malformed']++; $stats['rows_consumed']++; continue; }
                $rowDate = $this->rowDate($row);
                if ($rowDate === null) { $stats['skipped_malformed']++; $stats['rows_consumed']++; continue; }
                if ($incrementalRunUpperTransDate !== null && $rowDate->gt(Carbon::parse($incrementalRunUpperTransDate))) { $stats['rows_consumed']++; continue; }
                if ($incrementalCompletedUpperTransDate !== null && $rowDate->lt(Carbon::parse($incrementalCompletedUpperTransDate))) { $stats['boundary_complete'] = true; break 2; }
                if ($stats['details_fetched'] > 0 && $sleepMs > 0) $this->sleep($sleepMs);
                $detail = $this->client->detailPurchaseInvoice($id);
                if (!($detail['ok'] ?? false)) { $stats['failures']++; $stats['ok'] = false; $stats['message'] = 'Gagal mengambil detail Purchase Invoice.'; break 2; }
                $payload = $this->payload($detail['body'] ?? []); $candidates = $this->candidates($payload, $row);
                $stats['details_fetched']++; $stats['rows_consumed']++;
                foreach ($candidates as $candidate) { $stats['lines_processed']++; if ($full) { $key=$candidate['item_accurate_id'].':'.$candidate['item_unit_accurate_id']; if (!isset($dataset[$key]) || $this->newerArray($candidate, $dataset[$key])) $dataset[$key]=$candidate; } else { $stats[$this->store($candidate)]++; } }
            }
            $processed++; if (count($rows) < $pageSize) break; $page++;
        }
        if ($stageOnly) { $stats['candidates'] = array_values($dataset); }
        elseif ($full && $stats['ok']) { $reconciled=$this->reconcile(array_values($dataset)); $stats['inserted']=$reconciled['inserted']; $stats['updated']=$reconciled['updated']; $stats['unchanged']=$reconciled['unchanged']; $stats['legacy_deleted']=$reconciled['legacy_deleted']; }
        return $stats;
    }

    public function listParams(int $page, int $pageSize): array
    { return ['fields'=>'id,number,transDate,approvalStatus','filter.approvalStatus.val'=>'APPROVED','sp.sort'=>'transDate|desc','sp.page'=>$page,'sp.pageSize'=>$pageSize]; }

    public function syncSmartUnprocessedPurchaseInvoiceBatch(int $page=1, int $pageSize=100, int $maxDetails=50, int $sleepMs=500, array $attemptedPurchaseInvoiceIds=[], string $scanMode=self::SCAN_MODE_QUICK, bool $stageOnly=true, int $startRowIndex=0, ?string $incrementalRunUpperTransDate=null, ?string $incrementalCompletedUpperTransDate=null): array
    {
        $result = $this->sync($page, $pageSize, 1, $maxDetails, $sleepMs, $stageOnly, $startRowIndex, $incrementalRunUpperTransDate, $incrementalCompletedUpperTransDate);
        $processedRows = (int) ($result['rows_consumed'] ?? $result['details_fetched'] ?? 0);
        $pageComplete = $result['ok'] && (($result['boundary_complete'] ?? false) || $startRowIndex + $processedRows >= $pageSize || ($result['purchase_invoices'] ?? 0) < $pageSize);
        $result['page_complete'] = $pageComplete;
        $result['stage_complete'] = $pageComplete && (($result['boundary_complete'] ?? false) || ($result['purchase_invoices'] ?? 0) < $pageSize);
        $result['next_row_index'] = $pageComplete ? 0 : $startRowIndex + $processedRows;
        $result['next_page'] = $pageComplete ? $page + 1 : $page;
        $result['attempted_purchase_invoice_ids'] = $attemptedPurchaseInvoiceIds;
        return $result;
    }

    public function firstPurchaseInvoiceTransDate(int $pageSize = 100): array
    {
        $response = $this->client->listPurchaseInvoices($this->listParams(1, $pageSize));
        if (!($response['ok'] ?? false)) return ['ok' => false, 'trans_date' => null, 'message' => 'Gagal mengambil daftar Purchase Invoice dari Accurate.'];
        foreach ($this->rows($response['body'] ?? []) as $row) {
            if ((int) Arr::get($row, 'id', 0) <= 0) continue;
            $date = $this->rowDate($row);
            if ($date !== null) return ['ok' => true, 'trans_date' => $date->toDateString(), 'message' => null];
        }
        return ['ok' => true, 'trans_date' => null, 'message' => null];
    }

    private function rows(mixed $body): array { if (!is_array($body)) throw new RuntimeException('Response list Purchase Invoice tidak valid.'); if (array_key_exists('d',$body)) $rows=$body['d']; elseif (array_key_exists('data',$body)) $rows=$body['data']; elseif (array_key_exists('rows',$body)) $rows=$body['rows']; else throw new RuntimeException('Payload list Purchase Invoice tidak memiliki struktur d/data/rows.'); if (!is_array($rows)||array_filter($rows,'is_array')!==$rows) throw new RuntimeException('Data list Purchase Invoice tidak valid.'); return array_values($rows); }
    private function payload(mixed $body): array { if (!is_array($body)) throw new RuntimeException('Payload detail Purchase Invoice tidak valid.'); return $body['d'] ?? $body['data'] ?? $body; }
    private function rowDate(array $row): ?Carbon { try { $date = $row['transDate'] ?? null; return $date ? Carbon::parse($date)->startOfDay() : null; } catch (Throwable) { return null; } }
    private function candidates(array $invoice, array $row): array
    {
        $id=(int)($invoice['id']??$row['id']??0); $date=$invoice['transDate']??$row['transDate']??null; $items=$invoice['detailItem']??[]; $out=[];
        foreach (is_array($items)?$items:[] as $line) { if (!is_array($line)) continue; $item=(int)data_get($line,'item.id',data_get($line,'itemId',0)); $unit=(int)data_get($line,'itemUnit.id',data_get($line,'itemUnitId',0)); $price=$line['unitPrice']??null; if($id<=0||$item<=0||$unit<=0||!is_numeric($price)||!$date) continue; $out[]=['item_accurate_id'=>$item,'item_no'=>data_get($line,'item.no'),'item_name'=>data_get($line,'item.name'),'item_unit_accurate_id'=>$unit,'item_unit_name'=>data_get($line,'itemUnit.name'),'unit_price'=>(string)$price,'purchase_order_accurate_id'=>$id,'purchase_order_number'=>$invoice['number']??$row['number']??null,'purchase_order_date'=>Carbon::parse($date)->toDateString(),'purchase_order_detail_id'=>isset($line['id'])?(int)$line['id']:null,'source_updated_at'=>null,'source_type'=>PurchaseItemLatestPrice::SOURCE_TYPE_PI]; }
        return $out;
    }
    private function store(array $candidate): string
    { return DB::transaction(function() use($candidate){ $q=PurchaseItemLatestPrice::query()->where('item_accurate_id',$candidate['item_accurate_id'])->where('item_unit_accurate_id',$candidate['item_unit_accurate_id'])->lockForUpdate(); $old=$q->first(); if($old && !$this->newer($candidate,$old)) return 'unchanged'; $data=$candidate+['source_type'=>PurchaseItemLatestPrice::SOURCE_TYPE_PI,'accurate_item_id'=>AccurateItem::query()->where('accurate_id',$candidate['item_accurate_id'])->value('id'),'synced_at'=>now()]; if($old){$old->update($data);return 'updated';} PurchaseItemLatestPrice::create($data);return 'inserted';}); }
    private function newer(array $c, PurchaseItemLatestPrice $e): bool { if($e->source_type !== PurchaseItemLatestPrice::SOURCE_TYPE_PI) return true; $d=Carbon::parse($c['purchase_order_date']); $old=$e->purchase_order_date; if(!$old||$d->gt($old)) return true; if($d->lt($old)) return false; $id=(int)$c['purchase_order_accurate_id']; $eid=(int)$e->purchase_order_accurate_id; if($id!==$eid)return $id>$eid; return (int)($c['purchase_order_detail_id']??0)>(int)($e->purchase_order_detail_id??0); }
    private function newerArray(array $c, array $e): bool { $d=Carbon::parse($c['purchase_order_date']); $old=Carbon::parse($e['purchase_order_date']); if($d->gt($old))return true; if($d->lt($old))return false; if((int)$c['purchase_order_accurate_id'] !== (int)$e['purchase_order_accurate_id']) return (int)$c['purchase_order_accurate_id'] > (int)$e['purchase_order_accurate_id']; return (int)($c['purchase_order_detail_id']??0) > (int)($e['purchase_order_detail_id']??0); }
    public function reconcile(array $dataset): array
    { return DB::transaction(function() use($dataset){ $latest=[]; foreach($dataset as $candidate){$key=$candidate['item_accurate_id'].':'.$candidate['item_unit_accurate_id']; if(!isset($latest[$key])||$this->newerArray($candidate,$latest[$key]))$latest[$key]=$candidate;} $keys=array_keys($latest); $result=['inserted'=>0,'updated'=>0,'unchanged'=>0,'legacy_deleted'=>0]; foreach($latest as $candidate){$result[$this->store($candidate)]++;} $deleted=PurchaseItemLatestPrice::query()->get()->filter(fn($row)=>!in_array($row->item_accurate_id.':'.$row->item_unit_accurate_id,$keys,true)); foreach($deleted as $row){$row->delete();$result['legacy_deleted']++;} return $result;}); }
    private function sleep(int $ms): void { is_callable($this->sleeper) ? ($this->sleeper)($ms) : usleep($ms*1000); }
}
