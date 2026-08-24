<?php

namespace App\Filament\Resources\AccurateJobOrderResource\Pages;

use App\Filament\Resources\AccurateJobOrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAccurateJobOrder extends ViewRecord
{
    protected static string $resource = AccurateJobOrderResource::class;

    protected function getHeaderWidgets(): array
    {
        return [];
    }
}
