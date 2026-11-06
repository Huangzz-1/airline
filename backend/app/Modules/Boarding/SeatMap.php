<?php

namespace App\Modules\Boarding;

final class SeatMap
{
    public static function contains(string $seat, int $capacity, array|string|null $layout = null, ?string $fareClass = null): bool
    {
        if ($layout !== null) {
            return in_array($seat, self::seats($capacity, $layout, $fareClass), true);
        }
        if (! preg_match('/^([1-9][0-9]*)([A-F])$/D', $seat, $match)) {
            return false;
        }
        $index = ((int) $match[1] - 1) * 6 + ord($match[2]) - ord('A');

        return $index >= 0 && $index < $capacity;
    }

    public static function presets(): array
    {
        return ['LEGACY' => 'Sáu ghế mỗi hàng (cấu hình hiện có)', 'AUTO' => 'Theo loại tàu, có phân khoang', 'NARROW' => 'Thân hẹp: thương gia 2–2, phổ thông 3–3', 'WIDE' => 'Thân rộng: thương gia 1–2–1, phổ thông 3–3–3', 'REGIONAL' => 'Tàu khu vực: phổ thông 2–2'];
    }

    public static function preset(string $preset, string $type, int $capacity): ?array
    {
        if ($preset === 'LEGACY') {
            return null;
        }
        if ($preset === 'AUTO') {
            $preset = preg_match('/ATR|DHC|Q400/i', $type) ? 'REGIONAL' : (preg_match('/787|777|330|350|380/i', $type) ? 'WIDE' : 'NARROW');
        }
        if (! in_array($preset, ['NARROW', 'WIDE', 'REGIONAL'], true)) {
            throw new \InvalidArgumentException('Unknown seat layout');
        }
        $cabins = [];
        $remaining = $capacity;
        if ($preset !== 'REGIONAL' && $capacity >= 40) {
            $rows = $capacity >= 200 ? 4 : 3;
            $cabins[] = ['code' => 'BUSINESS', 'name' => 'Thương gia', 'rows' => $rows, 'blocks' => $preset === 'WIDE' ? ['A', 'DG', 'K'] : ['AC', 'DF']];
            $remaining -= $rows * 4;
        }
        $blocks = match ($preset) {
            'REGIONAL' => ['AB', 'CD'], 'WIDE' => ['ABC', 'DEF', 'HJK'], default => ['ABC', 'DEF']
        };
        $perRow = strlen(implode('', $blocks));
        $cabins[] = ['code' => 'ECONOMY', 'name' => 'Phổ thông', 'rows' => (int) ceil($remaining / $perRow), 'blocks' => $blocks, 'last_row_seats' => $remaining % $perRow ?: $perRow];

        return ['cabins' => $cabins];
    }

    public static function rows(int $capacity, array|string|null $layout = null): array
    {
        if ($capacity < 1) {
            return [];
        }
        if (is_string($layout)) {
            $layout = json_decode($layout, true, 32, JSON_THROW_ON_ERROR);
        }
        $layout ??= ['cabins' => [['code' => 'ANY', 'name' => 'Khoang hành khách', 'rows' => (int) ceil($capacity / 6), 'blocks' => ['ABC', 'DEF'], 'last_row_seats' => $capacity % 6 ?: 6]]];
        $rows = [];
        $number = 1;
        $total = 0;
        if (! is_array($layout['cabins'] ?? null) || count($layout['cabins']) < 1 || count($layout['cabins']) > 3) {
            throw new \InvalidArgumentException('Invalid cabin count');
        }
        foreach ($layout['cabins'] as $cabin) {
            if (! is_array($cabin) || ! is_array($cabin['blocks'] ?? null) || array_filter($cabin['blocks'], fn ($value) => ! is_string($value)) || (isset($cabin['name']) && ! is_string($cabin['name']))) {
                throw new \InvalidArgumentException('Invalid cabin format');
            }
            if (($cabin['code'] ?? '') === 'ANY' && count($layout['cabins']) !== 1) {
                throw new \InvalidArgumentException('Legacy cabin cannot be mixed with named cabins');
            }
            $blocks = $cabin['blocks'] ?? [];
            $letters = implode('', $blocks);
            $count = (int) ($cabin['rows'] ?? 0);
            $perRow = strlen($letters);
            if (! in_array($cabin['code'] ?? '', ['ANY', 'BUSINESS', 'PREMIUM', 'ECONOMY'], true) || $count < 1 || $count > 150 || count($blocks) < 1 || count($blocks) > 4 || ! preg_match('/^[A-Z]{1,12}$/D', $letters) || count(array_unique(str_split($letters))) !== $perRow) {
                throw new \InvalidArgumentException('Invalid cabin rows or seat columns');
            }
            for ($i = 0; $i < $count; $i++, $number++) {
                $limit = $i === $count - 1 ? (int) ($cabin['last_row_seats'] ?? $perRow) : $perRow;
                if ($limit < 1 || $limit > $perRow) {
                    throw new \InvalidArgumentException('Invalid final row');
                }
                $columns = [];
                foreach ($blocks as $block) {
                    $seats = [];
                    foreach (str_split($block) as $letter) {
                        if ($limit-- > 0) {
                            $seats[] = $number.$letter;
                            $total++;
                        }
                    }
                    $columns[] = $seats;
                }
                $rows[] = ['number' => $number, 'cabin' => $cabin['code'], 'name' => $cabin['name'] ?? $cabin['code'], 'blocks' => $columns];
            }
        }
        if ($total < $capacity || $total > 600) {
            throw new \InvalidArgumentException('Seat layout capacity mismatch');
        }

        return $rows;
    }

    public static function seats(int $capacity, array|string|null $layout = null, ?string $fareClass = null): array
    {
        $cabin = match ($fareClass) {
            'BUSINESS' => 'BUSINESS', 'PREMIUM' => 'PREMIUM', null => null, default => 'ECONOMY'
        };
        $seats = [];
        foreach (self::rows($capacity, $layout) as $row) {
            if ($cabin !== null && ! in_array($row['cabin'], ['ANY', $cabin], true)) {
                continue;
            }
            foreach ($row['blocks'] as $block) {
                array_push($seats, ...$block);
            }
        }

        return $seats;
    }

    public static function cabin(string $fareClass): string
    {
        return in_array($fareClass, ['BUSINESS', 'PREMIUM'], true) ? $fareClass : 'ECONOMY';
    }
}
