<?php

namespace App\Modules\Analytics;

use App\Models\User;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AnalyticsService
{
    public function device(User $u, array $data): object
    {
        Access::role($u, 'integration_admin');
        $d = Validator::make($data, ['code' => 'required|string|max:40', 'airport_code' => 'required|string|size:3', 'threshold' => 'required|numeric|between:-1000,1000'])->validate();
        Access::airport($u, $d['airport_code']);
        abort_unless(DB::connection('ops')->table('airports')->where('code', $d['airport_code'])->exists(), 422, 'Sân bay không tồn tại.');

        return DB::connection('ops')->transaction(function () use ($u, $d) {
            abort_if(DB::connection('ops')->table('devices')->where('code', $d['code'])->exists(), 409, 'Thiết bị đã tồn tại.');
            $id = DB::connection('ops')->table('devices')->insertGetId($d);
            Audit::record('iot.device.created', (string) $id, null, 'SIMULATED', $u->id, 'ops');

            return (object) ['id' => $id];
        });
    }

    public function telemetry(User $u, array $data): object
    {
        Access::role($u, 'integration_admin');
        $d = Validator::make($data, ['event_id' => 'required|uuid', 'device_id' => 'required|integer', 'sequence' => 'required|integer|between:1,1000000000', 'value' => 'required|numeric|between:-1000,1000', 'event_time' => 'required|date'])->validate();
        $d['event_time'] = Carbon::parse($d['event_time'])->utc()->startOfSecond()->format('Y-m-d H:i:s');
        abort_if(Carbon::parse($d['event_time'])->gt(now()->addSeconds(30)), 422, 'Thời gian thiết bị vượt quá ngưỡng tương lai.');
        $db = DB::connection('ops');

        return $db->transaction(function () use ($u, $db, $d) {
            $device = $db->table('devices')->where('id', $d['device_id'])->lockForUpdate()->first() ?? abort(404);
            Access::airport($u, $device->airport_code);
            $old = $db->table('telemetry')->find($d['event_id']);
            if ($old) {
                abort_unless($old->device_id === (int) $d['device_id'] && $old->sequence === (int) $d['sequence'] && (float) $old->value === (float) $d['value'] && Carbon::parse($old->event_time)->eq(Carbon::parse($d['event_time'])), 409, 'eventId trùng nhưng payload khác.');

                return $old;
            }
            abort_if($db->table('telemetry')->where('device_id', $device->id)->where('sequence', $d['sequence'])->exists(), 409, 'Sequence trùng nhưng eventId khác.');
            $late = $d['sequence'] <= $device->last_sequence || Carbon::parse($d['event_time'])->lt(now()->subMinutes(5)) || ($device->last_event_at && Carbon::parse($d['event_time'])->lt(Carbon::parse($device->last_event_at)));
            $db->table('telemetry')->insert(['id' => $d['event_id'], 'device_id' => $device->id, 'sequence' => $d['sequence'], 'value' => $d['value'], 'event_time' => Carbon::parse($d['event_time'])->utc(), 'received_at' => now(), 'late' => $late, 'alert' => $d['value'] > $device->threshold]);
            if (! $late) {
                $db->table('devices')->where('id', $device->id)->update(['last_sequence' => $d['sequence'], 'latest_value' => $d['value'], 'last_event_at' => Carbon::parse($d['event_time'])->utc()]);
            }
            Audit::record('iot.received', $d['event_id'], null, $late ? 'LATE' : ($d['value'] > $device->threshold ? 'ALERT' : 'OK'), $u->id, 'ops');

            return $db->table('telemetry')->find($d['event_id']);
        }, 3);
    }

    public function forecast(User $u, int $id): array
    {
        Access::role($u, 'analyst', 'operations', 'ops_manager');
        $f = DB::connection('ops')->table('flights')->find($id) ?? abort(404);
        Access::airport($u, $f->origin);
        $tasks = DB::connection('ops')->table('ground_tasks')->where('flight_id', $id)->where('status', '!=', 'COMPLETED')->where('due_at', '<', now())->count();
        $alerts = DB::connection('ops')->table('telemetry as t')->join('devices as d', 'd.id', '=', 't.device_id')->where('d.airport_code', $f->origin)->where('t.alert', true)->where('t.received_at', '>=', now()->subMinutes(30))->count();
        $score = min(95, 10 + min(4, $tasks) * 15 + min(3, $alerts) * 10 + ($f->status === 'DELAYED' ? 30 : 0));
        Audit::record('ai.simulated', (string) $id, null, (string) $score, $u->id, 'ops');

        return ['flight_id' => $id, 'model' => 'rules-demo-v1', 'simulated' => true, 'risk_percent' => $score, 'confidence' => 0.6, 'generated_at' => now()->toIso8601String(), 'factors' => ['overdue_tasks' => $tasks, 'recent_iot_alerts' => $alerts, 'already_delayed' => $f->status === 'DELAYED'], 'recommendation' => 'Kiểm tra nguồn lực và nhiệm vụ trễ; người điều hành quyết định. Đây không phải mô hình đã huấn luyện hoặc dự báo an toàn được chứng nhận.'];
    }
}
