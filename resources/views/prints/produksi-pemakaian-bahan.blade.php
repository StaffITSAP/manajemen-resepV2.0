<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8" />
    <title>Print Pemakaian Bahan - {{ $produksi->nomor_produksi ?? 'Produksi' }}</title>

    @php
    $ori = ($orientation ?? 'portrait');
    $isLandscape = $ori === 'landscape';
    // Precompute semua angka, tapi JANGAN dipakai langsung di CSS pakai {{}}.
    // Kita bakal pilih blok CSS murni pakai @if supaya linter VSCode happy.
    @endphp

    @if ($isLandscape)
    <style>
        /* =====================  PAGE (LANDSCAPE) ===================== */
        @page {
            size: 297mm 210mm;
            margin: 12mm 12mm 14mm 12mm;
            /* atas kanan bawah kiri */
        }

        @media print {

            html,
            body {
                width: 297mm;
                height: 210mm;
            }

            .no-print {
                display: none !important;
            }

            a {
                color: inherit;
                text-decoration: none;
            }
        }

        /* =====================  GLOBAL ===================== */
        * {
            box-sizing: border-box;
        }

        html,
        body {
            padding: 0;
            margin: 0;
        }

        body {
            font: 12px/1.45 Arial, Helvetica, sans-serif;
            color: #0f172a;
            background: #fff;
        }

        .container {
            width: 100%;
        }

        /* =====================  HEADER ===================== */
        .topbar {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            column-gap: 8px;
            font-size: 11px;
            color: #64748b;
        }

        .topbar .center {
            text-align: center;
            font-weight: 600;
            color: #0f172a;
        }

        .topbar .right {
            text-align: right;
        }

        .title {
            margin: 8px 0 4px;
            font-size: 18px;
            font-weight: 700;
            color: #0b1220;
        }

        .meta {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
            font-size: 12px;
            color: #475569;
        }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 11px;
        }

        .badge-selesai {
            background: #dcfce7;
            color: #166534;
        }

        .badge-diproses {
            background: #fef9c3;
            color: #854d0e;
        }

        .badge-draft {
            background: #e5e7eb;
            color: #374151;
        }

        .badge-default {
            background: #fee2e2;
            color: #991b1b;
        }

        /* =====================  CARDS / CHIPS ===================== */
        .card {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #fff;
        }

        .card-body {
            padding: 10px 12px;
        }

        .chips {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 8px;
        }

        .chip {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #f8fafc;
            padding: 8px 10px;
        }

        .chip .nm {
            margin: 0 0 4px;
            font-weight: 700;
            font-size: 12px;
            color: #0f172a;
        }

        .chip .kv {
            font-size: 11px;
            color: #334155;
            margin-right: 14px;
            display: inline-block;
        }

        /* =====================  TABLE ===================== */
        .table-wrap {
            margin-top: 10px;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        colgroup col.c0 {
            width: 6%;
        }

        /* No. */
        colgroup col.c1 {
            width: 28%;
        }

        /* Bahan */
        colgroup col.c2 {
            width: 12%;
        }

        /* Satuan */
        colgroup col.c3 {
            width: 14%;
        }

        /* Bahan 1 Resep */
        colgroup col.c4 {
            width: 20%;
        }

        /* Bahan 1 Resep × Jml Produksi */
        colgroup col.c5 {
            width: 14%;
        }

        /* Bahan yang Diambil */
        colgroup col.c6 {
            width: 6%;
        }

        /* Selisih */

        thead th {
            background: #f3f4f6;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: .04em;
            font-size: 10.5px;
            font-weight: 700;
            padding: 9px 10px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
        }

        tbody td {
            padding: 9px 10px;
            border-bottom: 1px solid #eef2f7;
            vertical-align: top;
            font-size: 12px;
        }

        tbody tr:nth-child(even) td {
            background: #fafafa;
        }

        .right {
            text-align: right;
        }

        .break {
            word-break: break-word;
        }

        .num {
            font-weight: 800;
            color: #0f172a;
        }

        .unit {
            font-size: 10px;
            color: #64748b;
            margin-top: 2px;
        }

        .muted {
            color: #64748b;
        }

        .sum-row td {
            background: #f1f5f9;
            border-top: 1px solid #e2e8f0;
            font-weight: 700;
        }

        .note {
            border: 1px solid #bfdbfe;
            background: #eff6ff;
            color: #1e40af;
            border-radius: 10px;
            padding: 10px 12px;
            margin-top: 10px;
        }
    </style>
    @else
    <style>
        /* =====================  PAGE (PORTRAIT) ===================== */
        @page {
            size: 210mm 297mm;
            margin: 12mm 12mm 14mm 12mm;
            /* atas kanan bawah kiri */
        }

        @media print {

            html,
            body {
                width: 210mm;
                height: 297mm;
            }

            .no-print {
                display: none !important;
            }

            a {
                color: inherit;
                text-decoration: none;
            }
        }

        /* =====================  GLOBAL ===================== */
        * {
            box-sizing: border-box;
        }

        html,
        body {
            padding: 0;
            margin: 0;
        }

        body {
            font: 12px/1.45 Arial, Helvetica, sans-serif;
            color: #0f172a;
            background: #fff;
        }

        .container {
            width: 100%;
        }

        /* =====================  HEADER ===================== */
        .topbar {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            column-gap: 8px;
            font-size: 11px;
            color: #64748b;
        }

        .topbar .center {
            text-align: center;
            font-weight: 600;
            color: #0f172a;
        }

        .topbar .right {
            text-align: right;
        }

        .title {
            margin: 8px 0 4px;
            font-size: 18px;
            font-weight: 700;
            color: #0b1220;
        }

        .meta {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
            font-size: 12px;
            color: #475569;
        }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 11px;
        }

        .badge-selesai {
            background: #dcfce7;
            color: #166534;
        }

        .badge-diproses {
            background: #fef9c3;
            color: #854d0e;
        }

        .badge-draft {
            background: #e5e7eb;
            color: #374151;
        }

        .badge-default {
            background: #fee2e2;
            color: #991b1b;
        }

        /* =====================  CARDS / CHIPS ===================== */
        .card {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #fff;
        }

        .card-body {
            padding: 10px 12px;
        }

        .chips {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 8px;
        }

        .chip {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #f8fafc;
            padding: 8px 10px;
        }

        .chip .nm {
            margin: 0 0 4px;
            font-weight: 700;
            font-size: 12px;
            color: #0f172a;
        }

        .chip .kv {
            font-size: 11px;
            color: #334155;
            margin-right: 14px;
            display: inline-block;
        }

        /* =====================  TABLE ===================== */
        .table-wrap {
            margin-top: 10px;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        colgroup col.c1 {
            width: 32%;
        }

        col.c2 {
            width: 12%;
        }

        col.c3 {
            width: 14%;
        }

        col.c4 {
            width: 20%;
        }

        col.c5 {
            width: 14%;
        }

        col.c6 {
            width: 8%;
        }

        thead th {
            background: #f3f4f6;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: .04em;
            font-size: 10.5px;
            font-weight: 700;
            padding: 9px 10px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
        }

        tbody td {
            padding: 9px 10px;
            border-bottom: 1px solid #eef2f7;
            vertical-align: top;
            font-size: 12px;
        }

        tbody tr:nth-child(even) td {
            background: #fafafa;
        }

        .right {
            text-align: right;
        }

        .break {
            word-break: break-word;
        }

        .num {
            font-weight: 800;
            color: #0f172a;
        }

        .unit {
            font-size: 10px;
            color: #64748b;
            margin-top: 2px;
        }

        .muted {
            color: #64748b;
        }

        .sum-row td {
            background: #f1f5f9;
            border-top: 1px solid #e2e8f0;
            font-weight: 700;
        }

        .note {
            border: 1px solid #bfdbfe;
            background: #eff6ff;
            color: #1e40af;
            border-radius: 10px;
            padding: 10px 12px;
            margin-top: 10px;
        }
    </style>
    @endif
</head>

<body>
    <div class="container">
        {{-- ===== TOP BAR ===== --}}
        <div class="topbar">
            <div>{{ now()->format('d/m/Y, H:i') }}</div>
            <div class="center">Print Pemakaian Bahan - {{ $produksi->nomor_produksi ?? '-' }}</div>
        </div>

        {{-- ===== TITLE + META ===== --}}
        <h1 class="title">Detail Pemakaian Bahan</h1>
        @php
        $status = $produksi->status ?? 'draft';
        $badgeClass = match ($status) {
        'selesai' => 'badge badge-selesai',
        'diproses' => 'badge badge-diproses',
        'draft' => 'badge badge-draft',
        default => 'badge badge-default',
        };
        @endphp
        <div class="meta">
            <div>
                <span class="muted">Nomor:</span> <strong>{{ $produksi->nomor_produksi ?? '-' }}</strong>
                <span class="muted">Job Order Accurate:</span> <strong>{{ $produksi->accurate_number ?? '-' }}</strong>
                <span class="muted" style="margin-left:12px;">Tanggal:</span>
                <strong>{{ optional($produksi->tanggal)->format('d/m/Y') ?? '-' }}</strong>
            </div>
            <div><span class="{{ $badgeClass }}">{{ ucfirst($status) }}</span></div>
        </div>

        {{-- ===== CHIPS BARANG 1/2 JADI ===== --}}
        @if($produksi->itemProduksi->count())
        <div class="card">
            <div class="card-body">
                <div class="muted" style="margin-bottom:6px;">Barang 1/2 Jadi</div>
                <div class="chips">
                    @foreach ($produksi->itemProduksi as $it)
                    @php
                    $nm = $it->barang->nama ?? '-';
                    $sat = $it->barang->satuan->nama ?? '';
                    $ren = (float) ($it->jumlah ?? 0);
                    $akt = (float) ($it->jumlah_aktual ?? 0);
                    @endphp
                    <div class="chip">
                        <p class="nm">{{ $nm }}</p>
                        <span class="kv">Resep: <b>{{ $ren == (int)$ren ? number_format($ren,0,',','.') : number_format($ren,2,',','.') }} {{ $sat }}</b></span>
                        <span class="kv">Produksi: <b>{{ $akt == (int)$akt ? number_format($akt,0,',','.') : number_format($akt,2,',','.') }} {{ $sat }}</b></span>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        {{-- ===== TABEL REKAP ===== --}}
        @php
        $fmt = function ($n) {
        if ($n === null || $n === '') return '-';
        $n = (float) $n;
        return $n == (int) $n ? number_format($n, 0, ',', '.') : number_format($n, 2, ',', '.');
        };
        $cell = function ($n, $unit = '', $color = null) use ($fmt) {
        $n = (float) ($n ?? 0);
        $style = $color ? "style=\"color:{$color}\"" : '';
        return "<div class=\"num\" {$style}>{$fmt($n)}</div>" . ($unit ? "<div class=\"unit\">{$unit}</div>" : "");
        };

        // Agregasi
        $agg = []; $grandR=0; $grandA=0; $grandT=0; $grandS=0;
        foreach ($produksi->itemProduksi as $item) {
        foreach ($item->bahanProduksi as $bp) {
        $bahan = $bp->bahan;
        $nama = $bahan->nama ?? ('Bahan#'.$bp->bahan_id);
        $sat = $bahan->satuan->nama ?? '';
        $key = ($bahan->id ?? $bp->bahan_id).'|'.$sat;

        $r = (float) ($bp->jumlah ?? 0);
        $a = (float) ($bp->jumlah_aktual ?? 0);
        $t = (float) ($bp->total_produksi ?? 0);
        $s = (float) ($bp->selisih_produksi ?? ($t - $a));

        if (!isset($agg[$key])) $agg[$key] = ['nama'=>$nama,'satuan'=>$sat,'rencana'=>0,'aktual'=>0,'total'=>0,'selisih'=>0];
        $agg[$key]['rencana'] += $r; $grandR += $r;
        $agg[$key]['aktual'] += $a; $grandA += $a;
        $agg[$key]['total'] += $t; $grandT += $t;
        $agg[$key]['selisih'] += $s; $grandS += $s;
        }
        }
        $rows = collect($agg)->sortBy('nama')->values();
        @endphp

        <div class="table-wrap">
            <table>
                <colgroup>
                    <col class="c0">
                    <col class="c1">
                    <col class="c2">
                    <col class="c3">
                    <col class="c4">
                    <col class="c5">
                    <col class="c6">
                </colgroup>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>BAHAN</th>
                        <th>SATUAN</th>
                        <th class="right">BAHAN 1 RESEP</th>
                        <th class="right">BAHAN 1 RESEP × JML PRODUKSI</th>
                        <th class="right">BAHAN YANG DIAMBIL</th>
                        <th class="right">SELISIH</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $i => $row)
                    @php
                    $sat = $row['satuan'] ?? '';
                    $r = (float) ($row['rencana'] ?? 0);
                    $a = (float) ($row['aktual'] ?? 0);
                    $t = (float) ($row['total'] ?? 0);
                    $s = (float) ($row['selisih'] ?? 0);
                    $col = $s > 0 ? '#16a34a' : ($s < 0 ? '#dc2626' : '#334155' );
                        @endphp
                        <tr>
                        <td class="right">{{ $i + 1 }}</td>
                        <td class="break">{{ $row['nama'] }}</td>
                        <td>{{ $sat ?: '-' }}</td>
                        <td class="right">{!! $cell($r, $sat) !!}</td>
                        <td class="right">{!! $cell($a, $sat) !!}</td>
                        <td class="right">{!! $cell($t, $sat, '#3730a3') !!}</td>
                        <td class="right">{!! $cell($s, $sat, $col) !!}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="right muted">Tidak ada data pemakaian bahan</td>
                        </tr>
                        @endforelse

                        {{-- TOTAL --}}
                        <tr class="sum-row">
                            <td colspan="3" style="text-align:right;padding-right:10px;">TOTAL</td>
                            <td class="right">{!! $cell($grandR, '') !!}</td>
                            <td class="right">{!! $cell($grandA, '') !!}</td>
                            <td class="right">{!! $cell($grandT, '') !!}</td>
                            <td class="right">{!! $cell($grandS, '') !!}</td>
                        </tr>
                </tbody>
            </table>
        </div>

        @if ($produksi->catatan)
        <div class="note">
            <div style="font-weight:700; margin-bottom:4px;">Catatan</div>
            <div>{{ $produksi->catatan }}</div>
        </div>
        @endif
    </div>

    <script>
        // Tunda sedikit agar CSS @page & ukuran mm sudah ter-apply sebelum print
        window.addEventListener('load', () => setTimeout(() => window.print(), 350));
    </script>
</body>

</html>