<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Permintaan Barang #{{ $record->id }}</title>
    <style>
        @page { margin: 24px 28px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 10px; }
        h1 { margin: 0 0 4px; font-size: 20px; }
        .brand { color: #64748b; font-size: 11px; font-weight: bold; }
        .meta { width: 100%; margin: 16px 0; }
        .meta td { padding: 3px 0; vertical-align: top; }
        .label { color: #64748b; width: 18%; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .items th { background: #f1f5f9; text-align: left; font-weight: bold; }
        .items th, .items td { border: 1px solid #cbd5e1; padding: 6px; }
        .right { text-align: right; }
        .total { border-top: 1px solid #94a3b8; margin-top: 14px; padding-top: 8px; text-align: right; font-size: 13px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="brand">Manajemen Resep</div>
    <h1>Permintaan Barang</h1>
    <table class="meta">
        <tr><td class="label">Tanggal</td><td>{{ $record->trans_date?->format('d/m/Y') ?? '-' }}</td><td class="label">Status</td><td>Disetujui</td></tr>
        <tr><td class="label">Divisi Outlet</td><td>{{ $record->description ?: '-' }}</td><td class="label">Cabang</td><td>{{ $record->branch_name ?: '-' }}</td></tr>
        <tr><td class="label">Dibuat Oleh</td><td>{{ $record->user?->name ?: '-' }}</td><td class="label">Nomor Accurate</td><td>{{ $record->accurate_number ?: '-' }}</td></tr>
        <tr><td></td><td></td><td class="label">Disetujui Oleh</td><td>{{ $record->approver?->name ?: '-' }}</td></tr>
    </table>
    <table class="items">
        <thead><tr><th>Barang</th><th>Kode</th><th class="right">Qty</th><th>Satuan</th><th>Tanggal Diminta</th><th>Keterangan</th><th class="right">Harga Satuan</th><th class="right">Harga Total</th><th>Source PO</th><th>Tanggal PO</th></tr></thead>
        <tbody>
        @foreach ($record->items as $item)
            <tr>
                <td>{{ $item->item_name }}</td><td>{{ $item->item_no ?: '-' }}</td><td class="right">{{ $item->quantity }}</td><td>{{ $item->item_unit_name }}</td><td>{{ $item->required_date?->format('d/m/Y') ?? '-' }}</td><td>{{ $item->note ?: '-' }}</td><td class="right">IDR {{ number_format((float) $item->latest_purchase_unit_price, 2, '.', ',') }}</td><td class="right">IDR {{ number_format((float) $item->total_price, 2, '.', ',') }}</td><td>{{ $item->source_purchase_order_number ?: '-' }}</td><td>{{ $item->source_purchase_order_date?->format('d/m/Y') ?? '-' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="total">Total Nilai : IDR {{ number_format((float) $record->items->sum('total_price'), 2, '.', ',') }}</div>
</body>
</html>
