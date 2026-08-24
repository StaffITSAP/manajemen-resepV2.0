<?php
// app/Http/Controllers/ProduksiPrintController.php

namespace App\Http\Controllers;

use App\Models\Produksi;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProduksiPrintController extends Controller
{
    public function show(Request $request, Produksi $record): View
    {
        // Eager load relasi agar view cepat
        $record->load([
            'itemProduksi.bahanProduksi.bahan.satuan',
            'itemProduksi.barang.satuan',
        ]);

        return view('prints.produksi-pemakaian-bahan', [
            'produksi' => $record,
        ]);
    }
}
