<?php

namespace App\Modules\Operations;

use App\Models\User;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MasterFileService
{
    public function importAirports(User $u, string $path): int
    {
        Access::role($u, 'data_admin');
        $text = file_get_contents($path);
        abort_unless(mb_check_encoding($text, 'UTF-8'), 422, 'Tệp CSV cần dùng UTF-8.');
        $in = fopen($path, 'r');
        $header = fgetcsv($in, 0, ',', '"', '');
        if ($header) {
            $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
        }abort_unless($header === ['code', 'name', 'city', 'timezone'], 422, 'CSV phải có các cột code,name,city,timezone theo mẫu.');
        try {
            return DB::connection('ops')->transaction(function () use ($u, $in) {
                $count = 0;
                while (($row = fgetcsv($in, 0, ',', '"', '')) !== false) {
                    if ($row === [null]) {
                        continue;
                    }abort_if(++$count > 200, 422, 'Tối đa 200 sân bay mỗi lần nhập.');
                    abort_unless(count($row) === 4, 422, 'Số cột không khớp CSV.');
                    $d = array_combine(['code', 'name', 'city', 'timezone'], $row);
                    $d = Validator::make($d, ['code' => 'required|regex:/^[A-Z]{3}$/', 'name' => 'required|string|max:120', 'city' => 'required|string|max:100', 'timezone' => 'required|timezone'])->validate();
                    $db = DB::connection('ops');
                    abort_if($db->table('airports')->where('code', $d['code'])->exists() || $db->table('master_changes')->where('entity', 'airports')->where('record_id', $d['code'])->where('status', 'PENDING')->exists(), 409, 'Mã sân bay đã có hoặc đang chờ duyệt: '.$d['code']);
                    $id = $db->table('master_changes')->insertGetId(['entity' => 'airports', 'record_id' => $d['code'], 'before_data' => 'null', 'after_data' => json_encode($d, JSON_THROW_ON_ERROR), 'reason' => 'Nhập danh mục từ CSV; chờ phê duyệt độc lập', 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
                    Audit::record('master.import.staged', (string) $id, null, 'PENDING', $u->id, 'ops');
                }abort_if(! $count, 422, 'CSV không có dữ liệu.');

                return $count;
            });
        } finally {
            fclose($in);
        }
    }

    public function export(User $u, string $entity)
    {
        abort_unless(isset(MasterDataService::RULES[$entity]), 404);
        Access::role($u, MasterDataService::RULES[$entity][0]);
        $columns = match ($entity) {
            'airports' => ['code', 'name', 'city', 'timezone'],'routes' => ['id', 'origin', 'destination', 'duration_minutes', 'distance_km', 'active'],'airport_resources' => ['id', 'airport_code', 'code', 'type', 'active'],'aircraft' => ['id', 'registration', 'type', 'capacity', 'status', 'flight_hours', 'cycles'],'crew_members' => ['id', 'code', 'name', 'position', 'qualification', 'license_expires', 'available']
        };
        Audit::record('master.exported', $entity, null, 'CSV', $u->id, 'ops');

        return response()->streamDownload(function () use ($entity, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns, ',', '"', '');
            foreach (DB::connection('ops')->table($entity)->select($columns)->orderBy($columns[0])->cursor() as $row) {
                $values = [];
                foreach ($columns as $c) {
                    $v = (string) $row->$c;
                    $values[] = preg_match('/^[=+@\-\t\r]/', $v) ? "'".$v : $v;
                }fputcsv($out, $values, ',', '"', '');
            }fclose($out);
        }, $entity.'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }
}
