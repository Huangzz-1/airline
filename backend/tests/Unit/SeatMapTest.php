<?php

namespace Tests\Unit;

use App\Modules\Boarding\SeatMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SeatMapTest extends TestCase
{
    public static function seats(): array
    {
        return [
            'first' => ['1A', 60, true], 'last' => ['10F', 60, true], 'outside' => ['11A', 60, false],
            'partial row exists' => ['2A', 7, true], 'partial row absent' => ['2B', 7, false],
            'no capacity' => ['1A', 0, false], 'zero row' => ['0A', 60, false], 'leading zero' => ['01A', 60, false],
            'invalid column' => ['1G', 60, false], 'trailing newline' => ["1A\n", 60, false],
        ];
    }

    #[DataProvider('seats')]
    public function test_seat_map_boundaries(string $seat, int $capacity, bool $valid): void
    {
        $this->assertSame($valid, SeatMap::contains($seat, $capacity));
    }
}
