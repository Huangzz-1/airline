<?php

namespace App\Modules\Flights;

final class Carrier
{
    public const NAMES = ['AS' => 'Airline Smart', 'VN' => 'Vietnam Airlines', 'VJ' => 'Vietjet Air', 'QH' => 'Bamboo Airways', 'VU' => 'Vietravel Airlines'];

    public static function name(string $code): string
    {
        return self::NAMES[$code] ?? $code;
    }
}
