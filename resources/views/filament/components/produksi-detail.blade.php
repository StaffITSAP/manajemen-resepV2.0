@php
// Helper format angka
function fmt($num): string {
if ($num === null || $num === '') return '-';
$num = (float) $num;
return $num == (int) $num
? number_format($num, 0, ',', '.')
: number_format($num, 2, ',', '.');
}
@endphp

<div class="p-6 space-y-6">
    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
        Detail Produksi: {{ $record->nomor_produksi }}
    </h3>

    <div class="grid grid-cols-2 gap-4 text-sm">
        <div>
            <strong class="text-gray-700 dark:text-white">Tanggal:</strong>
            <span class="text-gray-900 dark:text-white">
                {{ optional($record->tanggal)->format('d/m/Y') }}
            </span>
        </div>
        <div>
            <strong class="text-gray-700 dark:text-white">Status:</strong>
            <span class="px-2 py-1 rounded-full text-xs font-medium
                @class([
                    'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' => $record->status === 'selesai',
                    'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' => $record->status === 'diproses',
                    'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200' => $record->status === 'draft',
                    'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' => $record->status === 'batal',
                ])">
                {{ ucfirst($record->status) }}
            </span>
        </div>
    </div>

    <div class="border rounded-lg overflow-hidden border-gray-200 dark:border-gray-700">
        <div class="overflow-x-auto">
            <table class="w-full table-auto border-separate border-spacing-0 divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-white uppercase">Barang 1/2 Jadi</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-white uppercase">Bahan yang Dibutuhkan</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-white uppercase">Resep</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-white uppercase">Produksi</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-white uppercase">Selisih</th>
                    </tr>
                </thead>

                <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($record->itemProduksi as $item)
                    @php
                    $barang = $item->barang ?? $item->barangSetengahJadi ?? null;
                    $barangNama = $barang?->nama ?? '-';
                    $barangSatuan = $barang?->satuan?->nama ?? '';
                    @endphp

                    <tr>
                        <td class="px-4 py-3 whitespace-normal">
                            <div class="font-medium text-gray-900 dark:text-white">{{ $barangNama }}</div>
                            @if($barangSatuan)
                            <div class="text-sm text-gray-500 dark:text-gray-400">{{ $barangSatuan }}</div>
                            @endif
                        </td>

                        {{-- ⬇️ PENTING: ambil dari tabel bahan_produksi yang SUDAH TERSIMPAN --}}
                        <td class="px-4 py-3 whitespace-normal">
                            @if ($item->bahanProduksi->count())
                            @foreach ($item->bahanProduksi as $bp)
                            @php
                            $bahanNama = $bp->bahan?->nama ?? 'Bahan#'.$bp->bahan_id;
                            $satuanBahan = $bp->bahan?->satuan?->nama ?? '';
                            $qtyRencana = $bp->jumlah; // from DB
                            @endphp
                            <div class="text-sm text-gray-800 dark:text-gray-200">
                                • {{ $bahanNama }}: {{ fmt($qtyRencana) }} {{ $satuanBahan }}
                                @if ($bp->keterangan_aktual)
                                <span class="text-gray-400 dark:text-gray-500">— {{ $bp->keterangan_aktual }}</span>
                                @endif
                            </div>
                            @endforeach
                            @else
                            <span class="text-gray-400 dark:text-gray-500">Tidak ada data bahan</span>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            <span class="font-medium text-gray-900 dark:text-white">{{ fmt($item->jumlah) }}</span>
                            @if ($barangSatuan)
                            <span class="text-gray-500 dark:text-gray-400 text-xs">{{ $barangSatuan }}</span>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            @if ($item->jumlah_aktual === null)
                            <span class="text-gray-400 dark:text-gray-500">Belum diinput</span>
                            @else
                            <span class="font-medium text-gray-900 dark:text-white">{{ fmt($item->jumlah_aktual) }}</span>
                            @if ($barangSatuan)
                            <span class="text-gray-500 dark:text-gray-400 text-xs">{{ $barangSatuan }}</span>
                            @endif
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            @if ($item->jumlah_aktual === null)
                            <span class="text-gray-400 dark:text-gray-500">-</span>
                            @else
                            <span class="font-medium
                                        @if ($item->selisih > 0)
                                            text-green-600 dark:text-green-400
                                        @elseif ($item->selisih < 0)
                                            text-red-600 dark:text-red-400
                                        @else
                                            text-gray-600 dark:text-white
                                        @endif">
                                {{ fmt($item->selisih) }}
                            </span>
                            @endif
                        </td>
                    </tr>
                    @endforeach

                    {{-- TOTAL --}}
                    <tr class="bg-gray-50 dark:bg-gray-800">
                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-white" colspan="2">TOTAL</td>
                        <td class="px-4 py-3 font-medium text-blue-600 dark:text-blue-400">{{ fmt($record->total_rencana) }}</td>
                        <td class="px-4 py-3 font-medium text-blue-600 dark:text-blue-400">{{ fmt($record->total_aktual) }}</td>
                        <td class="px-4 py-3 font-medium
                            @if ($record->total_selisih > 0)
                                text-green-600 dark:text-green-400
                            @elseif ($record->total_selisih < 0)
                                text-red-600 dark:text-red-400
                            @else
                                text-gray-600 dark:text-white
                            @endif">
                            {{ fmt($record->total_selisih) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    @if ($record->catatan)
    <div class="bg-blue-50 dark:bg-blue-900 p-4 rounded-lg">
        <h4 class="font-medium text-blue-800 dark:text-blue-100">Catatan:</h4>
        <p class="text-blue-700 dark:text-blue-200">{{ $record->catatan }}</p>
    </div>
    @endif
</div>