<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule
            ->command('accurate:sync-purchase-requisition-data')
            ->name('purchase-requisition-smart-sync-daily')
            ->dailyAt('03:30')
            ->timezone((string) config('app.timezone', 'Asia/Jakarta'))
            ->withoutOverlapping(360);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
