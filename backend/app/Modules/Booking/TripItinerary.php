<?php

namespace App\Modules\Booking;

use Carbon\Carbon;

class TripItinerary
{
    public static function validate(string $type, array $flights, int $outboundCount): void
    {
        abort_unless(in_array($type, ['ONE_WAY', 'ROUND_TRIP'], true), 422, 'Chỉ hỗ trợ chuyến đi một chiều hoặc khứ hồi.');
        abort_unless($outboundCount >= 1 && $outboundCount <= 3 && count($flights) <= 6, 422, 'Mỗi lượt bay có tối đa hai điểm dừng.');
        abort_unless(count(array_unique(array_column($flights, 'id'))) === count($flights), 422, 'Không chọn lặp một chuyến bay.');
        $outbound = array_slice($flights, 0, $outboundCount);
        $inbound = array_slice($flights, $outboundCount);
        abort_unless(count($outbound) === $outboundCount && ($type === 'ONE_WAY' ? ! $inbound : count($inbound) >= 1 && count($inbound) <= 3), 422, 'Chưa chọn đủ lượt đi và lượt về.');
        foreach ([$outbound, $inbound] as $direction) {
            if (! $direction) {
                continue;
            }
            $visited = [$direction[0]->origin];
            foreach ($direction as $i => $flight) {
                abort_unless(in_array($flight->status, ['SCHEDULED', 'DELAYED', 'BOARDING']) && now()->lt($flight->departure), 409, 'Một chuyến đã ngừng nhận đặt chỗ. Vui lòng tìm lại.');
                abort_unless(! in_array($flight->destination, $visited, true) && Carbon::parse($flight->arrival)->gt($flight->departure), 422, 'Lịch trình không hợp lệ hoặc lặp sân bay.');
                $visited[] = $flight->destination;
                if ($i > 0) {
                    $previous = $direction[$i - 1];
                    $gap = Carbon::parse($previous->arrival)->diffInMinutes(Carbon::parse($flight->departure), false);
                    abort_unless($previous->destination === $flight->origin && $gap >= 60 && $gap <= 360, 422, 'Nối chuyến cần cùng sân bay và thời gian chờ từ 60 phút đến 6 giờ.');
                }
            }
            abort_unless(Carbon::parse($direction[0]->departure)->diffInHours(Carbon::parse($direction[count($direction) - 1]->arrival)) <= 24, 422, 'Một lượt bay không vượt quá 24 giờ.');
        }
        if ($inbound) {
            $lastOut = $outbound[count($outbound) - 1];
            $lastIn = $inbound[count($inbound) - 1];
            abort_unless($outbound[0]->origin === $lastIn->destination && $lastOut->destination === $inbound[0]->origin, 422, 'Lượt về phải đảo chiều điểm đi và điểm đến.');
            abort_unless(Carbon::parse($inbound[0]->departure)->gte(Carbon::parse($lastOut->arrival)->addMinutes(60)), 422, 'Lượt về phải sau khi lượt đi hoàn tất ít nhất 60 phút.');
        }
    }
}
