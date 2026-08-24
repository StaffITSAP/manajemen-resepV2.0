# 🧾 Manajemen Resep — Internal ERP Integration System

**Manajemen Resep** adalah sistem internal berbasis **Laravel 12** dan **Filament 3** yang dirancang untuk mengelola data transaksi, integrasi Accurate Online, serta proses otomatis seperti impor/ekspor Excel, sinkronisasi stok, dan pelaporan berbasis role.

Proyek ini dikembangkan secara **private** untuk kebutuhan internal perusahaan (Nyawiji Web Solutions), dengan fokus pada **stabilitas data**, **kemudahan audit**, dan **automasi proses bisnis**.

---

## 🚀 Tech Stack

| Layer | Teknologi |
|-------|------------|
| Backend | Laravel 12 (PHP 8.2) |
| Frontend | Filament v3 (TailwindCSS + Alpine.js) |
| Database | MySQL / MariaDB |
| Queue / Job | Laravel Queue (database/redis) |
| API Integration | Accurate Online API |
| File Handling | PhpSpreadsheet, Spatie SimpleExcel |
| Auth | Laravel Sanctum |
| Notifications | Filament Notifications |

---

## 🧩 Fitur Utama

### 📦 Modul Item Adjustment
- Upload file Excel (`.xlsx`) untuk penyesuaian stok.
- Template otomatis: `imports/template_import_item_adjustment.xlsx`.
- Validasi & parsing data menggunakan `PhpSpreadsheet`.
- Sinkronisasi ke **Accurate Online API** melalui `AccurateClient` service.
- Queue job: `App\Jobs\Accurate\ProcessItemAdjustmentUpload`.

### 🧮 Integrasi Accurate
- Service layer modular (`App\Services\Accurate\AccurateClient`).
- Menggunakan token `Authorization: Bearer <API_TOKEN>`.
- Mendukung multi-sesi database Accurate.
- Request log detail dan error handling di `storage/logs/accurate.log`.

### 🧰 Modul Filament Resource
- CRUD otomatis dengan tabel `item_adjustment_uploads`.
- Aksi khusus: “Kirim Ulang ke Accurate”.
- Filter tanggal, status, dan pencarian nomor transaksi.
- Form Dinamis: field `trans_date`, `description`, dll. (auto-filled dari hasil posting).

### 📊 Import & Monitoring
- Status tracking: `pending`, `processing`, `success`, `failed`.
- Log error disimpan pada kolom `error_message`.
- File hasil upload tersimpan di `storage/app/imports/item_adjustment`.

---