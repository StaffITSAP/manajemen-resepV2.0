{{-- resources/views/filament/components/resep-bahan-detail.blade.php --}}
@once
@php
    // Helper format angka: 0 desimal jika bulat, 2 desimal jika pecahan
    function fmt($num): string {
        if ($num === null || $num === '') return '-';
        $num = (float) $num;
        return ($num == (int) $num)
            ? number_format($num, 0, ',', '.')
            : number_format($num, 2, ',', '.');
    }
@endphp
@endonce

@php
    // Pastikan relasi tersedia
    $resep->loadMissing(['barangSetengahJadi.satuan', 'bahanResep.bahan.satuan']);

    $hasilQty = (float) ($resep->jumlah_barang_setengah_jadi ?? 0);
    $hasilUnit = $resep->barangSetengahJadi?->satuan?->nama ?? '';
    $barangJadi = $resep->barangSetengahJadi?->nama ?? '-';
@endphp

<div class="p-6 space-y-6">
    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
        Bahan Resep: {{ $resep->nama }}
    </h3>

    {{-- Info ringkas hasil per 1 batch resep --}}
    <div class="grid grid-cols-3 gap-4 text-sm">
        <div>
            <strong class="text-gray-700 dark:text-white">Menghasilkan:</strong>
            <div class="mt-0.5 text-gray-900 dark:text-white">{{ $barangJadi }}</div>
        </div>
        <div>
            <strong class="text-gray-700 dark:text-white">Jumlah per Batch:</strong>
            <div class="mt-0.5 text-gray-900 dark:text-white">
                {{ fmt($hasilQty) }} {{ $hasilUnit }}
            </div>
        </div>
        <div>
            <strong class="text-gray-700 dark:text-white">Total Bahan:</strong>
            <div class="mt-0.5 text-gray-900 dark:text-white">
                {{ $resep->bahanResep->count() }} item
            </div>
        </div>
    </div>

    {{-- Tabel Bahan --}}
    <div class="border rounded-lg overflow-hidden border-gray-200 dark:border-gray-700">
        <table class="w-full table-fixed divide-y divide-gray-200 dark:divide-gray-700">
            <colgroup>
                <col class="w-2/5">
                <col class="w-1/5">
                <col class="w-2/5">
            </colgroup>

            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-white uppercase">
                        Nama Bahan
                    </th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-white uppercase">
                        Jumlah per Batch
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-white uppercase">
                        Catatan
                    </th>
                </tr>
            </thead>

            <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($resep->bahanResep as $row)
                    @php
                        $bahanNama = $row->bahan?->nama ?? 'Bahan#'.$row->bahan_id;
                        $bahanSat = $row->bahan?->satuan?->nama ?? '';
                        $qty = (float) $row->jumlah;
                        $catatan = $row->catatan ?: '-';
                    @endphp
                    <tr>
                        <td class="px-4 py-3 whitespace-normal align-top">
                            <div class="font-medium text-gray-900 dark:text-white">{{ $bahanNama }}</div>
                            @if($bahanSat)
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $bahanSat }}</div>
                            @endif
                        </td>

                        <td class="px-4 py-3 text-right align-top">
                            <span class="font-medium text-gray-900 dark:text-white">{{ fmt($qty) }}</span>
                            @if($bahanSat)
                                <span class="text-gray-500 dark:text-gray-400 text-xs"> {{ $bahanSat }}</span>
                            @endif
                        </td>

                        <td class="px-4 py-3 text-gray-700 dark:text-white align-top">
                            {{ $catatan }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-gray-500 dark:text-gray-400" colspan="3">
                            Belum ada bahan yang ditambahkan
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Deskripsi dan Cara Pembuatan --}}
    @if($resep->deskripsi || $resep->cara_pembuatan)
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @if($resep->deskripsi)
                <div class="bg-blue-50 dark:bg-blue-900 p-4 rounded-lg">
                    <h4 class="font-medium text-blue-800 dark:text-blue-100">Deskripsi</h4>
                    <p class="text-blue-700 dark:text-blue-200 mt-1">{{ $resep->deskripsi }}</p>
                </div>
            @endif

            @if($resep->cara_pembuatan)
                <div class="bg-emerald-50 dark:bg-emerald-900 p-4 rounded-lg">
                    <h4 class="font-medium text-emerald-800 dark:text-emerald-100">Cara Pembuatan</h4>
                    <p class="text-emerald-700 dark:text-emerald-200 mt-1 whitespace-pre-line">
                        {{ $resep->cara_pembuatan }}
                    </p>
                </div>
            @endif
        </div>
    @endif
</div>
