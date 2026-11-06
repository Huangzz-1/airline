<?php

namespace Tests\Unit;

use App\Modules\Boarding\SeatMap;
use PHPUnit\Framework\TestCase;

class CabinLayoutTest extends TestCase
{
    public function test_aircraft_types_have_distinct_aisles_and_exact_capacities(): void
    {
        foreach (['ATR72' => 68, 'A320' => 180, 'A321' => 220, 'B787' => 294, 'A350' => 305] as $type => $capacity) {
            $layout = SeatMap::preset('AUTO', $type, $capacity);
            $this->assertCount($capacity, SeatMap::seats($capacity, $layout));
            $this->assertSame($capacity, count(array_unique(SeatMap::seats($capacity, $layout))));
        }
        $atr = SeatMap::preset('AUTO', 'ATR72', 68);
        $this->assertTrue(SeatMap::contains('17D', 68, $atr, 'ECONOMY'));
        $this->assertFalse(SeatMap::contains('1E', 68, $atr));
        $this->assertFalse(SeatMap::contains('1A', 68, $atr, 'BUSINESS'));
        $a320 = SeatMap::preset('AUTO', 'A320', 180);
        $this->assertTrue(SeatMap::contains('1C', 180, $a320, 'BUSINESS'));
        $this->assertFalse(SeatMap::contains('1B', 180, $a320));
        $this->assertFalse(SeatMap::contains('1A', 180, $a320, 'ECONOMY'));
        $wide = SeatMap::rows(294, SeatMap::preset('AUTO', 'B787', 294));
        $this->assertCount(3, $wide[4]['blocks']);
    }

    public function test_sales_ceiling_does_not_erase_physical_seats(): void
    {
        $layout = SeatMap::preset('AUTO', 'A320', 180);
        $this->assertCount(180, SeatMap::seats(120, $layout));
        $this->assertTrue(SeatMap::contains('31F', 120, $layout, 'ECONOMY'));
    }

    public function test_duplicate_columns_are_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SeatMap::rows(4, ['cabins' => [['code' => 'ECONOMY', 'rows' => 1, 'blocks' => ['AA', 'CD']]]]);
    }
}
