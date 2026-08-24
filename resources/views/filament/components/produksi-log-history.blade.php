@php
use App\Models\MasterBarang;
use App\Models\BahanProduksi;
use App\Models\ItemProduksi;

/** @var \App\Models\Produksi $produksi */
$logs = $produksi->logs()
->with(['user:id,name']) // cukup user dulu
->latest()
->get();

/* Eager load relasi sesuai tipe model yang tercatat di log */
$logs->loadMorph('model', [
BahanProduksi::class => ['bahan:id,nama'], // butuh nama bahan
ItemProduksi::class => [], // tidak perlu apa-apa
\App\Models\Produksi::class => [], // tidak perlu apa-apa
]);

/* ================== Flatten & Humanize ================== */
$rows = [];
foreach ($logs as $log) {
$old = $log->changes_old ?? [];
$new = $log->changes_new ?? [];

// field yang berubah di baris ini
$keys = collect(array_unique(array_merge(array_keys($old), array_keys($new))))->values();
if ($keys->isEmpty()) {
$keys = collect(array_unique(array_merge(array_keys($old ?: []), array_keys($new ?: []))))->values();
}

// Tentukan label objek yang akurat per log
$objLabel = 'Produksi';
if ($log->model instanceof BahanProduksi) {
// log untuk 1 baris bahan_produksi -> pakai nama bahannya
$objLabel = 'Bahan: ' . ($log->model->bahan->nama ?? ('#'.$log->model->bahan_id));
} elseif ($log->model instanceof ItemProduksi) {
// log di level item_produksi
$objLabel = 'Item Produksi #' . ($log->item_produksi_id ?? $log->model->getKey());
} elseif ($log->itemProduksi) {
// fallback lama (kalau model() tidak bisa diresolve)
$firstName = optional($log->itemProduksi->bahanProduksi->first())->bahan->nama;
$objLabel = $firstName ? ('Bahan: ' . $firstName) : ('Item Produksi #' . $log->item_produksi_id);
}

foreach ($keys as $field) {
$rows[] = [
'user' => $log->user?->name ?? 'System',
'obj' => $objLabel,
'field' => $field,
'old' => $old[$field] ?? null,
'new' => $new[$field] ?? null,
'time' => $log->created_at?->format('Y-m-d H:i:s'),
'action' => $log->action,
];
}
}

// Sembunyikan field yang tidak perlu
$hiddenFields = ['id','deleted_at','updated_at','created_at'];
$rows = array_values(array_filter($rows, fn($r) => !in_array($r['field'], $hiddenFields, true)));

// urut terbaru dulu
usort($rows, fn($a,$b) => strtotime($b['time'] ?? '') <=> strtotime($a['time'] ?? ''));

    // label field agar readable
    $fieldLabels = [
    'tanggal' => 'Tanggal',
    'keterangan' => 'Keterangan',
    'status' => 'Status',
    'total_produksi' => 'Total Produksi',
    'selisih_produksi' => 'Selisih Produksi',
    'jumlah' => 'Jumlah',
    'keterangan_aktual' => 'Keterangan Aktual',
    'is_manual' => 'Input Manual',
    'enable_bahan_tambahan' => 'Aktifkan Bahan Tambahan',
    'bahan_id' => 'Bahan',
    'barang_id' => 'Barang',
    'barang_setengah_jadi_id' => 'Barang Setengah Jadi',
    'resep_id' => 'Resep',
    'item_produksi_id' => 'Item Produksi',
    ];

    // peta nama FK agar tampil nama, bukan ID (tetap dipakai untuk diff 'bahan_id' dsb)
    $fkBarangIds = [];
    foreach ($rows as $r) {
    if (in_array($r['field'], ['bahan_id','barang_id'], true)) {
    foreach (['old','new'] as $k) {
    if (is_numeric($r[$k] ?? null)) $fkBarangIds[] = (int) $r[$k];
    }
    }
    }
    $mapBarang = empty($fkBarangIds)
    ? []
    : MasterBarang::whereIn('id', array_unique($fkBarangIds))->pluck('nama','id')->all();

    $labelize = fn(string $f) => $fieldLabels[$f] ?? str($f)->replace('_',' ')->title();

    $human = function (string $field, $val) use ($mapBarang) {
    if ($val === null || $val === '') return '—';
    if (in_array($field, ['is_manual','enable_bahan_tambahan'], true)) {
    return (string) $val === '1' || $val === true ? 'true' : 'false';
    }
    if (in_array($field, ['bahan_id','barang_id'], true)) {
    return $mapBarang[$val] ?? (string) $val;
    }
    if (is_array($val)) return json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return (string) $val;
    };
    @endphp


    <div
        x-data="{
        rawRows: @js($rows),
        q: $persist(''),
        perPage: $persist(10),
        page: $persist(1),
        get filtered() {
            if (!this.q) return this.rawRows;
            const s = this.q.toLowerCase();
            return this.rawRows.filter(r =>
                (r.user  || '').toLowerCase().includes(s) ||
                (r.obj   || '').toLowerCase().includes(s) ||
                (r.field || '').toLowerCase().includes(s)||
                (r.action|| '').toLowerCase().includes(s)||
                String(r.old ?? '').toLowerCase().includes(s)||
                String(r.new ?? '').toLowerCase().includes(s)
            );
        },
        get total() { return this.filtered.length; },
        get totalPages() { return Math.max(1, Math.ceil(this.total / this.perPage)); },
        get startIdx() { return (this.page - 1) * this.perPage; },
        get endIdx()   { return Math.min(this.total, this.page * this.perPage); },
        get pageRows() { return this.filtered.slice(this.startIdx, this.endIdx); },
        go(p){ this.page = Math.min(Math.max(1, p), this.totalPages); },
        badgeClass(act){
            return {
                'created':'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
                'updated':'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300',
                'deleted':'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
                'restored':'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
                'viewed':'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
            }[act] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
        }
    }"
        class="space-y-4">
        <div>
            <div class="text-base font-semibold">Histori Log Produksi</div>
            <div class="text-sm text-gray-600 dark:text-gray-300">
                ID Produksi: <span class="font-medium">{{ $produksi->id }}</span>
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Total baris: <span x-text="total"></span> • Halaman: <span x-text="page"></span>/<span x-text="totalPages"></span>
            </div>
        </div>

        <template x-if="total === 0">
            <div class="text-center text-gray-500 dark:text-gray-400 py-10">Belum ada histori.</div>
        </template>

        <template x-if="total > 0">
            <div class="space-y-3">
                <div class="flex items-center justify-end">
                    <input
                        x-model="q" @input="go(1)"
                        type="text" placeholder="Cari di tabel..."
                        class="fi-input block w-64 rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-gray-100" />
                </div>

                <div class="overflow-auto rounded-lg border border-gray-200 dark:border-gray-700">
                    <table class="w-full table-fixed min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-800 sticky top-0 z-10">
                            <tr class="text-left text-gray-700 dark:text-gray-200">
                                <th class="px-3 py-2 w-44">Pengguna</th>
                                <th class="px-3 py-2 w-56">Objek</th>
                                <th class="px-3 py-2 w-64">Nama Field</th>
                                <th class="px-3 py-2">Sebelum</th>
                                <th class="px-3 py-2">Sesudah</th>
                                <th class="px-3 py-2 w-40">Tanggal</th>
                                <th class="px-3 py-2 w-28">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($rows as $i => $r)
                            <tr x-show="pageRows.includes(rawRows[{{ $i }}])"
                                class="align-top hover:bg-gray-50 dark:hover:bg-gray-800/60 text-gray-900 dark:text-gray-100">
                                <td class="px-3 py-2 whitespace-nowrap">{{ $r['user'] }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">{{ $r['obj'] }}</td>
                                <td class="px-3 py-2 font-medium">{{ $labelize($r['field']) }}</td>
                                <td class="px-3 py-2">
                                    <div class="whitespace-pre-wrap break-words">
                                        {{ $human($r['field'], $r['old']) }}
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="whitespace-pre-wrap break-words">
                                        {{ $human($r['field'], $r['new']) }}
                                    </div>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">{{ $r['time'] }}</td>
                                <td class="px-3 py-2">
                                    <span class="px-2 py-0.5 text-xs rounded-full @{{ badgeClass('{{ $r['action'] }}') }}">
                                        {{ strtoupper($r['action']) }}
                                    </span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="flex items-center justify-between">
                    <div class="text-xs text-gray-600 dark:text-gray-400">
                        Menampilkan <span x-text="startIdx + 1"></span>–<span x-text="endIdx"></span> dari <span x-text="total"></span> baris
                    </div>

                    <div class="flex items-center gap-1">
                        <button type="button" class="fi-btn px-2 py-1 text-sm"
                            @click.prevent="go(1)" :disabled="page===1">« Pertama</button>
                        <button type="button" class="fi-btn px-2 py-1 text-sm"
                            @click.prevent="go(page-1)" :disabled="page===1">‹ Sebelumnya</button>

                        <template x-for="p in totalPages" :key="p">
                            <button type="button"
                                class="fi-btn px-2 py-1 text-sm rounded-md"
                                :class="page===p ? 'bg-primary-600 text-white dark:bg-primary-500' : 'bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200'"
                                @click.prevent="go(p)"
                                x-text="p">
                            </button>
                        </template>

                        <button type="button" class="fi-btn px-2 py-1 text-sm"
                            @click.prevent="go(page+1)" :disabled="page===totalPages">Berikutnya ›</button>
                        <button type="button" class="fi-btn px-2 py-1 text-sm"
                            @click.prevent="go(totalPages)" :disabled="page===totalPages">Terakhir »</button>
                    </div>
                </div>
            </div>
        </template>
    </div>