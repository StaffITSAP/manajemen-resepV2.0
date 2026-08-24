<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProduksiPrintController;
use App\Jobs\SyncAccurateItemsJob;
use App\Http\Controllers\Accurate\ItemAdjustmentTemplateController;


Route::get('/', function () {
    return redirect('/admin');
});
Route::middleware(['web', 'auth']) // sesuaikan middleware panelmu
    ->get('/admin/produksi/{record}/print', [ProduksiPrintController::class, 'show'])
    ->name('produksi.print');
Route::post('/accurate/items/sync', function () {
    SyncAccurateItemsJob::dispatch(); // atau ->dispatchSync() bila mau langsung
    return response()->json(['ok' => true]);
})->name('accurate.items.sync');
Route::get('/templates/item-adjustment', ItemAdjustmentTemplateController::class)
    ->name('templates.item-adjustment');
