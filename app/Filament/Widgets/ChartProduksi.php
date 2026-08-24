<?php

namespace App\Filament\Widgets;

use App\Models\Produksi;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;
use Filament\Widgets\ChartWidget;
use PhpOffice\PhpSpreadsheet\Shared\Trend\Trend as TrendTrend;

class ChartProduksi extends ChartWidget
{
    protected static ?string $heading = 'Produksi Per Bulan';

    protected function getData(): array
    {
        $data = Trend::model(Produksi::class)
            ->between(
                start: now()->startOfYear(),
                end: now()->endOfYear(),
            )
            ->perMonth()
            ->count();

        return [
            'datasets' => [
                [
                    'label' => 'Produksi',
                    'data' => $data->map(fn (TrendValue $value) => $value->aggregate),
                ],
            ],
            'labels' => $data->map(fn (TrendValue $value) => $value->date),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}