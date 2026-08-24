<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // panjang 50 cukup singkat & efisien index, sesuaikan kalau perlu
            $table->string('username', 50)->nullable()->after('email')->unique();
        });

        // isi username untuk data lama secara aman & unik
        DB::transaction(function () {
            $users = DB::table('users')->select('id', 'name', 'email', 'username')->get();

            foreach ($users as $u) {
                if (!empty($u->username)) continue;

                // base: nama tanpa spasi/simbol; fallback: sebelum '@' dari email; terakhir: 'user'
                $base = trim((string) $u->name) !== '' 
                    ? preg_replace('/[^A-Za-z0-9_]/', '', Str::slug($u->name, '_'))
                    : (strstr((string)$u->email, '@', true) ?: 'user');

                $base = $base ?: 'user';
                $candidate = $base;
                $i = 1;

                // pastikan unik (case-insensitive)
                while (DB::table('users')->whereRaw('LOWER(username) = ?', [strtolower($candidate)])->exists()) {
                    $candidate = $base . $i;
                    $i++;
                }

                DB::table('users')->where('id', $u->id)->update(['username' => $candidate]);
            }
        });

        // jadikan NOT NULL
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // dropUnique name auto → gunakan index name yang dihasilkan Laravel
            $table->dropUnique('users_username_unique');
            $table->dropColumn('username');
        });
    }
};
