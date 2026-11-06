<?php

namespace App\Modules\Booking;

use Illuminate\Support\Facades\Crypt;

class TripDetails
{
    public const COUNTRIES = ['VN' => 'Việt Nam', 'US' => 'Hoa Kỳ', 'GB' => 'Vương quốc Anh', 'AU' => 'Úc', 'JP' => 'Nhật Bản', 'KR' => 'Hàn Quốc', 'CN' => 'Trung Quốc', 'SG' => 'Singapore', 'TH' => 'Thái Lan', 'FR' => 'Pháp', 'DE' => 'Đức', 'ZZ' => 'Quốc tịch khác'];

    public static function encode(?array $details): ?string
    {
        return $details ? Crypt::encryptString(json_encode($details, JSON_THROW_ON_ERROR)) : null;
    }

    public static function decode(?string $value): array
    {
        return $value ? json_decode(Crypt::decryptString($value), true, 16, JSON_THROW_ON_ERROR) : [];
    }
}
