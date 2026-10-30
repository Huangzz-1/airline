<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class WorkspacePresentation
{
    private array $maps = [];

    public function __construct(array $tables)
    {
        $rows = collect($tables)->flatMap(fn ($t) => $t['rows']->items());
        $ids = fn (array $fields) => $rows->flatMap(fn ($r) => array_map(fn ($k) => $r->$k ?? null, $fields))->filter()->unique()->values();
        $this->maps['flight_id'] = DB::connection('ops')->table('flights')->whereIn('id', $ids(['flight_id']))->get()->mapWithKeys(fn ($f) => [$f->id => Ui::flight($f)])->all();
        $this->maps['aircraft_id'] = DB::connection('ops')->table('aircraft')->whereIn('id', $ids(['aircraft_id']))->pluck('registration', 'id')->all();
        $this->maps['crew_member_id'] = DB::connection('ops')->table('crew_members')->whereIn('id', $ids(['crew_member_id']))->pluck('name', 'id')->all();
        $this->maps['resource_id'] = DB::connection('ops')->table('airport_resources')->whereIn('id', $ids(['resource_id']))->get()->mapWithKeys(fn ($r) => [$r->id => $r->airport_code.' · '.$r->code])->all();
        $bookings = DB::table('bookings')->whereIn('id', $ids(['booking_id', 'source_booking_id', 'target_booking_id']))->pluck('reference', 'id')->all();
        foreach (['booking_id', 'source_booking_id', 'target_booking_id'] as $field) {
            $this->maps[$field] = $bookings;
        }
        $users = DB::table('users')->whereIn('id', $ids(['created_by', 'approved_by', 'assigned_by', 'assigned_to', 'completed_by', 'released_by', 'requested_by', 'closed_by', 'actor_id']))->pluck('name', 'id')->all();
        foreach (['created_by', 'approved_by', 'assigned_by', 'assigned_to', 'completed_by', 'released_by', 'requested_by', 'closed_by', 'actor_id'] as $field) {
            $this->maps[$field] = $users;
        }
    }

    public function cell(string $key, $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (isset($this->maps[$key])) {
            return $this->maps[$key][$value] ?? ((string) $value === '0' ? 'Hệ thống' : '—');
        }
        if (in_array($key, ['active', 'available', 'mandatory', 'late', 'alert'])) {
            return $value ? 'Có' : 'Không';
        }
        if (in_array($key, ['departure', 'arrival', 'event_time', 'license_expires']) || str_ends_with($key, '_at')) {
            return Ui::date($value, $key === 'license_expires' ? 'd/m/Y' : 'H:i d/m/Y');
        }
        if (in_array($key, ['price', 'amount', 'source_amount', 'target_amount'])) {
            return Ui::money($value);
        }
        if ($key === 'detail' && is_array($detail = json_decode($value, true))) {
            return 'Đặt chỗ: '.Ui::label($detail['booking'] ?? null).' · Thanh toán: '.Ui::label($detail['payment'] ?? null).' · Giá vé: '.Ui::money($detail['booking_amount'] ?? 0).' · Đã ghi nhận: '.Ui::money($detail['payment_amount'] ?? 0).' · Vé: '.(! empty($detail['ticket']) ? 'Đã xuất' : 'Chưa xuất');
        }
        if ($key === 'after_data') {
            $data = json_decode($value, true);
            if (! is_array($data)) {
                return '—';
            }
            $labels = ['name' => 'Tên', 'city' => 'Thành phố', 'timezone' => 'Múi giờ', 'active' => 'Khả dụng', 'type' => 'Loại tàu', 'capacity' => 'Số ghế', 'qualification' => 'Năng định', 'license_expires' => 'Hạn giấy phép', 'available' => 'Khả dụng', 'duration_minutes' => 'Thời lượng', 'distance_km' => 'Khoảng cách'];

            return collect($data)->map(fn ($v, $k) => ($labels[$k] ?? $k).': '.(is_array($v) ? 'Danh sách cập nhật' : $v))->implode(' · ');
        }
        if ($key === 'id' && preg_match('/^[a-f0-9-]{36}$/i', (string) $value)) {
            return 'YC-'.strtoupper(substr($value, 0, 8));
        }
        if ($key === 'entity') {
            return ['airports' => 'Sân bay', 'routes' => 'Đường bay', 'airport_resources' => 'Tài nguyên', 'aircraft' => 'Tàu bay', 'crew_members' => 'Tổ bay'][$value] ?? $value;
        }

        return Ui::label((string) $value);
    }
}
