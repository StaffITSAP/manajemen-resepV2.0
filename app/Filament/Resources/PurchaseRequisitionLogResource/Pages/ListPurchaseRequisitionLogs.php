<?php

namespace App\Filament\Resources\PurchaseRequisitionLogResource\Pages;

use App\Filament\Resources\PurchaseRequisitionLogResource;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseRequisitionLogs extends ListRecords
{
    protected static string $resource = PurchaseRequisitionLogResource::class;

    protected static ?string $breadcrumb = 'Daftar';

    protected function getHeaderActions(): array
    {
        return [];
    }
}
