<?php

namespace App\Exports;

use App\Models\Produksi;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ProduksiExport implements FromCollection, WithHeadings, WithEvents, ShouldAutoSize
{
    protected ?string $dariTanggal;
    protected ?string $sampaiTanggal;

    protected array $groupProduksiRows = [];
    protected array $groupBarangRows   = [];

    public function __construct(?string $dariTanggal = null, ?string $sampaiTanggal = null)
    {
        $this->dariTanggal   = $dariTanggal;
        $this->sampaiTanggal = $sampaiTanggal;
    }

    public function headings(): array
    {
        return [
            'No.',                             // A
            'Tanggal',                         // B
            'No Produksi',                     // C
            'Pekerjaan Pesanan',              // D
            'Penyelesaian Pesanan',                    // E
            'Nama Barang 1/2 Jadi',            // F
            'Jumlah Barang SJ',                // G
            'Satuan SJ',                       // H
            'Deskripsi Resep',                 // I
            'Hasil Item Produksi',             // J
            'Hasil Resep',                     // K
            'Jumlah Hasil',                    // L
            'Satuan Hasil',                    // M
            'Nama Bahan Yang Dibutuhkan',      // N
            'Satuan Bahan',                    // O
            'Bahan 1 Resep',                   // P
            'Bahan 1 Resep × Jml Produksi',    // Q
            'Bahan yang Diambil',              // R
            'Selisih Bahan yang Diambil',      // S
            'Hasil Resep Total',               // T
            'Hasil Produksi Total',            // U
            'Total Selisih',                   // V
            'Satuan',                          // W
            'Status',                          // X
        ];
    }

    public function collection()
    {
        $rows = new Collection();
        $this->groupProduksiRows = [];
        $this->groupBarangRows   = [];

        $produksiList = Produksi::query()
            ->with([
                'itemProduksi.barang.satuan',
                'itemProduksi.bahanProduksi.bahan.satuan',
                'itemProduksi.hasil',
            ])
            ->when($this->dariTanggal, fn($q) => $q->whereDate('tanggal', '>=', $this->dariTanggal))
            ->when($this->sampaiTanggal, fn($q) => $q->whereDate('tanggal', '<=', $this->sampaiTanggal))
            ->orderBy('tanggal', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $currentRow = 2; // baris pertama data (setelah header)
        $runningNo  = 1; // penomoran di kolom A

        foreach ($produksiList as $p) {
            $totalRencana = (float) $p->total_rencana;
            $totalAktual  = (float) $p->total_aktual;
            $totalSelisih = (float) $p->total_selisih;

            $unitsString = $p->itemProduksi
                ->map(fn($it) => $it->barang?->satuan?->nama)
                ->filter()
                ->unique()
                ->implode('; ');

            $tanggal    = optional($p->tanggal)->format('d/m/Y');
            $nomor      = (string) $p->nomor_produksi;
            $status     = ucfirst((string) $p->status);
            $noAccurate = (string) $p->accurate_number;
            $noRollover = (string) $p->accurate_rollover_number;

            $groupProduksiStart   = $currentRow;
            $isFirstRowOfProduksi = true;

            foreach ($p->itemProduksi as $it) {
                $barangNama = $it->barang?->nama ?? '-';
                $barangSat  = $it->barang?->satuan?->nama ?? '';
                $barangQty  = (float) ($it->jumlah ?? 0);

                $resep = \App\Models\Resep::where('barang_setengah_jadi_id', $it->barang_setengah_jadi_id)->first();
                $deskripsiResep = $resep?->deskripsi ?? '';

                $hasil = $it->hasil->first();
                $hasilNama   = $hasil?->nama_barang ?? $barangNama;
                $hasilJumlah = $hasil?->jumlah_total ? $this->fmtDb($hasil->jumlah_total) : '';
                $hasilSatuan = $hasil?->satuan ?? '';

                $hasilResep = $it->jumlah_aktual ? $this->fmtDb($it->jumlah_aktual) : '';

                $groupBarangStart   = $currentRow;
                $isFirstRowOfBarang = true;

                if ($it->bahanProduksi->isEmpty()) {
                    $rows->push([
                        $runningNo,                                     // A
                        $tanggal,                                       // B
                        $nomor,                                         // C
                        $isFirstRowOfProduksi ? $noAccurate : '',       // D
                        $isFirstRowOfProduksi ? $noRollover : '',       // E
                        $barangNama,                                    // F
                        $this->fmtDb($barangQty),                       // G
                        $barangSat,                                     // H
                        $deskripsiResep,                                // I
                        $hasilNama,                                     // J
                        $hasilResep,                                    // K
                        $hasilJumlah,                                   // L
                        $hasilSatuan,                                   // M
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',                         // N..S
                        $isFirstRowOfProduksi ? $this->fmtDb($totalRencana) : '', // T
                        $isFirstRowOfProduksi ? $this->fmtDb($totalAktual)  : '', // U
                        $isFirstRowOfProduksi ? $this->fmtDb($totalSelisih) : '', // V
                        $isFirstRowOfProduksi ? $unitsString : '',               // W
                        $isFirstRowOfProduksi ? $status : '',                      // X
                    ]);
                    $currentRow++;
                    $runningNo++;
                    $isFirstRowOfProduksi = false;
                    continue;
                }

                foreach ($it->bahanProduksi as $bp) {
                    $bahanNama = $bp->bahan?->nama ?? ('Bahan#' . $bp->bahan_id);
                    $bahanSat  = $bp->bahan?->satuan?->nama ?? '';

                    $per1Resep   = (float) ($bp->jumlah ?? 0);
                    $xJmlProd    = (float) ($bp->jumlah_aktual ?? 0);
                    $diambil     = (float) ($bp->total_produksi ?? 0);
                    $selisihTake = $bp->selisih_produksi ?? ($diambil - $xJmlProd);

                    $rows->push([
                        $runningNo,                                     // A
                        $tanggal,                                       // B
                        $nomor,                                         // C
                        $isFirstRowOfProduksi ? $noAccurate : '',       // D
                        $isFirstRowOfProduksi ? $noRollover : '',       // E
                        $barangNama,                                    // F
                        $isFirstRowOfBarang ? $this->fmtDb($barangQty) : '', // G
                        $isFirstRowOfBarang ? $barangSat : '',          // H
                        $isFirstRowOfBarang ? $deskripsiResep : '',     // I
                        $isFirstRowOfBarang ? $hasilNama : '',          // J
                        $isFirstRowOfBarang ? $hasilResep : '',         // K
                        $isFirstRowOfBarang ? $hasilJumlah : '',        // L
                        $isFirstRowOfBarang ? $hasilSatuan : '',        // M
                        $bahanNama,                                     // N
                        $bahanSat,                                      // O
                        $this->fmtDb($per1Resep),                       // P
                        $this->fmtDb($xJmlProd),                        // Q
                        $this->fmtDb($diambil),                         // R
                        $this->fmtDb($selisihTake),                     // S
                        $isFirstRowOfProduksi ? $this->fmtDb($totalRencana) : '', // T
                        $isFirstRowOfProduksi ? $this->fmtDb($totalAktual)  : '', // U
                        $isFirstRowOfProduksi ? $this->fmtDb($totalSelisih) : '', // V
                        $isFirstRowOfProduksi ? $unitsString : '',               // W
                        $isFirstRowOfProduksi ? $status : '',                      // X
                    ]);

                    $currentRow++;
                    $runningNo++;
                    $isFirstRowOfProduksi = false;
                    $isFirstRowOfBarang   = false;
                }

                $groupBarangEnd = $currentRow - 1;
                $this->groupBarangRows[] = ['start' => $groupBarangStart, 'end' => $groupBarangEnd];
            }

            $groupProduksiEnd = $currentRow - 1;
            $this->groupProduksiRows[] = ['start' => $groupProduksiStart, 'end' => $groupProduksiEnd];
        }

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet      = $event->sheet->getDelegate();
                $highestRow = $sheet->getHighestRow();

                // ===== Header style (center, bold, fill) =====
                $sheet->getStyle('A1:X1')->getFont()->setBold(true);
                $sheet->getRowDimension(1)->setRowHeight(22);
                $sheet->getStyle('A1:X1')->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getStyle('A1:X1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F3F3');

                // Freeze header
                $sheet->freezePane('A2');

                // ===== Borders for all cells =====
                $sheet->getStyle("A1:X{$highestRow}")->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()->setRGB('CCCCCC');

                // ===== Merge per produksi (B..E) =====
                foreach ($this->groupProduksiRows as $g) {
                    if ($g['end'] > $g['start']) {
                        foreach (['B', 'C', 'D', 'E'] as $col) {
                            $sheet->mergeCells("{$col}{$g['start']}:{$col}{$g['end']}");
                            $sheet->getStyle("{$col}{$g['start']}:{$col}{$g['end']}")->getAlignment()
                                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                                ->setVertical(Alignment::VERTICAL_CENTER);
                        }
                    }
                }

                // ===== Merge per barang (F..M) =====
                foreach ($this->groupBarangRows as $g) {
                    if ($g['end'] > $g['start']) {
                        foreach (['F', 'G', 'H', 'I', 'J', 'K', 'L', 'M'] as $col) {
                            $sheet->mergeCells("{$col}{$g['start']}:{$col}{$g['end']}");
                            $sheet->getStyle("{$col}{$g['start']}:{$col}{$g['end']}")->getAlignment()
                                ->setVertical(Alignment::VERTICAL_CENTER);
                        }
                    }
                }

                // ===== Default vertical center untuk seluruh data =====
                $sheet->getStyle("A2:X{$highestRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

                // ===== Horizontal alignment per kolom =====
                // Center: No., tanggal, nomor, status
                foreach (['A', 'B', 'C', 'D', 'E', 'X'] as $col) {
                    $sheet->getStyle("{$col}2:{$col}{$highestRow}")
                        ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                }
                // Left: kolom teks panjang
                foreach (['F', 'H', 'I', 'J', 'N', 'O'] as $col) {
                    $sheet->getStyle("{$col}2:{$col}{$highestRow}")
                        ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
                }
                // Right: angka (STEP 1: alignment)
                foreach (['G', 'K', 'L', 'P', 'Q', 'R', 'S', 'T', 'U', 'V'] as $col) {
                    $sheet->getStyle("{$col}2:{$col}{$highestRow}")
                        ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
                }
                // Right: angka (STEP 2: number format)
                foreach (['G', 'K', 'L', 'P', 'Q', 'R', 'S', 'T', 'U', 'V'] as $col) {
                    $sheet->getStyle("{$col}2:{$col}{$highestRow}")
                        ->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                }

                // ===== Wrap text hanya kolom tertentu =====
                foreach (['I', 'J', 'N', 'O'] as $col) {
                    $sheet->getStyle("{$col}2:{$col}{$highestRow}")
                        ->getAlignment()->setWrapText(true);
                }

                // ===== Lebar kolom yang panjang (lainnya pakai AutoSize) =====
                // (AutoSize tetap aktif via ShouldAutoSize)
                $sheet->getColumnDimension('I')->setWidth(28); // Deskripsi Resep
                $sheet->getColumnDimension('N')->setWidth(28); // Nama Bahan
            },
        ];
    }

    protected function fmtDb(float $num): string
    {
        return number_format($num, 2, '.', '');
    }
}
