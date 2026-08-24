<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
Artisan::command('app:after-deploy', function () {
    $flag = storage_path('app/deploy.flag');
    if (! File::exists($flag)) {
        $this->info('No deploy.flag found. Skip.');
        return;
    }

    $this->info('Running post-deploy tasks...');

    // Permission aman dulu
    @chmod(storage_path(), 0775);
    @chmod(base_path('bootstrap/cache'), 0775);

    Artisan::call('migrate', ['--force' => true]);
    $this->info(Artisan::output());

    // Kalau pertama kali atau butuh relink
    if (! file_exists(public_path('storage'))) {
        Artisan::call('storage:link');
        $this->info(Artisan::output());
    }

    Artisan::call('optimize:clear');
    $this->info(Artisan::output());

    // Filament optimize (kalau ada)
    try {
        Artisan::call('filament:optimize');
        $this->info(Artisan::output());
    } catch (\Throwable $e) {
        $this->warn('filament:optimize skipped: ' . $e->getMessage());
    }

    // Set permission lagi
    @chmod(storage_path(), 0775);
    @chmod(base_path('bootstrap/cache'), 0775);

    // Hapus flag
    @unlink($flag);

    $this->info('Post-deploy done.');
});