<?php

namespace App\Modules\Boarding;

/** Six seats per row in the current single-cabin demo configuration. */
final class SeatMap
{
    public static function contains(string $seat, int $capacity): bool
    {
        if (! preg_match('/^([1-9][0-9]*)([A-F])$/D', $seat, $match)) {
            return false;
        }
        $index = ((int) $match[1] - 1) * 6 + ord($match[2]) - ord('A');

        return $index >= 0 && $index < $capacity;
    }
}
