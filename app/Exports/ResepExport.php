<?php

namespace App\Exports;

use App\Models\Resep;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;

class ResepExport implements FromCollection, WithHeadings, WithEvents, ShouldAutoSize
{
    use UsesIdNumberFormat;

    protected ?string $dariTanggal;
    protected ?string $sampaiTanggal;

    /** @var array<int,array{start:int,end:int}> */
    protected array $groupResepRows = [];

    public function __construct($dariTanggal = null, $sampaiTanggal = null)
    {
        $this->dariTanggal   = $dariTanggal;
        $this->sampaiTanggal = $sampaiTanggal;
    }

    public function headings(): array
    {
        return [
            'Nama Resep',
            'Barang 1/2 Jadi',
            'Jumlah Hasil',
            'Satuan',
            'Nama Bahan yang Dibutuhkan',
            'Jumlah Bahan',
            'Satuan Bahan',
            'Status',
            'Dibuat Pada',
            'Diupdate Pada',
        ];
    }

    public function collection()
    {
        $rows = new Collection();
        $this->groupResepRows = [];

        $list = Resep::with(['barangSetengahJadi.satuan', 'bahanResep.bahan.satuan'])
            ->when($this->dariTanggal, fn($q) => $q->whereDate('created_at', '>=', $this->dariTanggal))
            ->when($this->sampaiTanggal, fn($q) => $q->whereDate('created_at', '<=', $this->sampaiTanggal))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $currentRow = 2; // header = row 1

        foreach ($list as $resep) {
            $namaResep   = $resep->nama ?? '-';
            $barangJadi  = $resep->barangSetengahJadi?->nama ?? '-';
            $jumlahHasil = (float) ($resep->jumlah_barang_setengah_jadi ?? 0);
            $satuanHasil = $resep->barangSetengahJadi?->satuan?->nama ?? '';

            $status   = $resep->status_aktif ? 'Aktif' : 'Nonaktif';
            $created  = optional($resep->created_at)->format('d/m/Y H:i');
            $updated  = optional($resep->updated_at)->format('d/m/Y H:i');

            $groupStart = $currentRow;

            // Jika belum ada bahan, tetap buat 1 baris kosong bahan:
            if ($resep->bahanResep->isEmpty()) {
                $rows->push([
                    $namaResep,
                    $barangJadi,
                    $this->fmtId($jumlahHasil),
                    $satuanHasil,
                    '', // nama bahan
                    '', // jumlah bahan
                    '', // satuan bahan
                    $status,
                    $created,
                    $updated,
                ]);
                $currentRow++;
            } else {
                foreach ($resep->bahanResep as $b) {
                    $namaBahan   = $b->bahan?->nama ?? ('Bahan#' . $b->bahan_id);
                    $jumlahBahan = (float) ($b->jumlah ?? 0);
                    $satuanBahan = $b->bahan?->satuan?->nama ?? '';

                    $rows->push([
                        $namaResep,
                        $barangJadi,
                        $this->fmtId($jumlahHasil),
                        $satuanHasil,
                        $namaBahan,
                        $this->fmtId($jumlahBahan),
                        $satuanBahan,
                        $status,
                        $created,
                        $updated,
                    ]);
                    $currentRow++;
                }
            }

            $groupEnd = $currentRow - 1;
            $this->groupResepRows[] = ['start' => $groupStart, 'end' => $groupEnd];
        }

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet      = $event->sheet->getDelegate();
                $highestRow = $sheet->getHighestRow();

                // Header bold
                $sheet->getStyle('A1:J1')->getFont()->setBold(true);

                // Bungkus teks panjang
                $sheet->getStyle("A1:J{$highestRow}")->getAlignment()->setWrapText(true);

                // Merge per RESEP:
                // - Kolom A-D (info resep)
                // - Kolom H-J (status & timestamp)
                foreach ($this->groupResepRows as $g) {
                    if ($g['end'] > $g['start']) {
                        $sheet->mergeCells("A{$g['start']}:A{$g['end']}");
                        $sheet->mergeCells("B{$g['start']}:B{$g['end']}");
                        $sheet->mergeCells("C{$g['start']}:C{$g['end']}");
                        $sheet->mergeCells("D{$g['start']}:D{$g['end']}");
                        $sheet->mergeCells("H{$g['start']}:H{$g['end']}");
                        $sheet->mergeCells("I{$g['start']}:I{$g['end']}");
                        $sheet->mergeCells("J{$g['start']}:J{$g['end']}");
                    }

                    // Center vertikal & horizontal kolom yang di-merge
                    $sheet->getStyle("A{$g['start']}:D{$g['end']}")
                        ->getAlignment()->setHorizontal('center')->setVertical('center');
                    $sheet->getStyle("H{$g['start']}:J{$g['end']}")
                        ->getAlignment()->setHorizontal('center')->setVertical('center');
                }

                // Angka rata kanan
                $sheet->getStyle("C2:C{$highestRow}")->getAlignment()->setHorizontal('right'); // jumlah hasil
                $sheet->getStyle("F2:F{$highestRow}")->getAlignment()->setHorizontal('right'); // jumlah bahan
            },
        ];
    }
}

/* ==============================================================
 |  Helper: Format Angka Indonesia (tanpa desimal jika bulat)
 * ============================================================ */
trait UsesIdNumberFormat
{
    protected function fmtId(float $num): string
    {
        $isInt = abs($num - (int) $num) < 0.0000001;
        return $isInt
            ? number_format((int) $num, 0, ',', '.')
            : number_format($num, 2, ',', '.');
    }
}
