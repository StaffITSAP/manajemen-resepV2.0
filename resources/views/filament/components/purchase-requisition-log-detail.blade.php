@php
    use App\Filament\Resources\PurchaseRequisitionLogResource;

    $currency = fn ($value) => 'Rp ' . number_format((float) $value, 0, ',', '.');
@endphp

<div class="space-y-4">
    <div class="grid gap-3 md:grid-cols-2">
        <div>
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Dibuat Oleh</dt>
            <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ PurchaseRequisitionLogResource::creatorName($record) }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Dibuat Pada</dt>
            <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $record->created_at?->format('d/m/Y H:i') ?? '-' }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Tanggal Permintaan</dt>
            <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $record->trans_date?->format('d/m/Y') ?? '-' }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Nomor Draft Accurate</dt>
            <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $record->accurate_number ?: '-' }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Accurate ID</dt>
            <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $record->accurate_id ?: '-' }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Cabang</dt>
            <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $record->branch_name ?: '-' }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Divisi Outlet</dt>
            <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $record->description ?: '-' }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Status Lokal</dt>
            <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ PurchaseRequisitionLogResource::localStatusLabel($record->status) }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Status Kirim</dt>
            <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ PurchaseRequisitionLogResource::sendResultLabel($record) }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Status Accurate</dt>
            <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $record->accurate_status ?: '-' }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Tersinkron Pada</dt>
            <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $record->synced_at?->format('d/m/Y H:i') ?? '-' }}</dd>
        </div>
    </div>

    @if (filled($record->error_message))
        <div class="rounded-xl border border-warning-200 bg-warning-50 p-3 text-sm text-warning-900 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-100">
            <div class="font-medium">Pesan Status</div>
            <div class="mt-1 break-words">{{ $record->error_message }}</div>
        </div>
    @endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Nama Barang</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Kode Barang</th>
                    <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300">Kuantitas</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Satuan</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Tanggal Diminta</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Keterangan</th>
                    <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300">Harga Satuan Referensi</th>
                    <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300">Harga Total Referensi</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Source PO</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white dark:divide-white/10 dark:bg-gray-900">
                @forelse ($record->items as $item)
                    <tr>
                        <td class="px-3 py-2 text-gray-950 dark:text-white">{{ $item->item_name }}</td>
                        <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $item->item_no }}</td>
                        <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-300">{{ rtrim(rtrim(number_format((float) $item->quantity, 6, '.', ''), '0'), '.') }}</td>
                        <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $item->item_unit_name }}</td>
                        <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $item->required_date?->format('d/m/Y') ?? '-' }}</td>
                        <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $item->note ?: '-' }}</td>
                        <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-300">{{ $currency($item->latest_purchase_unit_price) }}</td>
                        <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-300">{{ $currency($item->total_price) }}</td>
                        <td class="px-3 py-2 text-gray-700 dark:text-gray-300">
                            {{ $item->source_purchase_order_number ?: '-' }}
                            @if ($item->source_purchase_order_date)
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $item->source_purchase_order_date->format('d/m/Y') }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-3 py-6 text-center text-gray-500 dark:text-gray-400">Belum ada detail barang.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
