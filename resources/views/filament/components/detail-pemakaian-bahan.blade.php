@php
/* ========= Helpers ========= */
function fmt($num): string {
if ($num === null || $num === '') return '-';
$num = (float) $num;
return $num == (int) $num
? number_format($num, 0, ',', '.')
: number_format($num, 2, ',', '.');
}

/** angka tebal + unit kecil di bawah (untuk cell angka) */
function cell_with_unit($num, $unit = '', $color = ''): string {
$num = (float) ($num ?? 0);
$unit = trim((string) $unit);

$number = '<div class="font-semibold '.$color.'">'.fmt($num).'</div>';
$unitEl = $unit !== ''
? '<div class="text-xs text-gray-500 dark:text-gray-400">'.$unit.'</div>'
: '';

return $number.$unitEl;
}

/* ========= Agregasi dari tabel bahan_produksi =========
- dikelompokkan per (bahan_id|satuan)
- rencana = SUM(bahan_produksi.jumlah)
- aktual = SUM(bahan_produksi.jumlah_aktual)
- total_prod = SUM(bahan_produksi.total_produksi)
- selisih_prod = SUM(COALESCE(bahan_produksi.selisih_produksi, total_produksi - jumlah_aktual))
*/
$agg = []; // key => ['nama','satuan','rencana','aktual','total_prod','selisih_prod']
$grandRencana = $grandAktual = $grandTotalProd = $grandSelisihProd = 0.0;

foreach ($produksi->itemProduksi as $item) {
foreach ($item->bahanProduksi as $bp) {
$bahan = $bp->bahan;
$nama = $bahan->nama ?? ('Bahan#'.$bp->bahan_id);
$sat = $bahan->satuan->nama ?? '';
$key = ($bahan->id ?? $bp->bahan_id).'|'.$sat;

$rencana = (float) ($bp->jumlah ?? 0);
$aktual = (float) ($bp->jumlah_aktual ?? 0);
$total = (float) ($bp->total_produksi ?? 0);
$selProd = (float) ($bp->selisih_produksi ?? ($total - $aktual));

if (! isset($agg[$key])) {
$agg[$key] = [
'nama' => $nama,
'satuan' => $sat,
'rencana' => 0.0,
'aktual' => 0.0,
'total_prod' => 0.0,
'selisih_prod' => 0.0,
];
}

$agg[$key]['rencana'] += $rencana;
$agg[$key]['aktual'] += $aktual;
$agg[$key]['total_prod'] += $total;
$agg[$key]['selisih_prod'] += $selProd;

$grandRencana += $rencana;
$grandAktual += $aktual;
$grandTotalProd += $total;
$grandSelisihProd += $selProd;
}
}
$rows = collect($agg)->sortBy('nama')->values();

/** Badge status (light/dark) */
$status = $produksi->status ?? 'draft';
$badge = match ($status) {
'selesai' => 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-200',
'diproses' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/50 dark:text-yellow-200',
'draft' => 'bg-gray-100 text-gray-800 dark:bg-gray-800/60 dark:text-gray-200',
default => 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200',
};
@endphp

<div class="p-6 space-y-5">

    {{-- Judul --}}
    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
        Detail Produksi: {{ $produksi->nomor_produksi }}
    </h3>
    <div class="text-sm text-gray-600 dark:text-gray-300">
        Job Order Accurate: <span class="font-medium">{{ $produksi->accurate_number ?? '-' }}</span>
    </div>

    {{-- Chip nama barang 1/2 jadi --}}
    @if($produksi->itemProduksi->count())
    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 bg-white dark:bg-gray-900">
        <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-2">Barang 1/2 Jadi</div>
        <div class="grid gap-2 sm:grid-cols-2">
            @foreach ($produksi->itemProduksi as $it)
            @php
            $nm = $it->barang->nama ?? '-';
            $sat = $it->barang->satuan->nama ?? '';
            $ren = (float) ($it->jumlah ?? 0);
            $akt = (float) ($it->jumlah_aktual ?? 0);
            @endphp
            <div class="rounded-md bg-gray-50 dark:bg-gray-800 px-3 py-2 border border-gray-200 dark:border-gray-700">
                <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $nm }}</div>
                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-gray-600 dark:text-gray-300">
                    <span>Resep: <span class="font-medium">{{ fmt($ren) }} {{ $sat }}</span></span>
                    <span>Produksi: <span class="font-medium">{{ fmt($akt) }} {{ $sat }}</span></span>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Meta: tanggal + status --}}
    <div class="grid grid-cols-2 gap-3 text-sm">
        <div>
            <span class="text-gray-500 dark:text-gray-400">Tanggal:</span>
            <span class="ml-1 text-gray-900 dark:text-white">
                {{ optional($produksi->tanggal)->format('d/m/Y') ?? '-' }}
            </span>
        </div>
        <div class="text-right sm:text-left">
            <span class="text-gray-500 dark:text-gray-400 mr-1">Status:</span>
            <span class="px-2 py-0.5 rounded-full text-xs font-medium align-middle {{ $badge }}">
                {{ ucfirst($status) }}
            </span>
        </div>
    </div>

    {{-- Tabel rekap --}}
    <div class="border rounded-lg overflow-hidden border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
        <div class="overflow-x-auto max-h-[60vh]">
            <table class="w-full table-auto border-separate border-spacing-0">
                <thead class="sticky top-0 z-10 bg-gray-50 dark:bg-gray-800/95 backdrop-blur supports-backdrop-blur:bg-gray-50/80 dark:supports-backdrop-blur:bg-gray-800/80">
                    <tr class="text-xs uppercase tracking-wide text-gray-600 dark:text-gray-300">
                        <th class="px-3 py-3 text-left  w-[5%]">No.</th>
                        <th class="px-4 py-3 text-left  w-[30%]">Bahan</th>
                        <th class="px-4 py-3 text-left  w-[10%]">Satuan</th>
                        <th class="px-4 py-3 text-right w-[15%]">Bahan 1 Resep</th>
                        <th class="px-4 py-3 text-right w-[20%]">Bahan 1 Resep × Jml Produksi</th>
                        <th class="px-4 py-3 text-right w-[15%]">Bahan yang Diambil</th>
                        <th class="px-4 py-3 text-right w-[10%]">Selisih Bahan yang Diambil</th>
                    </tr>
                </thead>

                <tbody class="[&>tr:nth-child(even)]:bg-gray-50/60 dark:[&>tr:nth-child(even)]:bg-gray-800/60">
                    @forelse ($rows as $i => $row)
                    @php
                    $sat = $row['satuan'] ?? '';
                    $rencana = (float) ($row['rencana'] ?? 0);
                    $aktual = (float) ($row['aktual'] ?? 0);
                    $total = (float) ($row['total_prod'] ?? 0);
                    $selProd = (float) ($row['selisih_prod'] ?? 0);
                    $selColor = $selProd > 0
                    ? 'text-green-600 dark:text-green-400'
                    : ($selProd < 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-300' );
                        @endphp
                        <tr class="align-top">
                        <td class="px-3 py-3 text-sm text-gray-700 dark:text-gray-300">{{ $i + 1 }}</td>
                        <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100 break-words">
                            {{ $row['nama'] }}
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                            {{ $sat ?: '-' }}
                        </td>

                        <td class="px-4 py-3 text-right">{!! cell_with_unit($rencana, $sat) !!}</td>
                        <td class="px-4 py-3 text-right">{!! cell_with_unit($aktual, $sat) !!}</td>
                        <td class="px-4 py-3 text-right">{!! cell_with_unit($total, $sat, 'text-indigo-700 dark:text-indigo-300') !!}</td>
                        <td class="px-4 py-3 text-right">{!! cell_with_unit($selProd, $sat, $selColor) !!}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                Tidak ada data pemakaian bahan
                            </td>
                        </tr>
                        @endforelse
                </tbody>
            </table>
        </div>

        @if ($produksi->catatan)
        <div class="border-t border-gray-200 dark:border-gray-700 p-4 bg-blue-50/60 dark:bg-blue-900/20">
            <div class="text-sm font-medium text-blue-800 dark:text-blue-200 mb-1">Catatan</div>
            <p class="text-sm text-blue-800/90 dark:text-blue-200">{{ $produksi->catatan }}</p>
        </div>
        @endif
    </div>
</div>