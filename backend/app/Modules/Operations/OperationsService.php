<?php

namespace App\Modules\Operations;

use App\Models\User;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use App\Modules\Shared\Events;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OperationsService
{
    private function db()
    {
        return DB::connection('ops');
    }

    public function flight(int $id): object
    {
        return $this->db()->table('flights')->find($id) ?? abort(404);
    }

    public function execute(User $u, string $action, array $input): object
    {
        [$roles,$rules] = match ($action) {
            'airport' => [['data_admin'], ['code' => 'required|regex:/^[A-Z]{3}$/', 'name' => 'required|string|max:120', 'city' => 'required|string|max:100', 'timezone' => 'required|timezone']],
            'route' => [['data_admin'], ['origin' => 'required|string|size:3', 'destination' => 'required|string|size:3|different:origin', 'duration_minutes' => 'required|integer|between:15,1440', 'distance_km' => 'required|integer|between:1,30000']],
            'resource' => [['data_admin'], ['airport_code' => 'required|string|size:3', 'code' => 'required|string|max:30', 'type' => 'required|in:TERMINAL,GATE,STAND,COUNTER']],
            'aircraft' => [['fleet_manager'], ['registration' => 'required|regex:/^[A-Z0-9-]{3,20}$/', 'type' => 'required|string|max:80', 'capacity' => 'required|integer|between:1,600']],
            'crew' => [['crew_scheduler'], ['code' => 'required|string|max:30', 'name' => 'required|string|max:100', 'position' => 'required|in:PILOT,CABIN', 'qualification' => 'required|string|max:80', 'license_expires' => 'required|date|after:today']],
            'season' => [['operations'], ['number' => 'required|regex:/^[A-Z]{2}[0-9]{2,5}$/', 'route_id' => 'required|integer', 'aircraft_id' => 'required|integer', 'from_date' => 'required|date_format:Y-m-d|after:today', 'to_date' => 'required|date_format:Y-m-d|after_or_equal:from_date', 'weekdays' => 'required|regex:/^[1-7](,[1-7])*$/', 'local_departure' => 'required|date_format:H:i', 'duration_minutes' => 'required|integer|between:15,720', 'sale_price' => 'required|integer|between:1000,100000000', 'gate' => 'required|string|max:20']],
            'submit_batch' => [['operations'], ['flight_ids' => 'required|regex:/^[0-9]+(,[0-9]+){0,30}$/']],
            'publish_batch' => [['ops_manager'], ['flight_ids' => 'required|regex:/^[0-9]+(,[0-9]+){0,30}$/']],
            'schedule' => [['operations'], ['number' => 'required|regex:/^[A-Z]{2}[0-9]{2,5}$/', 'route_id' => 'required|integer', 'aircraft_id' => 'required|integer', 'departure' => 'required|date|after:now', 'arrival' => 'required|date|after:departure', 'sale_price' => 'required|integer|between:1000,100000000', 'gate' => 'required|string|max:20']],
            'assign_crew' => [['crew_scheduler'], ['flight_id' => 'required|integer', 'crew_member_id' => 'required|integer']],
            'submit' => [['operations'], ['flight_id' => 'required|integer']],
            'publish' => [['ops_manager'], ['flight_id' => 'required|integer']],
            'flight_state' => [['operations'], ['flight_id' => 'required|integer', 'status' => 'required|in:BOARDING,GATE_CLOSED,DEPARTED,ARRIVED', 'reason' => 'required|string|max:255']],
            'work_order' => [['maintenance'], ['aircraft_id' => 'required|integer', 'title' => 'required|string|max:150', 'due_at' => 'required|date|after:now']],
            'complete_work' => [['maintenance'], ['work_order_id' => 'required|integer', 'evidence' => 'required|string|min:10|max:4000']],
            'release_work' => [['maintenance'], ['work_order_id' => 'required|integer']],
            'unallocate' => [['operations'], ['allocation_id' => 'required|integer', 'reason' => 'required|string|min:5|max:255']],
            'allocate' => [['operations'], ['flight_id' => 'required|integer', 'resource_id' => 'required|integer', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'reason' => 'required|string|max:255']],
            'ground_task' => [['operations'], ['flight_id' => 'required|integer', 'title' => 'required|string|max:150', 'assigned_to' => 'required|integer', 'due_at' => 'required|date', 'mandatory' => 'required|boolean']],
            'ground_state' => [['ground_agent'], ['task_id' => 'required|integer', 'status' => 'required|in:IN_PROGRESS,COMPLETED', 'evidence' => 'nullable|string|max:4000']],
            'disruption' => [['operations'], ['flight_id' => 'required|integer', 'type' => 'required|in:DELAY,CANCEL', 'reason' => 'required|string|min:5|max:255', 'delay_minutes' => 'required|integer|between:0,1440']],
            'approve_disruption' => [['ops_manager'], ['disruption_id' => 'required|integer']],
            default => abort(404),
        };
        Access::role($u, ...$roles);
        $d = Validator::make($input, $rules)->validate();
        foreach (['departure', 'arrival', 'due_at', 'starts_at', 'ends_at'] as $key) {
            if (isset($d[$key])) {
                $d[$key] = Carbon::parse($d[$key])->utc()->format('Y-m-d H:i:s');
            }
        }

        return $this->db()->transaction(fn () => $this->$action($u, $d), 3);
    }

    private function audit(User $u, string $action, string|int $id, ?string $before, ?string $after): void
    {
        Audit::record($action, (string) $id, $before, $after, $u->id, 'ops');
    }

    private function lockedFlight(User $u, int $id): object
    {
        $f = $this->db()->table('flights')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
        Access::airport($u, $f->origin);

        return $f;
    }

    private function airport(User $u, array $d): object
    {
        abort_if($this->db()->table('airports')->where('code', $d['code'])->exists(), 409, 'Mã sân bay đã tồn tại.');
        $this->db()->table('airports')->insert($d);
        $this->audit($u, 'airport.created', $d['code'], null, 'ACTIVE');

        return (object) $d;
    }

    private function route(User $u, array $d): object
    {
        foreach (['origin', 'destination'] as $key) {
            abort_unless($this->db()->table('airports')->where('code', $d[$key])->exists(), 422, 'Sân bay không tồn tại.');
        }
        abort_if($this->db()->table('routes')->where('origin', $d['origin'])->where('destination', $d['destination'])->exists(), 409, 'Đường bay đã tồn tại.');
        $id = $this->db()->table('routes')->insertGetId($d);
        $this->audit($u, 'route.created', $id, null, 'ACTIVE');

        return (object) ['id' => $id];
    }

    private function resource(User $u, array $d): object
    {
        abort_unless($this->db()->table('airports')->where('code', $d['airport_code'])->exists(), 422, 'Sân bay không tồn tại.');
        abort_if($this->db()->table('airport_resources')->where('airport_code', $d['airport_code'])->where('code', $d['code'])->exists(), 409, 'Mã tài nguyên đã tồn tại.');
        $id = $this->db()->table('airport_resources')->insertGetId($d);
        $this->audit($u, 'resource.created', $id, null, 'ACTIVE');

        return (object) ['id' => $id];
    }

    private function aircraft(User $u, array $d): object
    {
        abort_if($this->db()->table('aircraft')->where('registration', $d['registration'])->exists(), 409, 'Số đăng ký đã tồn tại.');
        $id = $this->db()->table('aircraft')->insertGetId($d + ['status' => 'AVAILABLE']);
        $this->audit($u, 'aircraft.created', $id, null, 'AVAILABLE');

        return (object) ['id' => $id];
    }

    private function crew(User $u, array $d): object
    {
        abort_if($this->db()->table('crew_members')->where('code', $d['code'])->exists(), 409, 'Mã nhân sự đã tồn tại.');
        $id = $this->db()->table('crew_members')->insertGetId($d);
        $this->audit($u, 'crew.created', $id, null, 'AVAILABLE');

        return (object) ['id' => $id];
    }

    private function schedule(User $u, array $d): object
    {
        $route = $this->db()->table('routes')->find($d['route_id']) ?? abort(422, 'Đường bay không tồn tại.');
        Access::airport($u, $route->origin);
        abort_unless($route->active, 409, 'Đường bay ngừng khai thác.');
        $plane = $this->db()->table('aircraft')->where('id', $d['aircraft_id'])->lockForUpdate()->first() ?? abort(422, 'Tàu bay không tồn tại.');
        abort_unless($plane->status === 'AVAILABLE', 409, 'Tàu bay chưa khả dụng.');
        $d['departure'] = Carbon::parse($d['departure'])->utc();
        abort_unless(now()->lt($d['departure']), 422, 'Ngày khởi hành phải trong tương lai.');
        $d['arrival'] = Carbon::parse($d['arrival'])->utc();
        abort_if($d['departure']->diffInMinutes($d['arrival']) < $route->duration_minutes, 422, 'Thời lượng ngắn hơn thời gian chuẩn của đường bay.');
        abort_if($this->db()->table('flights')->where('number', $d['number'])->where('departure', $d['departure'])->exists(), 409, 'Chuyến đã tồn tại.');
        $id = $this->db()->table('flights')->insertGetId($d + ['origin' => $route->origin, 'destination' => $route->destination, 'status' => 'DRAFT', 'created_by' => $u->id]);
        $this->audit($u, 'schedule.created', $id, null, 'DRAFT');

        return $this->flight($id);
    }

    private function season(User $u, array $d): object
    {
        $route = $this->db()->table('routes')->find($d['route_id']) ?? abort(404);
        Access::airport($u, $route->origin);
        $airport = $this->db()->table('airports')->where('code', $route->origin)->first();
        $from = Carbon::parse($d['from_date'], $airport->timezone);
        $to = Carbon::parse($d['to_date'], $airport->timezone);
        abort_if($from->diffInDays($to) > 31, 422, 'Mỗi đợt lập lịch tối đa 31 ngày; có thể lập nhiều đợt theo mùa.');
        $days = array_map('intval', explode(',', $d['weekdays']));
        $ids = [];
        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            if (in_array($date->isoWeekday(), $days)) {
                $departure = Carbon::parse($date->format('Y-m-d').' '.$d['local_departure'], $airport->timezone)->utc();
                $flight = $this->schedule($u, ['number' => $d['number'], 'route_id' => $d['route_id'], 'aircraft_id' => $d['aircraft_id'], 'departure' => $departure->toDateTimeString(), 'arrival' => $departure->copy()->addMinutes($d['duration_minutes'])->toDateTimeString(), 'sale_price' => $d['sale_price'], 'gate' => $d['gate']]);
                $ids[] = $flight->id;
            }
        }
        abort_if(! $ids, 422, 'Khoảng ngày không chứa ngày trong tuần đã chọn.');

        return (object) ['flight_ids' => $ids, 'status' => 'DRAFT'];
    }

    private function submit_batch(User $u, array $d): object
    {
        $ids = array_unique(array_map('intval', explode(',', $d['flight_ids'])));
        sort($ids);
        foreach ($ids as $id) {
            $this->submit($u, ['flight_id' => $id]);
        }

        return (object) ['flight_ids' => $ids, 'status' => 'PENDING'];
    }

    private function publish_batch(User $u, array $d): object
    {
        $ids = array_unique(array_map('intval', explode(',', $d['flight_ids'])));
        sort($ids);
        $flights = $this->db()->table('flights')->whereIn('id', $ids)->get();
        abort_unless($flights->count() === count($ids), 404);
        foreach ($flights->pluck('origin')->unique()->sort() as $code) {
            $this->db()->table('airports')->where('code', $code)->lockForUpdate()->first();
        }
        foreach ($flights->pluck('aircraft_id')->unique()->sort() as $id) {
            $this->db()->table('aircraft')->where('id', $id)->lockForUpdate()->first();
        }
        foreach ($ids as $id) {
            $this->publish($u, ['flight_id' => $id]);
        }

        return (object) ['flight_ids' => $ids, 'status' => 'SCHEDULED'];
    }

    private function assign_crew(User $u, array $d): object
    {
        $f = $this->lockedFlight($u, $d['flight_id']);
        abort_unless(in_array($f->status, ['DRAFT', 'PENDING']), 409, 'Phân công chỉ được sửa trước công bố.');
        $crew = $this->db()->table('crew_members')->where('id', $d['crew_member_id'])->lockForUpdate()->first() ?? abort(422, 'Nhân sự không tồn tại.');
        $this->checkCrew($crew, $f);
        $this->db()->table('crew_assignments')->insertOrIgnore($d + ['assigned_by' => $u->id, 'created_at' => now()]);
        $this->audit($u, 'crew.assigned', $f->id, null, (string) $crew->id);

        return $f;
    }

    public function checkCrew(object $crew, object $f): void
    {
        $plane = $this->db()->table('aircraft')->find($f->aircraft_id);
        abort_unless($crew->available && $crew->qualification === $plane->type && Carbon::parse($crew->license_expires)->endOfDay()->gte(Carbon::parse($f->arrival)), 409, 'Năng định/giấy phép hoặc khả dụng tổ bay không hợp lệ.');
        abort_if(Carbon::parse($f->departure)->diffInHours(Carbon::parse($f->arrival)) > 12, 409, 'Vượt giới hạn nhiệm vụ 12 giờ.');
        $start = Carbon::parse($f->departure)->subHours(12);
        $end = Carbon::parse($f->arrival)->addHours(12);
        $conflict = $this->db()->table('crew_assignments as a')->join('flights as f', 'f.id', '=', 'a.flight_id')->where('a.active', true)->where('a.crew_member_id', $crew->id)->where('f.id', '!=', $f->id)->whereNotIn('f.status', ['DRAFT', 'PENDING', 'CANCELLED'])->where('f.departure', '<', $end)->where('f.arrival', '>', $start)->exists();
        abort_if($conflict, 409, 'Xung đột tổ bay hoặc chưa đủ 12 giờ nghỉ.');
    }

    private function submit(User $u, array $d): object
    {
        $f = $this->lockedFlight($u, $d['flight_id']);
        abort_unless($f->status === 'DRAFT', 409, 'Lịch không ở trạng thái nháp.');
        $this->db()->table('flights')->where('id', $f->id)->update(['status' => 'PENDING']);
        $this->audit($u, 'schedule.submitted', $f->id, 'DRAFT', 'PENDING');

        return $this->flight($f->id);
    }

    private function publish(User $u, array $d): object
    {
        // Aircraft is the serialization point shared by schedule approval and maintenance.
        $base = $this->flight($d['flight_id']);
        $this->db()->table('airports')->where('code', $base->origin)->lockForUpdate()->first();
        $plane = $this->db()->table('aircraft')->where('id', $base->aircraft_id)->lockForUpdate()->first();
        $f = $this->lockedFlight($u, $base->id);
        abort_unless($f->status === 'PENDING' && $f->created_by !== $u->id, 409, 'Cần lịch chờ duyệt và người phê duyệt độc lập.');
        abort_unless(now()->lt($f->departure) && $plane->status === 'AVAILABLE', 409, 'Chuyến đã qua giờ hoặc tàu bay chưa khả dụng.');
        abort_if($this->db()->table('change_reservations')->where('aircraft_id', $plane->id)->where('starts_at', '<', Carbon::parse($f->arrival)->addMinutes(30))->where('ends_at', '>', Carbon::parse($f->departure)->subMinutes(30))->exists(), 409, 'Tàu đang được giữ cho phương án đổi tàu.');
        $conflict = $this->db()->table('flights')->where('aircraft_id', $plane->id)->where('id', '!=', $f->id)->whereNotIn('status', ['DRAFT', 'PENDING', 'CANCELLED'])->where('departure', '<', Carbon::parse($f->arrival)->addMinutes(30))->where('arrival', '>', Carbon::parse($f->departure)->subMinutes(30))->exists();
        abort_if($conflict, 409, 'Xung đột tàu bay hoặc thời gian quay đầu 30 phút.');
        $crewIds = $this->db()->table('crew_assignments')->where('flight_id', $f->id)->where('active', true)->orderBy('crew_member_id')->pluck('crew_member_id');
        $crew = $this->db()->table('crew_members')->whereIn('id', $crewIds)->orderBy('id')->lockForUpdate()->get();
        abort_unless($crew->where('position', 'PILOT')->count() >= 1 && $crew->where('position', 'CABIN')->count() >= 1, 409, 'Cần tối thiểu một phi công và một tiếp viên.');
        foreach ($crew as $member) {
            $this->checkCrew($member, $f);
        }
        $gate = $this->db()->table('airport_resources')->where('airport_code', $f->origin)->where('code', $f->gate)->where('type', 'GATE')->where('active', true)->lockForUpdate()->first();
        abort_unless($gate, 409, 'Cần cửa khởi hành có trong danh mục và đang hoạt động.');
        $from = Carbon::parse($f->departure)->subMinutes(45);
        $to = Carbon::parse($f->departure)->addMinutes(15);
        abort_if($this->db()->table('resource_allocations')->where('resource_id', $gate->id)->where('flight_id', '!=', $f->id)->where('starts_at', '<', $to)->where('ends_at', '>', $from)->exists(), 409, 'Cửa khởi hành có lịch xung đột.');
        if (! $this->db()->table('resource_allocations')->where('resource_id', $gate->id)->where('flight_id', $f->id)->exists()) {
            $this->db()->table('resource_allocations')->insert(['flight_id' => $f->id, 'resource_id' => $gate->id, 'starts_at' => $from, 'ends_at' => $to, 'actor_id' => $u->id, 'reason' => 'Công bố lịch, giữ cửa -45/+15 phút', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->db()->table('flights')->where('id', $f->id)->update(['status' => 'SCHEDULED', 'approved_by' => $u->id, 'published_at' => now()]);
        $this->changed($f->id, $plane->capacity);
        $this->audit($u, 'schedule.published', $f->id, 'PENDING', 'SCHEDULED');

        return $this->flight($f->id);
    }

    private function changed(int $id, ?int $capacity = null): void
    {
        $f = $this->flight($id);
        $plane = $this->db()->table('aircraft')->find($f->aircraft_id);
        Events::emit('ops', 'FlightChanged', ['flight_id' => $id, 'status' => $f->status, 'version' => $f->version, 'capacity' => $capacity ?? $plane->capacity, 'price' => $f->sale_price, 'number' => $f->number, 'origin' => $f->origin, 'departure' => $f->departure]);
    }

    private function flight_state(User $u, array $d): object
    {
        $base = $this->flight($d['flight_id']);
        $plane = $this->db()->table('aircraft')->where('id', $base->aircraft_id)->lockForUpdate()->first();
        $f = $this->lockedFlight($u, $base->id);
        $allowed = ['SCHEDULED' => ['BOARDING'], 'DELAYED' => ['BOARDING'], 'BOARDING' => ['GATE_CLOSED'], 'GATE_CLOSED' => ['DEPARTED'], 'DEPARTED' => ['ARRIVED']];
        abort_unless(in_array($d['status'], $allowed[$f->status] ?? []), 409, 'Chuyển trạng thái chuyến không hợp lệ.');
        if ($d['status'] === 'BOARDING' && $f->created_by !== null) {
            abort_unless($f->gate !== 'TBD' && $this->db()->table('resource_allocations')->where('flight_id', $f->id)->where('starts_at', '<=', $f->departure)->where('ends_at', '>=', $f->departure)->exists(), 409, 'Cần điều phối tài nguyên hợp lệ trước boarding.');
        }
        if (in_array($d['status'], ['BOARDING', 'GATE_CLOSED', 'DEPARTED'])) {
            abort_unless($plane->status === 'AVAILABLE', 409, 'Tàu bay không khả dụng.');
        }
        if (in_array($d['status'], ['GATE_CLOSED', 'DEPARTED'])) {
            abort_if($this->db()->table('ground_tasks')->where('flight_id', $f->id)->where('mandatory', true)->where('status', '!=', 'COMPLETED')->exists(), 409, 'Còn nhiệm vụ mặt đất bắt buộc chưa hoàn tất.');
        }
        if ($d['status'] === 'DEPARTED') {
            abort_if(DB::table('bookings')->where('flight_id', $f->id)->where('status', 'CHECKED_IN')->exists(), 409, 'Còn hành khách check-in chưa boarding; cần xử lý no-show/offload.');
        }
        $update = ['status' => $d['status'], 'version' => $f->version + 1];
        if ($d['status'] === 'DEPARTED') {
            $update['actual_departure'] = now();
        }
        if ($d['status'] === 'ARRIVED') {
            $update['actual_arrival'] = now();
            $hours = max(0, Carbon::parse($f->actual_departure)->diffInSeconds(now()) / 3600);
            $this->db()->table('aircraft')->where('id', $plane->id)->update(['flight_hours' => $plane->flight_hours + $hours, 'cycles' => $plane->cycles + 1]);
        }
        $this->db()->table('flights')->where('id', $f->id)->update($update);
        $this->changed($f->id);
        $this->audit($u, 'flight.'.$d['status'], $f->id, $f->status, $d['reason']);

        return $this->flight($f->id);
    }

    private function work_order(User $u, array $d): object
    {
        $plane = $this->db()->table('aircraft')->where('id', $d['aircraft_id'])->lockForUpdate()->first() ?? abort(404);
        abort_if($this->db()->table('flights')->where('aircraft_id', $plane->id)->whereIn('status', ['BOARDING', 'GATE_CLOSED', 'DEPARTED'])->exists(), 409, 'Tàu bay đang trong chuyến khai thác; cần phương án điều hành trước bảo dưỡng.');
        abort_if($this->db()->table('change_reservations')->where('aircraft_id', $plane->id)->exists(), 409, 'Tàu đang được giữ cho phương án điều chỉnh; chưa thể mở bảo dưỡng.');
        $id = $this->db()->table('work_orders')->insertGetId($d + ['created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->db()->table('aircraft')->where('id', $plane->id)->update(['status' => 'MAINTENANCE']);
        $this->audit($u, 'maintenance.opened', $id, null, 'OPEN');

        return (object) ['id' => $id];
    }

    private function complete_work(User $u, array $d): object
    {
        $w = $this->db()->table('work_orders')->where('id', $d['work_order_id'])->lockForUpdate()->first() ?? abort(404);
        abort_unless($w->status === 'OPEN', 409, 'Lệnh bảo dưỡng không ở trạng thái mở.');
        $this->db()->table('work_orders')->where('id', $w->id)->update(['status' => 'COMPLETED', 'completed_by' => $u->id, 'evidence' => $d['evidence'], 'updated_at' => now()]);
        $this->audit($u, 'maintenance.completed', $w->id, 'OPEN', 'COMPLETED');

        return (object) ['id' => $w->id];
    }

    private function release_work(User $u, array $d): object
    {
        $base = $this->db()->table('work_orders')->find($d['work_order_id']) ?? abort(404);
        $this->db()->table('aircraft')->where('id', $base->aircraft_id)->lockForUpdate()->first();
        $w = $this->db()->table('work_orders')->where('id', $base->id)->lockForUpdate()->first();
        abort_unless($w->status === 'COMPLETED' && $w->completed_by !== $u->id && $w->created_by !== $u->id, 409, 'Cần hoàn tất công việc và kỹ thuật viên phê duyệt độc lập.');
        $this->db()->table('work_orders')->where('id', $w->id)->update(['status' => 'RELEASED', 'released_by' => $u->id, 'updated_at' => now()]);
        if (! $this->db()->table('work_orders')->where('aircraft_id', $w->aircraft_id)->where('status', '!=', 'RELEASED')->exists()) {
            $this->db()->table('aircraft')->where('id', $w->aircraft_id)->update(['status' => 'AVAILABLE']);
        }
        $this->audit($u, 'maintenance.released', $w->id, 'COMPLETED', 'RELEASED');

        return (object) ['id' => $w->id];
    }

    private function allocate(User $u, array $d): object
    {
        $base = $this->flight($d['flight_id']);
        $this->db()->table('airports')->where('code', $base->origin)->lockForUpdate()->first();
        $res = $this->db()->table('airport_resources')->where('id', $d['resource_id'])->lockForUpdate()->first() ?? abort(404);
        $f = $this->lockedFlight($u, $d['flight_id']);
        abort_unless($res->active && $res->airport_code === $f->origin && ! $f->pending_change_id && ! in_array($f->status, ['CANCELLED', 'DEPARTED', 'ARRIVED']), 409, 'Tài nguyên không phù hợp chuyến.');
        abort_unless(Carbon::parse($d['starts_at'])->lte(Carbon::parse($f->departure)) && Carbon::parse($d['ends_at'])->gte(Carbon::parse($f->departure)), 422, 'Phân bổ phải bao phủ giờ khởi hành.');
        abort_if($this->db()->table('resource_allocations')->where('resource_id', $res->id)->where('starts_at', '<', $d['ends_at'])->where('ends_at', '>', $d['starts_at'])->exists(), 409, 'Tài nguyên có lịch giao nhau.');
        $id = $this->db()->table('resource_allocations')->insertGetId($d + ['actor_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        if ($res->type === 'GATE') {
            $this->db()->table('flights')->where('id', $f->id)->update(['gate' => $res->code, 'version' => $f->version + 1]);
            $this->changed($f->id);
        }
        $this->audit($u, 'resource.allocated', $id, null, $d['reason']);

        return (object) ['id' => $id];
    }

    private function unallocate(User $u, array $d): object
    {
        $allocation = $this->db()->table('resource_allocations')->find($d['allocation_id']) ?? abort(404);
        $base = $this->flight($allocation->flight_id);
        $this->db()->table('airports')->where('code', $base->origin)->lockForUpdate()->first();
        $res = $this->db()->table('airport_resources')->where('id', $allocation->resource_id)->lockForUpdate()->first();
        $f = $this->lockedFlight($u, $allocation->flight_id);
        abort_if($f->pending_change_id || in_array($f->status, ['DEPARTED', 'ARRIVED']), 409, 'Chuyến đã khai thác.');
        $this->db()->table('resource_allocations')->where('id', $allocation->id)->delete();
        if ($res->type === 'GATE') {
            $this->db()->table('flights')->where('id', $f->id)->update(['gate' => 'TBD', 'version' => $f->version + 1]);
            $this->changed($f->id);
        }
        $this->audit($u, 'resource.unallocated', $allocation->id, 'ALLOCATED', $d['reason']);

        return (object) ['id' => $allocation->id];
    }

    private function ground_task(User $u, array $d): object
    {
        $f = $this->lockedFlight($u, $d['flight_id']);
        $assignee = User::find($d['assigned_to']);
        abort_unless($assignee && $assignee->role === 'ground_agent' && $assignee->airport_code === $f->origin, 422, 'Nhân viên mặt đất phải thuộc đúng sân bay.');
        abort_unless(Carbon::parse($d['due_at'])->lte(Carbon::parse($f->departure)), 422, 'SLA phải trước khởi hành.');
        $id = $this->db()->table('ground_tasks')->insertGetId($d + ['created_at' => now(), 'updated_at' => now()]);
        $this->audit($u, 'ground.assigned', $id, null, 'PENDING');

        return (object) ['id' => $id];
    }

    private function ground_state(User $u, array $d): object
    {
        $task = $this->db()->table('ground_tasks')->where('id', $d['task_id'])->lockForUpdate()->first() ?? abort(404);
        abort_unless($task->assigned_to === $u->id, 404);
        Access::airport($u, $this->flight($task->flight_id)->origin);
        abort_unless(($task->status === 'PENDING' && $d['status'] === 'IN_PROGRESS') || ($task->status === 'IN_PROGRESS' && $d['status'] === 'COMPLETED'), 409, 'Chuyển trạng thái nhiệm vụ không hợp lệ.');
        if ($d['status'] === 'COMPLETED') {
            abort_unless(strlen($d['evidence'] ?? '') >= 10, 422, 'Cần bằng chứng hoàn thành ít nhất 10 ký tự.');
        }
        $this->db()->table('ground_tasks')->where('id', $task->id)->update(['status' => $d['status'], 'evidence' => $d['evidence'] ?? null, 'completed_at' => $d['status'] === 'COMPLETED' ? now() : null, 'updated_at' => now()]);
        $this->audit($u, 'ground.'.$d['status'], $task->id, $task->status, $d['status']);

        return (object) ['id' => $task->id];
    }

    private function disruption(User $u, array $d): object
    {
        $f = $this->lockedFlight($u, $d['flight_id']);
        abort_if(in_array($f->status, ['DRAFT', 'PENDING', 'CANCELLED', 'DEPARTED', 'ARRIVED', 'CHANGE_PENDING']), 409, 'Chuyến không thể lập phương án gián đoạn.');
        if ($d['type'] === 'DELAY') {
            abort_unless($d['delay_minutes'] > 0, 422, 'Cần số phút trễ lớn hơn 0.');
        }
        $id = $this->db()->table('disruptions')->insertGetId($d + ['created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($u, 'disruption.proposed', $id, null, 'PENDING');

        return (object) ['id' => $id];
    }

    private function approve_disruption(User $u, array $d): object
    {
        $plan = $this->db()->table('disruptions')->find($d['disruption_id']) ?? abort(404);
        $base = $this->flight($plan->flight_id);
        $this->db()->table('aircraft')->where('id', $base->aircraft_id)->lockForUpdate()->first();
        $f = $this->lockedFlight($u, $base->id);
        $plan = $this->db()->table('disruptions')->where('id', $plan->id)->lockForUpdate()->first();
        abort_unless($plan->status === 'PENDING' && $plan->created_by !== $u->id, 409, 'Phương án cần người duyệt độc lập và chỉ áp dụng một lần.');
        abort_if(in_array($f->status, ['CANCELLED', 'DEPARTED', 'ARRIVED', 'CHANGE_PENDING']), 409, 'Chuyến không thể áp dụng phương án.');
        $update = ['version' => $f->version + 1, 'status' => $plan->type === 'CANCEL' ? 'CANCELLED' : 'DELAYED'];
        if ($plan->type === 'DELAY') {
            $update['departure'] = Carbon::parse($f->departure)->addMinutes($plan->delay_minutes);
            $update['arrival'] = Carbon::parse($f->arrival)->addMinutes($plan->delay_minutes);
            abort_if($this->db()->table('flights')->where('id', '!=', $f->id)->where('aircraft_id', $f->aircraft_id)->whereNotIn('status', ['DRAFT', 'PENDING', 'CANCELLED'])->where('departure', '<', $update['arrival']->copy()->addMinutes(30))->where('arrival', '>', $update['departure']->copy()->subMinutes(30))->exists(), 409, 'Phương án trễ gây xung đột tàu bay.');
            $candidate = (object) array_merge((array) $f, $update);
            $ids = $this->db()->table('crew_assignments')->where('flight_id', $f->id)->where('active', true)->orderBy('crew_member_id')->pluck('crew_member_id');
            foreach ($this->db()->table('crew_members')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get() as $crew) {
                $this->checkCrew($crew, $candidate);
            }
            abort_if($this->db()->table('resource_allocations')->where('flight_id', $f->id)->exists(), 409, 'Cần gỡ/điều phối lại tài nguyên trước khi đổi giờ; không tự bỏ qua xung đột.');
        } else {
            $this->db()->table('resource_allocations')->where('flight_id', $f->id)->delete();
        }
        $this->db()->table('flights')->where('id', $f->id)->update($update);
        $this->db()->table('disruptions')->where('id', $plan->id)->update(['status' => 'APPROVED', 'approved_by' => $u->id, 'updated_at' => now()]);
        $this->changed($f->id);
        $this->audit($u, 'disruption.approved', $plan->id, 'PENDING', $update['status']);

        return $this->flight($f->id);
    }
}
