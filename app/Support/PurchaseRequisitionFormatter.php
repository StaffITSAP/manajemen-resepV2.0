<?php

namespace App\Support;

class PurchaseRequisitionFormatter
{
    public static function quantity(mixed $value): string
    {
        return number_format((float) $value, 6, ',', '.');
    }

    public static function integer(mixed $value): string
    {
        return number_format((int) $value, 0, ',', '.');
    }

    public static function rupiah(mixed $value): string
    {
        return 'Rp ' . number_format((float) $value, 2, ',', '.');
    }
}
