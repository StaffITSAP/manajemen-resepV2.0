<?php

namespace App\Services\Accurate;

use Carbon\CarbonImmutable;

final class Signature
{
    public static function makeTimestamp(string $tz): string
    {
        $now = CarbonImmutable::now($tz);
        return $now->format('Y-m-d\TH:i:s.uP') . '[' . $tz . ']';
    }

    public static function hmac(string $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $timestamp, $secret);
    }
}
