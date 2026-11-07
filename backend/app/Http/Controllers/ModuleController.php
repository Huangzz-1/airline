<?php

namespace App\Http\Controllers;

use App\Modules\Shared\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unified dashboards for the three module groups described by the activity
 * and sequence specification.  Domain writes still go through the existing
 * WorkspaceController and its services; this controller only composes the
 * read model used by the module screens.
 */
class ModuleController extends Controller
{
    private const ROLES = [
        'm1' => ['admin', 'data_admin', 'operations', 'ops_manager', 'fleet_manager', 'maintenance', 'crew_scheduler', 'ground_agent', 'integration_admin'],
        'm2' => ['admin', 'passenger', 'airport_agent', 'customer_service', 'operations'],
        'm3' => ['admin', 'analyst', 'data_admin', 'operations', 'ops_manager', 'maintenance', 'integration_admin'],
    ];

    private const ACTIONS = [
        'm1' => [
            'airport_update', 'airport_area', 'season_rule', 'aircraft_model',
            'crew_qualification', 'crew_availability', 'crew_roster',
            'maintenance_item', 'work_item', 'maintenance_material', 'work_material',
            'ground_service', 'ground_task_service', 'ground_equipment', 'ground_task_equipment',
            'ground_handoff', 'integration_connection', 'fare_rule_version',
            'actual_time', 'master_propose', 'master_approve',
        ],
        'm2' => [
            'bag_tag', 'bag_scan', 'baggage_incident', 'baggage_incident_resolve',
            'document_verify', 'assistance', 'ancillary', 'ancillary_decide',
            'support', 'support_followup', 'support_reply', 'change_seat',
        ],
        'm3' => ['device', 'telemetry', 'forecast', 'dataset', 'dataset_clean', 'model_run', 'model_train', 'model_evaluate', 'recommendation'],
    ];

    public function index(Request $request, string $module)
    {
        abort_unless(isset(self::ROLES[$module]), 404);
        Access::role($request->user(), ...self::ROLES[$module]);

        $data = match ($module) {
            'm1' => $this->core($request),
            'm2' => $this->passenger($request),
            'm3' => $this->aiIot($request),
        };
        $data['module'] = $module;
        $data['moduleTitle'] = [
            'm1' => 'Quản lý cốt lõi',
            'm2' => 'Hành khách, booking & hành lý',
            'm3' => 'Phân tích AI & thiết bị',
        ][$module];
        $data['allowedActions'] = self::ACTIONS[$module];

        return $request->expectsJson()
            ? response()->json($data)
            : view('modules.'.$module, $data);
    }

    public function action(Request $request, string $module, string $action)
    {
        abort_unless(isset(self::ACTIONS[$module]) && in_array($action, self::ACTIONS[$module], true), 404);
        Access::role($request->user(), ...self::ROLES[$module]);
        $request->headers->set('Accept', 'application/json');

        return app(WorkspaceController::class)->dispatch($request, $action);
    }

    private function core(Request $request): array
    {
        $ops = DB::connection('ops');
        $user = $request->user();
        $flightQuery = $ops->table('flights');
        if (in_array($user->role, Access::SCOPED, true) && $user->airport_code) {
            $flightQuery->where('origin', $user->airport_code);
        }

        $flights = (clone $flightQuery)->orderBy('departure')->limit(12)->get();
        $aircraft = $this->rows($ops, 'aircraft', ['id', 'registration', 'type', 'capacity', 'status'], 8);
        $crew = $this->rows($ops, 'crew_members', ['id', 'code', 'name', 'position', 'available', 'license_expires'], 8);
        $maintenance = $this->rows($ops, 'work_orders', ['id', 'aircraft_id', 'title', 'due_at', 'status'], 8, fn ($q) => $q->whereIn('status', ['OPEN', 'IN_PROGRESS'])->orderBy('due_at'));

        return [
            'kpis' => [
                ['label' => 'Sân bay', 'value' => $this->count($ops, 'airports'), 'tone' => 'cyan'],
                ['label' => 'Đường bay', 'value' => $this->count($ops, 'routes'), 'tone' => 'blue'],
                ['label' => 'Chuyến sắp khai thác', 'value' => $flights->whereIn('status', ['SCHEDULED', 'BOARDING', 'DELAYED'])->count(), 'tone' => 'green'],
                ['label' => 'Tàu bay', 'value' => $this->count($ops, 'aircraft'), 'tone' => 'amber'],
            ],
            'flights' => $flights,
            'aircraft' => $aircraft,
            'crew' => $crew,
            'maintenance' => $maintenance,
            'quickLinks' => [
                ['label' => 'Danh mục sân bay', 'url' => '/workspace/catalog'],
                ['label' => 'Lịch bay & khai thác', 'url' => '/workspace/scheduling'],
                ['label' => 'Đội tàu bay', 'url' => '/workspace/fleet'],
                ['label' => 'Tổ bay', 'url' => '/workspace/crew'],
                ['label' => 'Bảo dưỡng', 'url' => '/workspace/maintenance'],
                ['label' => 'Phân bổ nguồn lực', 'url' => '/workspace/resources'],
                ['label' => 'Gián đoạn khai thác', 'url' => '/workspace/disruptions'],
                ['label' => 'Giá vé & tồn ghế', 'url' => '/workspace/fare'],
            ],
        ];
    }

    private function passenger(Request $request): array
    {
        $db = DB::connection('mysql');
        $ops = DB::connection('ops');
        $user = $request->user();
        $bookingQuery = $db->table('bookings');
        if ($user->role === 'passenger') {
            $bookingQuery->where('user_id', $user->id);
        } elseif ($user->role === 'airport_agent' && $user->airport_code) {
            $flightIds = $ops->table('flights')->where('origin', $user->airport_code)->pluck('id');
            $bookingQuery->whereIn('flight_id', $flightIds->isEmpty() ? [-1] : $flightIds);
        }
        $scopeBookingIds = (clone $bookingQuery)->pluck('id');
        $bookings = (clone $bookingQuery)->latest('created_at')->limit(12)->get();
        $flightMap = $ops->table('flights')->whereIn('id', $bookings->pluck('flight_id')->unique()->values())->get()->keyBy('id');
        $bookings->each(function ($booking) use ($flightMap) {
            $booking->flight = $flightMap->get($booking->flight_id);
        });
        $reservationQuery = $db->table('reservations');
        if ($user->role === 'passenger') {
            $reservationQuery->where('user_id', $user->id);
        } elseif ($user->role === 'airport_agent') {
            $reservationQuery->whereIn('id', $scopeBookingIds->isEmpty() ? ['-'] : $db->table('bookings')->whereIn('id', $scopeBookingIds)->whereNotNull('reservation_id')->pluck('reservation_id'));
        }
        $reservations = $reservationQuery->latest()->limit(8)->get();
        $baggageQuery = $db->table('baggage');
        if ($user->role === 'passenger') {
            $ids = $bookings->pluck('id');
            $baggageQuery->whereIn('booking_id', $ids->isEmpty() ? ['-'] : $ids);
        } elseif ($user->role === 'airport_agent') {
            $baggageQuery->whereIn('booking_id', $scopeBookingIds->isEmpty() ? ['-'] : $scopeBookingIds);
        }

        return [
            'kpis' => [
                ['label' => 'Hồ sơ đặt chỗ', 'value' => $this->countQuery($reservationQuery), 'tone' => 'cyan'],
                ['label' => 'Booking đang xử lý', 'value' => (clone $bookingQuery)->whereIn('status', ['HELD', 'PAYMENT_PENDING'])->count(), 'tone' => 'amber'],
                ['label' => 'Vé đã xác nhận', 'value' => (clone $bookingQuery)->whereIn('status', ['CONFIRMED', 'CHECKED_IN', 'BOARDED'])->count(), 'tone' => 'green'],
                ['label' => 'Hành lý', 'value' => $this->countQuery($baggageQuery), 'tone' => 'blue'],
            ],
            'bookings' => $bookings,
            'reservations' => $reservations,
            'baggage' => $baggageQuery->latest()->limit(12)->get(),
            'quickLinks' => $user->role === 'passenger'
                ? [['label' => 'Tìm chuyến bay', 'url' => '/'], ['label' => 'Hành trình của tôi', 'url' => '/journeys'], ['label' => 'Hỗ trợ', 'url' => '/workspace/customer']]
                : [['label' => 'Phục vụ hành khách', 'url' => '/staff/passengers'], ['label' => 'Booking & hành lý', 'url' => '/workspace/baggage'], ['label' => 'Yêu cầu hỗ trợ', 'url' => '/workspace/customer']],
        ];
    }

    private function aiIot(Request $request): array
    {
        $ops = DB::connection('ops');
        $db = DB::connection('mysql');
        $devicesQuery = $ops->table('devices');
        $telemetryQuery = $ops->table('telemetry');
        if (in_array($request->user()->role, Access::SCOPED, true) && $request->user()->airport_code) {
            $deviceIds = (clone $devicesQuery)->where('airport_code', $request->user()->airport_code)->pluck('id');
            $devicesQuery->where('airport_code', $request->user()->airport_code);
            $telemetryQuery->whereIn('device_id', $deviceIds->isEmpty() ? [-1] : $deviceIds);
        }
        $deviceCount = (clone $devicesQuery)->count();
        $eventCount = (clone $telemetryQuery)->where('received_at', '>=', now()->subDay())->count();
        $alertCount = (clone $telemetryQuery)->where('alert', true)->count();
        $lateCount = (clone $telemetryQuery)->where('late', true)->count();
        $devices = (clone $devicesQuery)->orderByDesc('last_event_at')->limit(20)->get();
        $telemetry = (clone $telemetryQuery)->latest('received_at')->limit(20)->get();
        $datasets = $this->rows($db, 'analytics_datasets', ['id', 'code', 'name', 'source', 'status', 'created_at'], 8);
        $runs = $this->rows($db, 'analytics_model_runs', ['id', 'dataset_id', 'model_type', 'train_ratio', 'status', 'metrics', 'created_at'], 8);
        $recommendations = $this->rows($db, 'analytics_recommendations', ['id', 'model_run_id', 'target_type', 'target_id', 'recommendation', 'confidence', 'status'], 8);

        return [
            'kpis' => [
                ['label' => 'Thiết bị hoạt động', 'value' => $deviceCount, 'tone' => 'cyan'],
                ['label' => 'Sự kiện 24 giờ', 'value' => $eventCount, 'tone' => 'blue'],
                ['label' => 'Cảnh báo', 'value' => $alertCount, 'tone' => 'red'],
                ['label' => 'Đến trễ', 'value' => $lateCount, 'tone' => 'amber'],
            ],
            'devices' => $devices,
            'telemetry' => $telemetry,
            'datasets' => $datasets,
            'runs' => $runs,
            'recommendations' => $recommendations,
            'quickLinks' => [['label' => 'Workspace IoT', 'url' => '/workspace/iot'], ['label' => 'Phân tích khai thác', 'url' => '/workspace/analytics']],
        ];
    }

    private function count($connection, string $table): int
    {
        return Schema::connection($connection->getName())->hasTable($table) ? $connection->table($table)->count() : 0;
    }

    private function countQuery($query): int
    {
        try { return $query->count(); } catch (\Throwable) { return 0; }
    }

    private function rows($connection, string $table, array $columns, int $limit, ?callable $filter = null)
    {
        if (! Schema::connection($connection->getName())->hasTable($table)) return collect();
        $query = $connection->table($table);
        if ($filter) $filter($query);

        $order = Schema::connection($connection->getName())->hasColumn($table, 'created_at')
            ? 'created_at'
            : (Schema::connection($connection->getName())->hasColumn($table, 'last_event_at') ? 'last_event_at' : $columns[0]);

        return $query->orderByDesc($order)->limit($limit)->get($columns);
    }
}
