<?php

namespace App\Exports;

use App\Models\Produksi;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LaporanPemakaianBahanExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    protected $dariTanggal;
    protected $sampaiTanggal;

    public function __construct($dariTanggal = null, $sampaiTanggal = null)
    {
        $this->dariTanggal = $dariTanggal;
        $this->sampaiTanggal = $sampaiTanggal;
    }

    public function collection()
    {
        $query = Produksi::with(['itemProduksi.barangSetengahJadi.resepSebagaiBarangSetengahJadi.bahanResep.bahan.satuan']);

        if ($this->dariTanggal) {
            $query->whereDate('tanggal', '>=', $this->dariTanggal);
        }

        if ($this->sampaiTanggal) {
            $query->whereDate('tanggal', '<=', $this->sampaiTanggal);
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'Nomor Produksi',
            'Tanggal',
            'Item Produksi',
            'Jumlah Item',
            'Bahan',
            'Jumlah Bahan per Porsi',
            'Total Dibutuhkan',
            'Satuan',
            'Status'
        ];
    }

    public function map($produksi): array
    {
        $rows = [];

        foreach ($produksi->laporanPemakaianBahan as $pemakaian) {
            $rows[] = [
                $produksi->nomor_produksi,
                $produksi->tanggal->format('d/m/Y'),
                $pemakaian['item_produksi'],
                $pemakaian['jumlah_item'],
                $pemakaian['bahan'],
                $pemakaian['jumlah_bahan'],
                $pemakaian['total_dibutuhkan'],
                $pemakaian['satuan'],
                ucfirst($produksi->status)
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
