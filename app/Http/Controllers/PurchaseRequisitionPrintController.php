<?php

namespace App\Http\Controllers;

use App\Models\PurchaseRequisition;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class PurchaseRequisitionPrintController extends Controller
{
    public function show(PurchaseRequisition $record): Response
    {
        abort_unless(auth()->user()?->can('view', $record) === true, 403);
        abort_unless($this->isApproved($record), 404);

        $record->load(['approver', 'branch', 'user', 'items']);

        return Pdf::loadView('prints.purchase-requisition', [
            'record' => $record,
        ])->download('permintaan-barang-' . $record->id . '.pdf');
    }

    private function isApproved(PurchaseRequisition $record): bool
    {
        return filled($record->approved_at)
            && blank($record->rejected_at)
            && $record->status !== 'cancelled';
    }
}
