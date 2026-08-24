<?php

if (!function_exists('fmt')) {
    /**
     * Format angka umum.
     * Contoh:
     * - 10 → "10"
     * - 10.25 → "10,25"
     * - null → "-"
     */
    function fmt($num): string {
        if ($num === null || $num === '') return '-';
        $num = (float) $num;
        return $num == (int) $num
            ? number_format($num, 0, ',', '.')
            : number_format($num, 2, ',', '.');
    }
}

if (!function_exists('fmtPct')) {
    /**
     * Format persen.
     * Contoh:
     * - 75 → "75%"
     * - 75.5 → "75,50%"
     * - null → "-"
     */
    function fmtPct($num): string {
        if ($num === null || $num === '') return '-';
        $num = (float) $num;
        $formatted = $num == (int) $num
            ? number_format($num, 0, ',', '.')
            : number_format($num, 2, ',', '.');
        return $formatted . ' %';
    }
}

if (!function_exists('fmtRp')) {
    /**
     * Format rupiah.
     * Contoh:
     * - 15000 → "Rp15.000"
     * - 15000.75 → "Rp15.000,75"
     * - null → "-"
     */
    function fmtRp($num): string {
        if ($num === null || $num === '') return '-';
        $num = (float) $num;
        $formatted = $num == (int) $num
            ? number_format($num, 0, ',', '.')
            : number_format($num, 2, ',', '.');
        return 'Rp ' . $formatted;
    }
}
