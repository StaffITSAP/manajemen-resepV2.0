<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /* ---------- Helpers tanpa Doctrine ---------- */

    private function hasIndex(string $table, string $index): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(1) c
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?",
            [$table, $index]
        );
        return (int)($row->c ?? 0) > 0;
    }

    private function addIndexIfMissing(string $table, string $column, string $index): void
    {
        if (! $this->hasIndex($table, $index)) {
            DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$index}` (`{$column}`)");
        }
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if ($this->hasIndex($table, $index)) {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
        }
    }

    private function fkName(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            "SELECT CONSTRAINT_NAME AS name
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1",
            [$table, $column]
        );
        return $row->name ?? null;
    }

    private function dropFkIfExists(string $table, string $column): ?string
    {
        $fk = $this->fkName($table, $column);
        if ($fk) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fk}`");
        }
        return $fk;
    }

    private function addUniqueIfMissing(string $table, array $cols, string $index): void
    {
        if (! $this->hasIndex($table, $index)) {
            $colStr = implode('`,`', $cols);
            DB::statement("ALTER TABLE `{$table}` ADD UNIQUE `{$index}` (`{$colStr}`)");
        }
    }

    /** Rapikan nama duplikat aktif agar bisa dibuat unique (tanpa hapus data) */
    private function dedupeActiveNames(string $table, string $pk = 'id', string $nameCol = 'nama'): void
    {
        $groups = DB::table($table)
            ->select($pk, $nameCol)
            ->whereNull('deleted_at')
            ->orderBy($nameCol)->orderBy($pk)
            ->get()
            ->groupBy($nameCol);

        foreach ($groups as $name => $rows) {
            if ($rows->count() <= 1) continue;
            $skipFirst = true;
            foreach ($rows as $r) {
                if ($skipFirst) { $skipFirst = false; continue; }
                DB::table($table)->where($pk, $r->$pk)
                  ->update([$nameCol => $name.' #'.$r->$pk]);
            }
        }
    }

    /* ---------------- Migrate ---------------- */

    public function up(): void
    {
        // 0) Pastikan ada index single-column utk FK
        $this->addIndexIfMissing('bahan_resep', 'resep_id', 'bahan_resep_resep_id_index');
        $this->addIndexIfMissing('bahan_resep', 'bahan_id', 'bahan_resep_bahan_id_index');

        // 1) Drop FK yang sedang memakai index unik komposit
        $this->dropFkIfExists('bahan_resep', 'resep_id');
        $this->dropFkIfExists('bahan_resep', 'bahan_id');

        // 2) Drop unique (resep_id, bahan_id) agar bahan boleh sama berulang
        $this->dropIndexIfExists('bahan_resep', 'bahan_resep_resep_id_bahan_id_unique');

        // 3) Re-add FK pakai index single-column
        DB::statement("
            ALTER TABLE `bahan_resep`
            ADD CONSTRAINT `bahan_resep_resep_id_foreign`
                FOREIGN KEY (`resep_id`) REFERENCES `resep`(`id`)
                ON UPDATE CASCADE ON DELETE CASCADE,
            ADD CONSTRAINT `bahan_resep_bahan_id_foreign`
                FOREIGN KEY (`bahan_id`) REFERENCES `master_barang`(`id`)
                ON UPDATE CASCADE ON DELETE RESTRICT
        ");

        // 4) Tegakkan keunikan nama untuk data aktif (tanpa hapus)
        $this->dedupeActiveNames('master_barang');
        $this->dedupeActiveNames('resep');

        // 5) Unique (nama, deleted_at) → hormati soft delete
        $this->addUniqueIfMissing('master_barang', ['nama','deleted_at'], 'master_barang_nama_deleted_unique');
        $this->addUniqueIfMissing('resep',         ['nama','deleted_at'], 'resep_nama_deleted_unique');
    }

    public function down(): void
    {
        // Balikkan urutan: drop FK → add unique → (opsional) drop index single-column

        $this->dropFkIfExists('bahan_resep', 'resep_id');
        $this->dropFkIfExists('bahan_resep', 'bahan_id');

        // Tambah lagi unique lama kalau ingin revert
        if (! $this->hasIndex('bahan_resep', 'bahan_resep_resep_id_bahan_id_unique')) {
            DB::statement("
                ALTER TABLE `bahan_resep`
                ADD UNIQUE `bahan_resep_resep_id_bahan_id_unique` (`resep_id`, `bahan_id`)
            ");
        }

        // Hapus unique nama
        $this->dropIndexIfExists('resep', 'resep_nama_deleted_unique');
        $this->dropIndexIfExists('master_barang', 'master_barang_nama_deleted_unique');

        // FK kembali (pakai index yang tersedia)
        DB::statement("
            ALTER TABLE `bahan_resep`
            ADD CONSTRAINT `bahan_resep_resep_id_foreign`
                FOREIGN KEY (`resep_id`) REFERENCES `resep`(`id`)
                ON UPDATE CASCADE ON DELETE CASCADE,
            ADD CONSTRAINT `bahan_resep_bahan_id_foreign`
                FOREIGN KEY (`bahan_id`) REFERENCES `master_barang`(`id`)
                ON UPDATE CASCADE ON DELETE RESTRICT
        ");
    }
};
