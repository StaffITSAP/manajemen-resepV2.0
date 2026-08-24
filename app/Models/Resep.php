<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\LogPerubahanTrait;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Traits\AuditableResepLog;

class Resep extends Model
{
    use HasFactory, SoftDeletes, LogPerubahanTrait, AuditableResepLog;

    protected $table = 'resep';
    protected $guarded = [];

    /**
     * Barang setengah jadi yang dihasilkan resep.
     */
    public function barangSetengahJadi(): BelongsTo
    {
        return $this->belongsTo(MasterBarang::class, 'barang_setengah_jadi_id');
    }

    /**
     * Relasi ke detail bahan resep.
     */
    public function bahanResep(): HasMany
    {
        return $this->hasMany(BahanResep::class, 'resep_id');
    }

    /**
     * Total item bahan pada resep (untuk badge di tabel).
     */
    public function getTotalBahanAttribute(): int
    {
        // pakai collection kalau sudah eager loaded, kalau belum akan query
        return $this->bahanResep->count();
    }

    /**
     * Detail bahan (nama, jumlah, satuan, catatan) – aman null.
     */
    public function getBahanDetailsAttribute()
    {
        return $this->bahanResep->map(function ($bahan) {
            $mb = $bahan->bahan; // MasterBarang bahan
            return [
                'bahan'   => $mb?->nama ?? 'Bahan#' . $bahan->bahan_id,
                'jumlah'  => $this->formatNumber($bahan->jumlah), // Format angka jumlah
                'satuan'  => $mb?->satuan?->nama ?? '',
                'catatan' => $bahan->catatan,
            ];
        });
    }

    /**
     * Label jumlah hasil dengan satuan, contoh: "9 Pcs"
     */
    public function getJumlahHasilDenganSatuanAttribute(): string
    {
        $qty = $this->formatNumber($this->jumlah_barang_setengah_jadi); // Format angka jumlah
        $unit = $this->barangSetengahJadi?->satuan?->nama;
        return trim($qty . ' ' . ($unit ?? ''));
    }

    /** Teks multiline bahan: "• Nama: 300,00 Gram" */
    public function getBahanListAttribute(): string
    {
        // Pastikan relasi & satuan ada supaya hemat query
        $this->loadMissing('bahanResep.bahan.satuan');

        if ($this->bahanResep->isEmpty()) {
            return '-';
        }

        return $this->bahanResep->map(function ($br) {
            $nama = $br->bahan?->nama ?? 'Bahan#' . $br->bahan_id;
            $sat  = $br->bahan?->satuan?->nama ?? '';
            $qty  = $this->formatNumber($br->jumlah); // Format angka jumlah
            return "• {$nama}: {$qty} {$sat}";
        })->implode("\n");
    }

    /**
     * Fungsi untuk format angka: tanpa desimal jika angka bulat, dengan dua desimal jika tidak
     */
    private function formatNumber($value): string
    {
        // Jika angka adalah bulat, jangan tampilkan desimal
        if (is_numeric($value)) {
            $formatted = (float) $value == (int) $value
                ? number_format($value, 0, ',', '.')  // Format tanpa desimal
                : number_format($value, 2, ',', '.'); // Format dengan dua desimal
            return $formatted;
        }
        return '0'; // Default jika nilai tidak valid
    }
    public function logs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\ResepLog::class, 'resep_id')->latest();
    }
}
