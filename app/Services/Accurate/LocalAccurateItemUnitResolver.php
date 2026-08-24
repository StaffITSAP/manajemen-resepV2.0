<?php

namespace App\Services\Accurate;

use App\Models\AccurateItemUnit;
use Illuminate\Support\Collection;

class LocalAccurateItemUnitResolver
{
    /**
     * @return \Illuminate\Support\Collection<int, AccurateItemUnit>
     */
    public function unitsForItemAccurateId(int|string $itemAccurateId): Collection
    {
        return AccurateItemUnit::query()
            ->where('item_accurate_id', (int) $itemAccurateId)
            ->orderBy('position')
            ->orderBy('item_unit_name')
            ->get();
    }
}
