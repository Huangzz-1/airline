<?php

namespace App\Support;

final class Csv
{
    public static function download(string $name, array $header, iterable $rows)
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $write = function (array $row) use ($out) {
                fputcsv($out, array_map(fn ($value) => is_string($value) && preg_match('/^[=+@\-\t\r]/', $value) ? "'".$value : $value, $row), ',', '"', '');
            };
            $write($header);
            foreach ($rows as $row) {
                $write($row);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
