<?php

namespace App\Services\Accurate;

use App\Models\ItemAdjustmentUpload;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class ItemAdjustmentImporter
{
    public function __construct(private AccurateClient $client) {}

    public function process(ItemAdjustmentUpload $record): ItemAdjustmentUpload
    {
        $record->update([
            'status'        => 'processing',
            'error_message' => null,
        ]);

        try {
            // Ambil full path dari disk 'public'
            $disk     = 'public';
            $path     = $record->path;
            $fullPath = Storage::disk($disk)->path($path);

            if (! Storage::disk($disk)->exists($path)) {
                throw new \RuntimeException("File tidak ditemukan: {$fullPath}");
            }

            // 1) Parse Excel -> payload
            [$transDate, $description, $details] = $this->parseExcel($fullPath);

            // 🔹 Fallback dari form jika tidak ada di excel
            $transDate   = $transDate   ?: ($record->trans_date?->format('d/m/Y'));
            $description = $description ?: $record->description;

            // 🔹 Update field record agar tampil rapi di form/view
            $record->update([
                'trans_date'  => $transDate ? Carbon::createFromFormat('d/m/Y', $transDate) : null,
                'description' => $description,
            ]);

            if (! $transDate) {
                throw new \RuntimeException('transDate tidak ditemukan (isi di Excel atau form).');
            }

            if (empty($details)) {
                throw new \RuntimeException(
                    'Detail item kosong. Pastikan kolom minimal: itemAdjustmentType, itemNo, quantity, itemUnitName, warehouseName (unitCost opsional setelah warehouseName).'
                );
            }

            // ✅ Pastikan adjustment_account_no terisi
            if (blank($record->adjustment_account_no)) {
                throw new \RuntimeException('Adjustment Account No belum diisi. Silakan isi di form upload.');
            }

            // ==========================
            // 2) Susun payload ke Accurate
            // ==========================
            $payload = [
                'transDate'           => $transDate,                     // dd/mm/YYYY
                'description'         => (string) $description,
                'adjustmentAccountNo' => $record->adjustment_account_no, // ✅ field baru
                'detailItem'          => array_values($details),
            ];

            // 3) POST ke Accurate
            $resp = $this->client->itemAdjustmentSave($payload);

            // 4) Ambil number + id dari response
            $body   = $resp['body'] ?? [];
            $number = $this->findDeep($body, 'number');
            $accId  = $this->findDeep($body, 'id');

            $ok = ($resp['ok'] === true);

            $record->update([
                'payload'         => $payload,
                'response'        => $resp,
                'accurate_number' => is_scalar($number) ? (string) $number : null,
                'accurate_id'     => is_scalar($accId) ? (string) $accId : null,
                'status'          => $ok ? 'success' : 'failed',
                'error_message'   => $ok ? null : json_encode($body ?: $resp['status']),
            ]);

            return $record;
        } catch (Throwable $e) {
            $record->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return $record;
        }
    }

    /*** --- helper parsing excel & utils --- ***/
    private function parseExcel(string $fullPath): array
    {
        $spreadsheet = IOFactory::load($fullPath);
        $sheet       = $spreadsheet->getActiveSheet();

        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $cells[] = trim((string) $cell->getFormattedValue());
            }
            $rows[] = $cells;
        }

        if (count($rows) < 2) {
            throw new \RuntimeException('Excel minimal memiliki header + 1 data.');
        }

        // Header: lower & hapus spasi
        $header = array_map(fn($v) => strtolower(preg_replace('/\s+/', '', $v)), $rows[0]);

        $findIndex = function (array $alts) use ($header): ?int {
            foreach ($alts as $a) {
                $a  = strtolower(preg_replace('/\s+/', '', $a));
                $idx = array_search($a, $header, true);
                if ($idx !== false) {
                    return $idx;
                }
            }
            return null;
        };

        $colType  = $findIndex(['itemAdjustmentType', 'type']);
        $colItem  = $findIndex(['itemNo', 'item_code', 'kode']);
        $colQty   = $findIndex(['quantity', 'qty']);
        $colUnit  = $findIndex(['itemUnitName', 'unit', 'uom']);
        $colWh    = $findIndex(['warehouseName', 'warehouse', 'gudang']);
        $colTrans = $findIndex(['transDate', 'tanggal']);
        $colDesc  = $findIndex(['description', 'keterangan', 'deskripsi']);

        // ✅ unitCost opsional
        $colCost  = $findIndex(['unitCost', 'unit_cost', 'cost']);

        foreach ([$colType, $colItem, $colQty, $colUnit, $colWh] as $must) {
            if ($must === null) {
                throw new \RuntimeException(
                    'Header wajib tidak lengkap: itemAdjustmentType, itemNo, quantity, itemUnitName, warehouseName. (unitCost opsional setelah warehouseName)'
                );
            }
        }

        $firstTrans = null;
        $firstDesc  = null;

        $details = [];
        for ($i = 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            if ($this->rowEmpty($r)) {
                continue;
            }

            $type = strtoupper((string) ($r[$colType] ?? ''));
            $item = (string) ($r[$colItem] ?? '');
            $qty  = (string) ($r[$colQty] ?? '');
            $unit = (string) ($r[$colUnit] ?? '');
            $wh   = (string) ($r[$colWh] ?? '');

            if (
                $item === '' ||
                $qty === '' ||
                $unit === '' ||
                $wh === '' ||
                ! in_array($type, ['ADJUSTMENT_IN', 'ADJUSTMENT_OUT', 'ADJUSTMENT_STOCK'])
            ) {
                continue;
            }

            // Tanggal & deskripsi (ambil hanya pertama kali ketemu)
            if ($colTrans !== null && isset($r[$colTrans]) && $r[$colTrans] !== '') {
                $firstTrans = $firstTrans ?: $this->toAccurateDate($r[$colTrans]);
            }
            if ($colDesc !== null && isset($r[$colDesc]) && $r[$colDesc] !== '') {
                $firstDesc = $firstDesc ?: (string) $r[$colDesc];
            }

            // ✅ Ambil unitCost jika ada kolomnya
            $unitCost = null;
            if ($colCost !== null && array_key_exists($colCost, $r)) {
                $rawCost = $r[$colCost];

                if ($rawCost !== '' && $rawCost !== null) {
                    $unitCost = is_numeric($rawCost)
                        ? (float) $rawCost
                        : (float) str_replace([','], '', $rawCost);
                }
            }

            $detail = [
                'itemAdjustmentType' => $type,
                'itemNo'             => $item,
                'quantity'           => is_numeric($qty)
                    ? (float) $qty
                    : (float) str_replace([','], '', $qty),
                'itemUnitName'       => $unit,
                'warehouseName'      => $wh,
            ];

            // Masukkan unitCost hanya kalau ada nilainya
            if ($unitCost !== null) {
                $detail['unitCost'] = $unitCost;
            }

            $details[] = $detail;
        }

        return [$firstTrans, $firstDesc, $details];
    }

    private function rowEmpty(array $r): bool
    {
        return count(array_filter($r, fn($v) => trim((string) $v) !== '')) === 0;
    }

    private function toAccurateDate(mixed $v): string
    {
        // Excel serial number
        if (is_numeric($v)) {
            $dt = ExcelDate::excelToDateTimeObject((float) $v);
            return $dt->format('d/m/Y');
        }

        $s = trim((string) $v);

        // 1) Prioritaskan format Indonesia: d/m/Y (1-2 digit) -> dd/mm/YYYY
        if (preg_match('~^\d{1,2}/\d{1,2}/\d{4}$~', $s)) {
            [$d, $m, $y] = array_map('intval', explode('/', $s));
            return sprintf('%02d/%02d/%04d', $d, $m, $y);
        }

        // 2) yyyy-mm-dd
        if (preg_match('~^\d{4}-\d{1,2}-\d{1,2}$~', $s)) {
            return Carbon::createFromFormat('Y-m-d', $s)->format('d/m/Y');
        }

        // 3) yyyy/mm/dd
        if (preg_match('~^\d{4}/\d{1,2}/\d{1,2}$~', $s)) {
            return Carbon::createFromFormat('Y/n/j', $s)->format('d/m/Y');
        }

        // 4) d-m-Y
        if (preg_match('~^\d{1,2}-\d{1,2}-\d{4}$~', $s)) {
            [$d, $m, $y] = array_map('intval', explode('-', $s));
            return sprintf('%02d/%02d/%04d', $d, $m, $y);
        }

        // 5) Fallback terakhir
        return Carbon::parse($s)->format('d/m/Y');
    }

    private function findDeep(mixed $data, string $key): mixed
    {
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if ((string) $k === $key) {
                    return $v;
                }
                $found = $this->findDeep($v, $key);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }
}
