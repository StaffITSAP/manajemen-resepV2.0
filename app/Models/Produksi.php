<?php

namespace App\Models;

use App\Models\Traits\AuditableProduksiLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\LogPerubahanTrait;
use Illuminate\Database\Eloquent\SoftDeletes;

class Produksi extends Model
{
    use HasFactory, LogPerubahanTrait, SoftDeletes, AuditableProduksiLog;

    protected $table = 'produksi';
    protected $guarded = [];

    protected $casts = [
        'tanggal' => 'date',
        'accurate_total_amount' => 'float',
    ];

    // Eager load default
    protected $with = [
        'itemProduksi.barang',
        'itemProduksi.bahanProduksi.bahan.satuan',
    ];
    public function logs()
    {
        return $this->hasMany(\App\Models\ProduksiLog::class, 'produksi_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function itemProduksi(): HasMany
    {
        return $this->hasMany(ItemProduksi::class, 'produksi_id');
    }

    public function getLaporanPemakaianBahanAttribute()
    {
        $pemakaianBahan = [];

        foreach ($this->itemProduksi as $item) {
            $barang = $item->barang;
            if (!$barang) {
                continue;
            }

            $resep = Resep::with(['bahanResep.bahan.satuan'])
                ->where('barang_setengah_jadi_id', $item->barang_setengah_jadi_id)
                ->first();

            if (!$resep) {
                continue;
            }

            foreach ($resep->bahanResep as $bahanResep) {
                $jumlahBahanNumeric     = (float) $bahanResep->jumlah;
                $jumlahItemNumeric      = (float) $item->jumlah;
                $totalDibutuhkanNumeric = $jumlahBahanNumeric * $jumlahItemNumeric;

                $pemakaianBahan[] = [
                    'produksi_id'              => $this->id,
                    'item_produksi'            => $barang->nama ?? '-',
                    'jumlah_item_numeric'      => $jumlahItemNumeric,
                    'jumlah_bahan_numeric'     => $jumlahBahanNumeric,
                    'total_dibutuhkan_numeric' => $totalDibutuhkanNumeric,
                    'jumlah_item'              => $this->formatNumber($jumlahItemNumeric),
                    'jumlah_bahan'             => $this->formatNumber($jumlahBahanNumeric),
                    'total_dibutuhkan'         => $this->formatNumber($totalDibutuhkanNumeric),
                    'satuan'                   => $bahanResep->bahan->satuan->nama ?? '-',
                    'bahan'                    => $bahanResep->bahan->nama ?? '-',
                    'resep'                    => $resep->nama ?? '-',
                ];
            }
        }

        return collect($pemakaianBahan);
    }

    public function getTotalPemakaianBahanAttribute()
    {
        return $this->laporanPemakaianBahan->sum(function ($item) {
            return (float) str_replace(',', '.', $item['total_dibutuhkan']);
        });
    }

    public function getPersentaseSesuaiAttribute()
    {
        $totalItem = $this->itemProduksi->count();
        $sesuai = $this->itemProduksi->where('status', 'sesuai')->count();

        return $totalItem > 0 ? ($sesuai / $totalItem) * 100 : 0;
    }

    public function getIsLengkapAttribute(): bool
    {
        return $this->itemProduksi->where('jumlah_aktual', null)->count() === 0;
    }

    /** LIST barang setengah jadi: multi-baris */
    public function getBarangSetengahJadiListAttribute(): string
    {
        $rows = $this->itemProduksi()
            ->with(['barang.satuan'])
            ->get()
            ->map(function ($it) {
                $nm  = $it->barang?->nama ?? '-';
                $sat = $it->barang?->satuan?->nama ?? '';
                $qty = $this->formatNumber($it->jumlah);
                return "• {$nm} ({$qty} {$sat})";
            });

        return $rows->implode("\n");
    }

    /** LIST bahan yang dibutuhkan (agregat rencana) */
    public function getBahanListAttribute(): string
    {
        $needs = collect(); // key: bahan_id => total qty

        foreach ($this->itemProduksi as $it) {
            $resep = Resep::with('bahanResep.bahan.satuan')
                ->where('barang_setengah_jadi_id', $it->barang_setengah_jadi_id)
                ->first();

            if (!$resep) continue;

            $den    = (float) max(1, $resep->jumlah_barang_setengah_jadi);
            $factor = (float) $it->jumlah / $den;

            foreach ($resep->bahanResep as $br) {
                $needQty = (float) $br->jumlah * $factor;
                $needs[$br->bahan_id] = ($needs[$br->bahan_id] ?? 0) + $needQty;
            }
        }

        if ($needs->isEmpty()) return '-';

        $lines = $needs->map(function ($qty, $bahanId) {
            $b   = MasterBarang::with('satuan')->find($bahanId);
            $nm  = $b?->nama ?? 'Bahan#' . $bahanId;
            $sat = $b?->satuan?->nama ?? '';
            $qtyFmt = $this->formatNumber($qty);
            return "• {$nm}: {$qtyFmt} {$sat}";
        });

        return $lines->implode("\n");
    }

    public function getBahanListDbAttribute(): string
    {
        // Agregat semua bahan_produksi lintas item_produksi
        $totals = []; // [bahan_id => ['nama'=>..., 'satuan'=>..., 'qty'=>float]]

        foreach ($this->itemProduksi as $it) {
            foreach ($it->bahanProduksi as $bp) {
                $bid = $bp->bahan_id;
                $nm  = $bp->bahan?->nama ?? ('Bahan#' . $bid);
                $sat = $bp->bahan?->satuan?->nama ?? '';
                $qty = (float) ($bp->jumlah ?? 0); // ambil kolom 'jumlah' dari tabel bahan_produksi

                if (! isset($totals[$bid])) {
                    $totals[$bid] = ['nama' => $nm, 'satuan' => $sat, 'qty' => 0.0];
                }
                $totals[$bid]['qty'] += $qty;
            }
        }

        if (empty($totals)) {
            return '-';
        }

        $lines = [];
        foreach ($totals as $row) {
            $nm  = $row['nama'];
            $sat = $row['satuan'] ? ' ' . $row['satuan'] : '';
            $lines[] = "• {$nm} : " . $this->formatNumber($row['qty']) . $sat;
        }

        return implode("\n", $lines);
    }
    /** Format angka untuk tampilan */
    private function formatNumber($value): string
    {
        if (is_numeric($value)) {
            return (float) $value == (int) $value
                ? number_format($value, 0, ',', '.')
                : number_format($value, 2, ',', '.');
        }
        return '0';
    }

    // === Total untuk kolom tabel ===
    public function getTotalRencanaAttribute(): float
    {
        return (float) $this->itemProduksi()->sum('jumlah');
    }

    public function getTotalAktualAttribute(): float
    {
        return (float) $this->itemProduksi()->sum('jumlah_aktual');
    }

    public function getTotalSelisihAttribute(): float
    {
        return (float) $this->itemProduksi()->sum('selisih');
    }

    protected function formatTotalsByUnit(string $field): string
    {
        $items = $this->itemProduksi()->with('barang.satuan')->get();
        if ($items->isEmpty()) return '-';

        $totals = [];
        foreach ($items as $it) {
            $unit = $it->barang?->satuan?->nama ?? '';
            $val  = (float) ($it->{$field} ?? 0);
            if ($val === 0.0) continue;
            $totals[$unit] = ($totals[$unit] ?? 0) + $val;
        }

        if (empty($totals)) return '0';

        $lines = [];
        foreach ($totals as $unit => $sum) {
            $lines[] = $this->formatNumber($sum) . ($unit ? " {$unit}" : '');
        }

        return implode("\n", $lines);
    }

    public function getTotalRencanaWithUnitAttribute(): string
    {
        return $this->formatTotalsByUnit('jumlah');
    }

    public function getTotalAktualWithUnitAttribute(): string
    {
        return $this->formatTotalsByUnit('jumlah_aktual');
    }

    public function getTotalPemakaianBahanNumericAttribute(): float
    {
        $rows = $this->laporanPemakaianBahan;
        if ($rows->isEmpty()) return 0.0;

        return (float) $rows->sum(fn($r) => (float) ($r['total_dibutuhkan_numeric'] ?? 0));
    }

    public function getTotalPemakaianBahanWithUnitAttribute(): string
    {
        $rows  = $this->laporanPemakaianBahan;
        $total = $this->total_pemakaian_bahan_numeric;

        if ($rows->isEmpty()) return '0';

        $first = $rows->first();
        $unit  = is_array($first) ? ($first['satuan'] ?? '') : '';
        $formatted = $this->formatNumber($total);

        return $unit ? "{$formatted} {$unit}" : $formatted;
    }

    public function getTotalSelisihBahanAttribute(): float
    {
        return (float) ($this->itemProduksi->flatMap->bahanProduksi)->sum('selisih');
    }

    /* ========= Soft delete / restore cascade ========= */
    protected static function booted(): void
    {
        static::deleting(function (Produksi $produksi) {
            $force = method_exists($produksi, 'isForceDeleting') && $produksi->isForceDeleting();

            $produksi->itemProduksi()
                ->withTrashed()
                ->get()
                ->each(function (ItemProduksi $item) use ($force) {
                    $item->bahanProduksi()->withTrashed()->get()->each(function (BahanProduksi $bp) use ($force) {
                        $force ? $bp->forceDelete() : $bp->delete();
                    });

                    $force ? $item->forceDelete() : $item->delete();
                });
        });

        static::restoring(function (Produksi $produksi) {
            $produksi->itemProduksi()->onlyTrashed()->get()->each(function (ItemProduksi $item) {
                $item->restore();
                $item->bahanProduksi()->onlyTrashed()->restore();
            });
        });

        static::forceDeleted(function (Produksi $produksi) {
            $produksi->itemProduksi()->withTrashed()->get()->each(function (ItemProduksi $item) {
                $item->bahanProduksi()->withTrashed()->forceDelete();
                $item->forceDelete();
            });
        });
    }

    /* ===========================================================
     |  BARU: List pemakaian bahan aktual + selisih (rencana-aktual)
     |  -> Mengacu langsung ke tabel bahan_produksi:
     |     planned = jumlah, actual = jumlah_aktual, diff = planned - actual
     * ===========================================================*/
    public function getBahanPemakaianAktualSelisihListAttribute(): string
    {
        // agregat lintas semua item_produksi
        $agg = []; // [bahan_id => ['nama'=>..., 'satuan'=>..., 'planned'=>float, 'actual'=>float]]

        foreach ($this->itemProduksi as $it) {
            foreach ($it->bahanProduksi as $bp) {
                $bid = $bp->bahan_id;
                $nm  = $bp->bahan?->nama ?? ('Bahan#' . $bid);
                $sat = $bp->bahan?->satuan?->nama ?? '';

                if (!isset($agg[$bid])) {
                    $agg[$bid] = ['nama' => $nm, 'satuan' => $sat, 'planned' => 0.0, 'actual' => 0.0];
                }

                $agg[$bid]['planned'] += (float) ($bp->jumlah ?? 0);
                $agg[$bid]['actual']  += (float) ($bp->jumlah_aktual ?? 0);
            }
        }

        if (empty($agg)) return '-';

        $lines = [];
        foreach ($agg as $row) {
            $diff = (float) $row['planned'] - (float) $row['actual']; // sesuai DB
            $nm   = $row['nama'];
            $sat  = $row['satuan'] ? ' ' . $row['satuan'] : '';
            $lines[] = "• {$nm}: " . $this->formatNumber($row['actual']) . $sat .
                " (Selisih: " . $this->formatNumber($diff) . $sat . ")";
        }

        return implode("\n", $lines);
    }

    /* ===========================================================
     |  DIBENARKAN: List barang 1/2 jadi + Rencana/Aktual/Selisih
     |  -> Selisih mengikuti DB (rencana - aktual), tidak terbalik.
     * ===========================================================*/
    public function getBarangSetengahJadiListWithSelisihAttribute(): string
    {
        $rows = $this->itemProduksi()
            ->with(['barang.satuan'])
            ->get()
            ->map(function ($it) {
                $nm   = $it->barang?->nama ?? '-';
                $sat  = $it->barang?->satuan?->nama ?? '';
                $unit = $sat ? " {$sat}" : '';

                $rencana = (float) ($it->jumlah ?? 0);
                $aktual  = (float) ($it->jumlah_aktual ?? 0);
                $selisih = $rencana - $aktual; // BENAR: sama seperti di DB

                $rencanaFmt = $this->formatNumber($rencana) . $unit;
                $aktualFmt  = $this->formatNumber($aktual) . $unit;
                $selisihFmt = $this->formatNumber($selisih) . $unit;

                // 3 baris dengan newline supaya rapi (nl2br di kolom tabel)
                return "• {$nm}\nResep: {$rencanaFmt}\nProduksi: {$aktualFmt}\nSelisih: {$selisihFmt}";
            });

        return $rows->isEmpty() ? '-' : $rows->implode("\n\n");
    }
    public function accurateDocs()
    {
        return $this->hasMany(ProduksiAccurateDoc::class, 'produksi_id');
    }

    public function accurateJo()
    {
        return $this->hasOne(ProduksiAccurateDoc::class, 'produksi_id')->where('doc_type', 'JOB_ORDER');
    }

    public function accurateRo()
    {
        return $this->hasOne(ProduksiAccurateDoc::class, 'produksi_id')->where('doc_type', 'ROLL_OVER');
    }

    public function getJoTotalAmountAttribute(): float
    {
        return (float) optional($this->accurateJo)->total_amount ?? 0.0;
    }

    public function getRoAllocationAmountAttribute(): float
    {
        return (float) optional($this->accurateRo)->allocation_amount ?? 0.0;
    }
}
