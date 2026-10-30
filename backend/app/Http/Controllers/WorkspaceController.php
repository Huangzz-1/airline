<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Analytics\AnalyticsService;
use App\Modules\Baggage\BaggageService;
use App\Modules\Boarding\PassengerChanges;
use App\Modules\Boarding\SeatMap;
use App\Modules\Booking\BookingChanges;
use App\Modules\Booking\CommerceRepository;
use App\Modules\Booking\ExchangeService;
use App\Modules\Customer\CustomerService;
use App\Modules\Finance\FinanceService;
use App\Modules\Finance\OutboxRelay;
use App\Modules\Flights\FlightCatalog;
use App\Modules\Operations\FlightChangeService;
use App\Modules\Operations\MasterDataService;
use App\Modules\Operations\MasterFileService;
use App\Modules\Operations\OperationsService;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use App\Support\WorkspaceForms;
use App\Support\WorkspacePresentation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WorkspaceController extends Controller
{
    public static function sections(): array
    {
        return [
            'catalog' => ['Danh mục sân bay & đường bay', ['data_admin']],
            'scheduling' => ['Lịch bay & điều hành', ['operations', 'ops_manager']],
            'fleet' => ['Đội tàu bay', ['fleet_manager']],
            'maintenance' => ['Bảo dưỡng', ['maintenance']],
            'recovery' => ['Đổi tàu & tổ bay', ['operations', 'ops_manager', 'crew_scheduler']],
            'crew' => ['Tổ bay', ['crew_scheduler']],
            'baggage' => ['Hành lý', ['airport_agent']],
            'ground' => ['Dịch vụ mặt đất', ['ground_agent', 'operations']],
            'customer' => ['Yêu cầu hỗ trợ', ['passenger', 'customer_service']],
            'finance' => ['Hoàn tiền & đối soát', ['finance']],
            'analytics' => ['Phân tích khai thác', ['analyst', 'operations', 'ops_manager']],
            'iot' => ['Thiết bị & cảnh báo', ['integration_admin']],
            'fare' => ['Giá vé & số chỗ', ['data_admin']],
            'resources' => ['Phân bổ tài nguyên', ['operations']],
            'disruptions' => ['Xử lý gián đoạn', ['operations', 'ops_manager']],
            'notifications' => ['Thông báo', Access::ROLES],
            'audit' => ['Nhật ký kiểm toán', ['admin', 'auditor']],
            'integrations' => ['Giám sát kết nối', ['admin', 'integration_admin']],
        ];
    }

    private function scopedFlights(User $u)
    {
        $q = DB::connection('ops')->table('flights');
        if (in_array($u->role, Access::SCOPED)) {
            $q->where('origin', $u->airport_code);
        }

        return $q;
    }

    public function index(Request $r, string $section = 'scheduling')
    {
        $meta = self::sections()[$section] ?? abort(404);
        $u = $r->user();
        Access::role($u, ...$meta[1]);
        $r->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $ops = DB::connection('ops');
        $tables = [];
        $forms = [];
        $kpis = [];
        // Form specifications are shared by Blade and the first-party REST workspace.
        $f = fn (string $name, string $label, string $type = 'text', array $options = []) => compact('name', 'label', 'type', 'options');
        $form = function (string $action, string $title, array $fields) use (&$forms) {
            $forms[] = compact('action', 'title', 'fields');
        };
        $dataset = function (string $title, $query, array $columns) use (&$tables, $r) {
            $searchable = array_intersect(array_keys($columns), ['number', 'registration', 'name', 'code', 'tag', 'title', 'subject', 'reference', 'reason', 'body']);
            if ($r->filled('q') && $searchable) {
                $query->where(function ($q) use ($searchable, $r) {
                    foreach ($searchable as $column) {
                        $q->orWhere($column, 'like', '%'.addcslashes($r->query('q'), '\\%_').'%');
                    }
                });
            }
            $page = count($tables) === 0 ? 'page' : 'page_'.(count($tables) + 1);
            $tables[] = ['title' => $title, 'rows' => $query->orderByDesc(array_key_first($columns))->paginate(12, ['*'], $page)->withQueryString(), 'columns' => $columns];
        };
        $airports = $section === 'catalog' ? $ops->table('airports')->get()->mapWithKeys(fn ($a) => [$a->code => $a->code.' · '.$a->name])->all() : [];
        $flights = in_array($section, ['scheduling', 'crew', 'ground', 'resources', 'disruptions', 'analytics', 'recovery']) ? $this->scopedFlights($u)->where('departure', '>', now()->subDays(2))->orderByDesc('id')->limit(100)->get()->mapWithKeys(fn ($x) => [$x->id => $x->number.' · '.$x->origin.'→'.$x->destination.' · '.$x->status])->all() : [];
        if ($section === 'catalog') {
            $dataset('Sân bay', $ops->table('airports'), ['code' => 'Mã', 'name' => 'Tên sân bay', 'city' => 'Thành phố', 'timezone' => 'Múi giờ']);
            $dataset('Đường bay', $ops->table('routes'), ['id' => 'ID', 'origin' => 'Đi', 'destination' => 'Đến', 'duration_minutes' => 'Phút chuẩn', 'distance_km' => 'Km', 'active' => 'Khả dụng']);
            $dataset('Tài nguyên sân bay', $ops->table('airport_resources'), ['id' => 'ID', 'airport_code' => 'Sân bay', 'code' => 'Mã', 'type' => 'Loại', 'active' => 'Khả dụng']);
            $form('airport', 'Thêm sân bay', [$f('code', 'Mã IATA (3 chữ hoa)'), $f('name', 'Tên sân bay'), $f('city', 'Thành phố'), $f('timezone', 'Múi giờ IANA')]);
            $form('route', 'Thêm đường bay', [$f('origin', 'Đi', 'select', $airports), $f('destination', 'Đến', 'select', $airports), $f('duration_minutes', 'Thời gian chuẩn (phút)', 'number'), $f('distance_km', 'Khoảng cách (km)', 'number')]);
            $form('resource', 'Thêm tài nguyên', [$f('airport_code', 'Sân bay', 'select', $airports), $f('code', 'Mã tài nguyên'), $f('type', 'Loại', 'select', ['TERMINAL' => 'Nhà ga', 'GATE' => 'Cửa', 'STAND' => 'Vị trí đỗ', 'COUNTER' => 'Quầy'])]);
        }
        if ($section === 'fleet') {
            $dataset('Hồ sơ đội bay', $ops->table('aircraft'), ['id' => 'ID', 'registration' => 'Số đăng ký', 'type' => 'Loại', 'capacity' => 'Ghế', 'status' => 'Trạng thái', 'flight_hours' => 'Giờ bay', 'cycles' => 'Chu kỳ']);
            $form('aircraft', 'Thêm tàu bay', [$f('registration', 'Số đăng ký'), $f('type', 'Loại/năng định (ví dụ A320)'), $f('capacity', 'Số ghế', 'number')]);
        }
        if ($section === 'scheduling') {
            $dataset('Lịch và trạng thái khai thác', $this->scopedFlights($u), ['id' => 'ID', 'number' => 'Số hiệu', 'origin' => 'Đi', 'destination' => 'Đến', 'departure' => 'Khởi hành UTC+7', 'arrival' => 'Đến UTC+7', 'aircraft_id' => 'Tàu bay', 'status' => 'Trạng thái', 'gate' => 'Cửa', 'version' => 'Phiên bản']);
            if ($u->role === 'operations') {
                $routes = $ops->table('routes')->where('origin', $u->airport_code)->where('active', true)->get()->mapWithKeys(fn ($x) => [$x->id => $x->origin.'→'.$x->destination])->all();
                $planes = $ops->table('aircraft')->where('status', 'AVAILABLE')->pluck('registration', 'id')->all();
                $form('schedule', 'Lập lịch nháp', [$f('number', 'Số hiệu (ví dụ AS201)'), $f('route_id', 'Đường bay', 'select', $routes), $f('aircraft_id', 'Tàu bay', 'select', $planes), $f('departure', 'Khởi hành (giờ Việt Nam)', 'datetime-local'), $f('arrival', 'Hạ cánh (giờ Việt Nam)', 'datetime-local'), $f('sale_price', 'Giá VND', 'number'), $f('gate', 'Cửa dự kiến')]);
                $form('season', 'Lập lịch lặp theo kỳ (mỗi đợt tối đa 31 ngày)', [$f('number', 'Số hiệu chuyến'), $f('route_id', 'Đường bay', 'select', $routes), $f('aircraft_id', 'Tàu bay', 'select', $planes), $f('from_date', 'Ngày bắt đầu', 'date'), $f('to_date', 'Ngày kết thúc', 'date'), $f('weekdays', 'Ngày trong tuần: 1=Thứ hai…7=Chủ nhật (ví dụ 1,3,5)'), $f('local_departure', 'Giờ địa phương tại sân bay đi', 'time'), $f('duration_minutes', 'Thời lượng phút', 'number'), $f('sale_price', 'Giá VND', 'number'), $f('gate', 'Cửa dự kiến')]);
                $form('submit_batch', 'Trình duyệt nhiều lịch', [$f('flight_ids', 'Danh sách ID cách nhau dấu phẩy (tối đa 31)')]);
                $form('submit', 'Trình duyệt lịch', [$f('flight_id', 'Chuyến nháp', 'select', $flights)]);
                $form('flight_state', 'Cập nhật khai thác', [$f('flight_id', 'Chuyến', 'select', $flights), $f('status', 'Trạng thái mới', 'select', ['BOARDING' => 'Boarding', 'GATE_CLOSED' => 'Đóng cửa', 'DEPARTED' => 'Khởi hành', 'ARRIVED' => 'Đã đến']), $f('reason', 'Lý do')]);
            } else {
                $form('publish', 'Phê duyệt và công bố', [$f('flight_id', 'Chuyến chờ duyệt', 'select', $flights)]);
            }
        }
        if ($section === 'scheduling' && $u->role === 'ops_manager') {
            $form('publish_batch', 'Duyệt và công bố cả đợt', [$f('flight_ids', 'Danh sách ID cách nhau dấu phẩy (tối đa 31)')]);
        }
        if ($section === 'crew') {
            $dataset('Năng định & giấy phép', $ops->table('crew_members'), ['id' => 'ID', 'code' => 'Mã', 'name' => 'Họ tên', 'position' => 'Vị trí', 'qualification' => 'Năng định', 'license_expires' => 'Giấy phép đến', 'available' => 'Khả dụng']);
            $dataset('Phân công tổ bay', $ops->table('crew_assignments'), ['id' => 'ID', 'flight_id' => 'Chuyến', 'crew_member_id' => 'Nhân sự', 'active' => 'Đang hiệu lực', 'assigned_by' => 'Người phân công']);
            $form('crew', 'Thêm hồ sơ tổ bay', [$f('code', 'Mã nhân sự'), $f('name', 'Họ tên'), $f('position', 'Vị trí', 'select', ['PILOT' => 'Phi công', 'CABIN' => 'Tiếp viên']), $f('qualification', 'Loại tàu bay (khớp hồ sơ tàu)'), $f('license_expires', 'Giấy phép hết hạn', 'date')]);
            $form('assign_crew', 'Phân công', [$f('flight_id', 'Chuyến', 'select', $flights), $f('crew_member_id', 'Nhân sự', 'select', $ops->table('crew_members')->pluck('name', 'id')->all())]);
        }
        if ($section === 'maintenance') {
            $dataset('Lệnh bảo dưỡng', $ops->table('work_orders'), ['id' => 'ID', 'aircraft_id' => 'Tàu bay', 'title' => 'Hạng mục', 'due_at' => 'Hạn UTC+7', 'status' => 'Trạng thái', 'completed_by' => 'Thực hiện', 'released_by' => 'Phê duyệt']);
            $form('work_order', 'Mở lệnh bảo dưỡng', [$f('aircraft_id', 'Tàu bay', 'select', $ops->table('aircraft')->pluck('registration', 'id')->all()), $f('title', 'Hạng mục'), $f('due_at', 'Hạn hoàn tất (giờ Việt Nam)', 'datetime-local')]);
            $work = $ops->table('work_orders')->pluck('title', 'id')->all();
            $form('complete_work', 'Ghi nhận hoàn tất', [$f('work_order_id', 'Lệnh', 'select', $work), $f('evidence', 'Kết quả/bằng chứng (ít nhất 10 ký tự)', 'textarea')]);
            $form('release_work', 'Giải phóng kỹ thuật — người duyệt độc lập', [$f('work_order_id', 'Lệnh hoàn tất', 'select', $work)]);
        }
        if ($section === 'resources') {
            $dataset('Lịch tài nguyên', $ops->table('resource_allocations')->whereIn('flight_id', array_keys($flights)), ['id' => 'ID', 'flight_id' => 'Chuyến', 'resource_id' => 'Tài nguyên', 'starts_at' => 'Từ UTC+7', 'ends_at' => 'Đến UTC+7', 'reason' => 'Lý do']);
            $form('allocate', 'Phân bổ — không cho phép giao thời gian', [$f('flight_id', 'Chuyến', 'select', $flights), $f('resource_id', 'Tài nguyên', 'select', $ops->table('airport_resources')->where('airport_code', $u->airport_code)->where('active', true)->pluck('code', 'id')->all()), $f('starts_at', 'Bắt đầu (giờ Việt Nam)', 'datetime-local'), $f('ends_at', 'Kết thúc (giờ Việt Nam)', 'datetime-local'), $f('reason', 'Lý do')]);
            $form('unallocate', 'Gỡ phân bổ trước điều phối lại', [$f('allocation_id', 'ID phân bổ', 'number'), $f('reason', 'Lý do')]);
        }
        if ($section === 'ground') {
            $q = $ops->table('ground_tasks');
            if ($u->role === 'ground_agent') {
                $q->where('assigned_to', $u->id);
            } else {
                $q->whereIn('flight_id', array_keys($flights));
            }
            $dataset('Nhiệm vụ & SLA', $q, ['id' => 'ID', 'flight_id' => 'Chuyến', 'title' => 'Nhiệm vụ', 'assigned_to' => 'Nhân viên', 'due_at' => 'SLA UTC+7', 'status' => 'Trạng thái', 'evidence' => 'Bằng chứng']);
            if ($u->role === 'operations') {
                $form('ground_task', 'Giao nhiệm vụ', [$f('flight_id', 'Chuyến', 'select', $flights), $f('title', 'Nhiệm vụ'), $f('assigned_to', 'Nhân viên', 'select', User::where('role', 'ground_agent')->where('airport_code', $u->airport_code)->pluck('name', 'id')->all()), $f('due_at', 'SLA (giờ Việt Nam)', 'datetime-local'), $f('mandatory', 'Bắt buộc', 'select', [1 => 'Có', 0 => 'Không'])]);
            } else {
                $form('ground_state', 'Cập nhật nhiệm vụ của tôi', [$f('task_id', 'ID nhiệm vụ', 'number'), $f('status', 'Trạng thái', 'select', ['IN_PROGRESS' => 'Bắt đầu', 'COMPLETED' => 'Hoàn tất']), $f('evidence', 'Bằng chứng / ghi chú', 'textarea-optional')]);
            }
        }
        if ($section === 'disruptions') {
            $dataset('Phương án xử lý gián đoạn', $ops->table('disruptions')->whereIn('flight_id', array_keys($flights)), ['id' => 'ID', 'flight_id' => 'Chuyến', 'type' => 'Loại', 'reason' => 'Nguyên nhân', 'delay_minutes' => 'Phút trễ', 'status' => 'Phê duyệt', 'created_by' => 'Người lập', 'approved_by' => 'Người duyệt']);
            if ($u->role === 'operations') {
                $form('disruption', 'Lập phương án', [$f('flight_id', 'Chuyến', 'select', $flights), $f('type', 'Loại', 'select', ['DELAY' => 'Trễ chuyến', 'CANCEL' => 'Hủy chuyến']), $f('delay_minutes', 'Số phút trễ (hủy nhập 0)', 'number'), $f('reason', 'Lý do')]);
            } else {
                $form('approve_disruption', 'Phê duyệt — đánh giá tác động trước khi xác nhận', [$f('disruption_id', 'ID phương án', 'number')]);
            }
        }
        if ($section === 'recovery') {
            $q = $ops->table('flight_changes')->whereIn('flight_id', array_keys($flights));
            $dataset('Phương án đổi nguồn lực', $q, ['id' => 'Mã phương án', 'flight_id' => 'Chuyến', 'kind' => 'Loại', 'status' => 'Tiến độ', 'reason' => 'Lý do', 'failure_reason' => 'Kết quả kiểm tra', 'created_by' => 'Người lập', 'approved_by' => 'Người duyệt']);
            if ($u->role === 'operations') {
                $form('resource_propose', 'Đề nghị đổi tàu bay', [$f('kind', '', 'hidden', ['value' => 'AIRCRAFT']), $f('flight_id', 'Chuyến', 'select', $flights), $f('target_aircraft_id', 'Tàu mới', 'select', $ops->table('aircraft')->where('status', 'AVAILABLE')->get()->mapWithKeys(fn ($a) => [$a->id => $a->registration.' · '.$a->type.' · '.$a->capacity.' ghế'])->all()), $f('reason', 'Lý do đổi tàu')]);
            }
            if ($u->role === 'crew_scheduler') {
                $dataset('Nhân sự và năng định', $ops->table('crew_members'), ['id' => 'ID nhân sự', 'name' => 'Họ tên', 'position' => 'Vị trí', 'qualification' => 'Năng định', 'license_expires' => 'Hạn giấy phép', 'available' => 'Khả dụng']);
                $form('resource_propose', 'Đề nghị thay tổ bay', [$f('kind', '', 'hidden', ['value' => 'CREW']), $f('flight_id', 'Chuyến', 'select', $flights), $f('crew_ids', 'Toàn bộ ID tổ bay mới, cách dấu phẩy'), $f('reason', 'Lý do thay thế')]);
            }
            if ($u->role === 'ops_manager') {
                $form('resource_decide', 'Phê duyệt đổi nguồn lực', [$f('change_id', 'Phương án', 'select', $ops->table('flight_changes')->whereIn('flight_id', array_keys($flights))->where('status', 'PENDING')->get()->mapWithKeys(fn ($p) => [$p->id => $p->kind.' · chuyến '.$p->flight_id.' · '.$p->reason])->all()), $f('approve', 'Quyết định', 'select', [1 => 'Chấp thuận', 0 => 'Từ chối'])]);
            }
        }
        if ($section === 'baggage') {
            $form('offload', 'Ghi nhận no-show và dỡ hành lý', [$f('booking_id', 'Booking UUID'), $f('reason', 'Bằng chứng / lý do xác nhận dỡ hành lý', 'textarea')]);
            $ids = $ops->table('flights')->where('origin', $u->airport_code)->orWhere('destination', $u->airport_code)->pluck('id');
            $bookings = DB::table('bookings')->whereIn('flight_id', $ids)->pluck('id');
            $dataset('Hành lý thuộc sân bay', $q = DB::table('baggage')->whereIn('booking_id', $bookings), ['id' => 'ID', 'tag' => 'Bag Tag', 'booking_id' => 'Booking', 'weight' => 'Kg', 'status' => 'Trạng thái', 'custody' => 'Đang giữ']);
            $form('bag_tag', 'Tạo thẻ hành lý', [$f('booking_id', 'Booking UUID'), $f('weight', 'Trọng lượng kg', 'number'), $f('request_id', 'request_id', 'uuid')]);
            $form('bag_scan', 'Quét và bàn giao', [$f('event_id', 'event_id', 'uuid'), $f('tag', 'Bag Tag'), $f('flight_id', 'ID chuyến', 'number'), $f('status', 'Mốc', 'select', array_combine(['ACCEPTED', 'SCREENED', 'LOADED', 'TRANSFERRED', 'DELIVERED', 'MISHANDLED', 'OFFLOADED'], ['Tiếp nhận', 'Soi chiếu', 'Xếp tàu', 'Chuyển tiếp', 'Giao trả', 'Bất thường', 'Dỡ khỏi tàu'])), $f('location', 'Điểm quét'), $f('custody', 'Đơn vị nhận bàn giao')]);
        }
        if ($section === 'customer') {
            $q = DB::table('support_cases');
            if ($u->role === 'passenger') {
                $q->where('user_id', $u->id);
            }
            $dataset('Yêu cầu & lịch sử phản hồi', $q, ['id' => 'ID', 'type' => 'Loại', 'subject' => 'Nội dung', 'status' => 'Trạng thái', 'response' => 'Phản hồi', 'updated_at' => 'Cập nhật UTC+7']);
            if ($u->role === 'passenger') {
                $form('support', 'Gửi yêu cầu', [$f('booking_id', 'Booking UUID (không bắt buộc)', 'text-optional'), $f('type', 'Loại', 'select', ['ASSISTANCE' => 'Hỗ trợ đặc biệt', 'COMPLAINT' => 'Khiếu nại', 'BAGGAGE' => 'Hành lý']), $f('subject', 'Chủ đề'), $f('message', 'Nội dung', 'textarea')]);
            } else {
                $form('support_reply', 'Xử lý hồ sơ', [$f('case_id', 'ID yêu cầu', 'number'), $f('status', 'Trạng thái', 'select', ['IN_PROGRESS' => 'Đang xử lý', 'RESOLVED' => 'Đã giải quyết']), $f('response', 'Phản hồi hành khách', 'textarea')]);
            }
        }
        if ($section === 'finance') {
            $db = DB::connection('finance');
            $quotes = DB::table('exchange_requests as e')->join('bookings as s', 's.id', '=', 'e.source_booking_id')->join('bookings as t', 't.id', '=', 'e.target_booking_id')->select('e.*', 's.amount as source_amount', 't.amount as target_amount');
            $dataset('Yêu cầu đổi vé đã thanh toán', DB::query()->fromSub($quotes, 'exchange_quotes'), ['id' => 'ID yêu cầu', 'source_booking_id' => 'Vé cũ', 'target_booking_id' => 'Giữ chỗ mới', 'source_amount' => 'Sẽ hoàn VND', 'target_amount' => 'Sẽ thu VND', 'status' => 'Trạng thái', 'reason' => 'Lý do', 'approved_by' => 'Người duyệt']);
            $form('exchange_decide', 'Duyệt phương án đổi vé', [$f('exchange_id', 'ID yêu cầu'), $f('approve', 'Quyết định', 'select', [1 => 'Chấp thuận', 0 => 'Từ chối']), $f('scenario', 'Kết quả mua vé mới sandbox', 'select', ['approve' => 'Thành công', 'decline' => 'Bị từ chối'])]);
            $dataset('Yêu cầu hoàn tiền', $db->table('refunds'), ['id' => 'ID', 'booking_id' => 'Booking', 'amount' => 'VND', 'status' => 'Trạng thái', 'reason' => 'Lý do', 'requested_by' => 'Người yêu cầu', 'approved_by' => 'Người duyệt']);
            $dataset('Sổ giao dịch', $db->table('ledger_entries'), ['id' => 'ID', 'booking_id' => 'Booking', 'kind' => 'Loại', 'amount' => 'VND', 'created_at' => 'Thời gian UTC+7']);
            $dataset('Kỳ đối soát', $db->table('reconciliation_runs'), ['id' => 'ID', 'period_start' => 'Từ (UTC)', 'period_end' => 'Đến (UTC)', 'status' => 'Trạng thái', 'created_by' => 'Người tạo', 'closed_by' => 'Người khóa']);
            $dataset('Kết quả và sai lệch', $db->table('reconciliation_entries'), ['id' => 'ID', 'run_id' => 'Kỳ', 'booking_id' => 'Booking', 'result' => 'Kết quả', 'detail' => 'Căn cứ', 'resolution' => 'Quyết định']);
            $form('refund_decide', 'Phê duyệt hoàn tiền', [$f('refund_id', 'ID yêu cầu', 'number'), $f('approve', 'Quyết định', 'select', [1 => 'Đồng ý hoàn', 0 => 'Từ chối'])]);
            $form('reconcile', 'Tạo kỳ đối soát', [$f('start', 'Từ ngày (UTC)', 'date'), $f('end', 'Đến ngày (UTC)', 'date')]);
            $form('resolve', 'Ghi nhận xử lý sai lệch / chấp nhận ngoại lệ', [$f('entry_id', 'ID sai lệch', 'number'), $f('note', 'Căn cứ xử lý (ít nhất 10 ký tự)', 'textarea')]);
            $form('close_period', 'Khóa kỳ — người duyệt độc lập', [$f('run_id', 'ID kỳ', 'number')]);
        }
        if ($section === 'fare') {
            $dataset('Hạng giá / tồn ghế', $q = DB::table('inventory'), ['flight_id' => 'Chuyến', 'fare_class' => 'Hạng', 'price' => 'VND', 'capacity' => 'Số chỗ', 'status' => 'Mở bán', 'source_version' => 'Phiên bản nguồn']);
            $dataset('Hạng giá theo chuyến', DB::table('fare_classes'), ['id' => 'ID', 'flight_id' => 'Chuyến', 'code' => 'Mã hạng', 'name' => 'Tên hạng', 'quota' => 'Hạn mức', 'price' => 'VND', 'active' => 'Đang mở']);
            $form('fare_class', 'Thiết lập hạng giá', [$f('flight_id', 'ID chuyến', 'number'), $f('code', 'Mã hạng (chữ hoa)'), $f('name', 'Tên hạng'), $f('quota', 'Hạn mức chỗ trong hạng', 'number'), $f('price', 'Giá VND', 'number'), $f('active', 'Trạng thái', 'select', [1 => 'Mở', 0 => 'Đóng'])]);
            $form('fare', 'Điều chỉnh giá / đóng mở bán', [$f('flight_id', 'ID chuyến', 'number'), $f('price', 'Giá VND', 'number'), $f('capacity', 'Tồn tối đa (không vượt tàu bay)', 'number'), $f('status', 'Trạng thái', 'select', ['OPEN' => 'Mở bán', 'CLOSED' => 'Đóng bán']), $f('reason', 'Lý do')]);
        }
        if ($section === 'iot') {
            $devices = $ops->table('devices')->where('airport_code', $u->airport_code);
            $dataset('Danh sách thiết bị', clone $devices, ['id' => 'ID', 'code' => 'Mã', 'threshold' => 'Ngưỡng cảnh báo', 'last_sequence' => 'Sequence mới nhất', 'latest_value' => 'Giá trị', 'last_event_at' => 'Event time UTC+7']);
            $dataset('Telemetry / sự kiện đến trễ', $ops->table('telemetry')->whereIn('device_id', $devices->pluck('id')), ['id' => 'Event ID', 'device_id' => 'Thiết bị', 'sequence' => 'Sequence', 'value' => 'Giá trị', 'event_time' => 'Event time UTC+7', 'received_at' => 'Nhận UTC+7', 'late' => 'Đến trễ', 'alert' => 'Cảnh báo']);
            $form('device', 'Đăng ký thiết bị', [$f('code', 'Mã thiết bị'), $f('airport_code', 'Sân bay', 'select', [$u->airport_code => $u->airport_code]), $f('threshold', 'Ngưỡng', 'number')]);
            $form('telemetry', 'Ghi nhận số liệu thiết bị', [$f('event_id', 'Event ID', 'uuid'), $f('device_id', 'Thiết bị', 'select', $ops->table('devices')->where('airport_code', $u->airport_code)->pluck('code', 'id')->all()), $f('sequence', 'Sequence', 'number'), $f('value', 'Giá trị', 'number'), $f('event_time', 'Event time (giờ Việt Nam)', 'datetime-local')]);
        }
        if ($section === 'analytics') {
            $source = $this->scopedFlights($u)->where('departure', '>=', now()->subDays(30))->get();
            $flightIds = $source->pluck('id');
            $actual = $source->filter(fn ($x) => $x->actual_departure !== null);
            $onTime = $actual->filter(fn ($x) => Carbon::parse($x->actual_departure)->lte(Carbon::parse($x->departure)->addMinutes(15)))->count();
            $kpis = ['Chuyến trong 30 ngày gần nhất & kế hoạch' => $source->count(), 'Đúng giờ (chỉ chuyến có giờ thực tế)' => $actual->count() ? round(100 * $onTime / $actual->count(), 1).'%' : 'Chưa đủ dữ liệu', 'Chuyến hủy' => $source->where('status', 'CANCELLED')->count(), 'Nhiệm vụ quá SLA' => $ops->table('ground_tasks')->whereIn('flight_id', $flightIds)->where('status', '!=', 'COMPLETED')->where('due_at', '<', now())->count(), 'Hành khách đã boarding' => DB::table('bookings')->whereIn('flight_id', $flightIds)->where('status', 'BOARDED')->count(), 'Hành lý bất thường' => DB::table('baggage')->whereIn('booking_id', DB::table('bookings')->whereIn('flight_id', $flightIds)->pluck('id'))->where('status', 'MISHANDLED')->count()];
            $dataset('Chuyến và nguồn tính KPI', $this->scopedFlights($u), ['id' => 'ID', 'number' => 'Chuyến', 'status' => 'Trạng thái', 'departure' => 'Dự kiến UTC+7', 'actual_departure' => 'Thực tế UTC+7']);
            $form('forecast', 'Kiểm tra cảnh báo vận hành', [$f('flight_id', 'Chuyến', 'select', $flights)]);
        }
        if ($section === 'notifications') {
            $dataset('Hộp thư cá nhân', DB::table('notifications')->where('user_id', $u->id), ['id' => 'ID', 'title' => 'Tiêu đề', 'body' => 'Nội dung', 'created_at' => 'Gửi UTC+7', 'read_at' => 'Đã đọc UTC+7']);
            $form('read_notice', 'Đánh dấu đã đọc', [$f('notification_id', 'ID thông báo')]);
        }
        if ($section === 'audit') {
            foreach (['ops' => 'Khai thác', 'mysql' => 'Khách hàng', 'finance' => 'Tài chính'] as $node => $label) {
                $dataset($label, DB::connection($node)->table('audit_logs'), ['id' => 'ID', 'actor_id' => 'Người thực hiện', 'action' => 'Hành động', 'object_id' => 'Đối tượng', 'before_state' => 'Trước', 'after_state' => 'Sau', 'correlation_id' => 'Tương quan', 'created_at' => 'Thời gian UTC+7']);
            }
        }
        if ($section === 'integrations') {
            foreach (['ops' => 'Khai thác → Đặt chỗ', 'mysql' => 'Đặt chỗ → Tài chính', 'finance' => 'Tài chính → Đặt chỗ'] as $node => $label) {
                $dataset($label, DB::connection($node)->table('outbox'), ['id' => 'Event ID', 'type' => 'Loại', 'attempts' => 'Số lần thử', 'available_at' => 'Thử lại UTC+7', 'delivered_at' => 'Đã giao UTC+7']);
            }
        }
        if (in_array($section, ['catalog', 'fleet', 'crew'])) {
            $entities = $section === 'catalog' ? ['airports' => 'Sân bay', 'routes' => 'Đường bay', 'airport_resources' => 'Tài nguyên'] : ($section === 'fleet' ? ['aircraft' => 'Tàu bay'] : ['crew_members' => 'Tổ bay']);
            $dataset('Phiên bản dữ liệu / chờ duyệt', $ops->table('master_changes')->whereIn('entity', array_keys($entities)), ['id' => 'ID', 'entity' => 'Danh mục', 'record_id' => 'Bản ghi', 'status' => 'Trạng thái', 'reason' => 'Lý do', 'after_data' => 'Thay đổi', 'created_by' => 'Người lập', 'approved_by' => 'Người duyệt']);
            foreach ($entities as $entity => $label) {
                $fields = [$f('entity', 'Danh mục', 'hidden', ['value' => $entity]), $f('record_id', 'ID / mã cần sửa')];
                $fields = array_merge($fields, match ($entity) {
                    'airports' => [$f('changes[name]', 'Tên sân bay'), $f('changes[city]', 'Thành phố'), $f('changes[timezone]', 'Múi giờ IANA')],
                    'routes' => [$f('changes[active]', 'Khả dụng', 'select', [1 => 'Hoạt động', 0 => 'Ngừng sử dụng']), $f('changes[duration_minutes]', 'Thời gian chuẩn (phút)', 'number'), $f('changes[distance_km]', 'Khoảng cách km', 'number')],
                    'airport_resources' => [$f('changes[active]', 'Khả dụng', 'select', [1 => 'Hoạt động', 0 => 'Ngừng sử dụng'])],
                    'aircraft' => [$f('changes[type]', 'Loại tàu'), $f('changes[capacity]', 'Số ghế', 'number')],
                    'crew_members' => [$f('changes[qualification]', 'Năng định'), $f('changes[license_expires]', 'Hết hạn giấy phép', 'date'), $f('changes[available]', 'Khả dụng', 'select', [1 => 'Sẵn sàng', 0 => 'Nghỉ/không khả dụng'])],
                });
                $fields[] = $f('reason', 'Lý do sửa đổi');
                $form('master_propose', 'Đề nghị sửa '.$label, $fields);
            }
            $form('master_approve', 'Duyệt phiên bản dữ liệu — độc lập', [$f('change_id', 'ID đề nghị', 'number')]);
        }
        if ($r->expectsJson()) {
            return response()->json(['section' => $section, 'title' => $meta[0], 'tables' => $tables, 'kpis' => $kpis]);
        }
        if ($section === 'notifications') {
            return view('notifications', ['notices' => $tables[0]['rows']]);
        }
        $forms = app(WorkspaceForms::class)->prepare($forms, $u);
        $presentation = new WorkspacePresentation($tables);
        if (! in_array($section, ['audit', 'integrations'])) {
            foreach ($tables as &$table) {
                $table['columns'] = array_diff_key($table['columns'], array_flip(['id', 'version', 'source_version', 'correlation_id']));
            }
            unset($table);
        }

        $transfers = $section === 'finance' ? DB::connection('finance')->table('checkout_intents')->where('method', 'BANK')->whereIn('status', ['PENDING', 'REPORTED'])->orderBy('created_at')->get() : collect();
        $payouts = $section === 'finance' ? DB::connection('finance')->table('refunds')->whereNotNull('payout_details')->whereIn('status', ['REQUESTED', 'APPROVED'])->get() : collect();
        foreach ($payouts as $payout) {
            $payout->account = json_decode(Crypt::decryptString($payout->payout_details), true);
        }

        return view('workspace', ['section' => $section, 'title' => $meta[0], 'tables' => $tables, 'forms' => $forms, 'kpis' => $kpis, 'sections' => self::sections(), 'presentation' => $presentation, 'transfers' => $transfers, 'payouts' => $payouts]);
    }

    public function dispatch(Request $r, string $action)
    {
        $u = $r->user();
        $d = $r->all();
        if (! $r->expectsJson()) {
            foreach (['crew_ids', 'flight_ids'] as $key) {
                if (isset($d[$key]) && is_array($d[$key])) {
                    Validator::make([$key => $d[$key]], [$key => 'required|array|min:1|max:31', $key.'.*' => 'required|integer|min:1|distinct'])->validate();
                    $d[$key] = implode(',', $d[$key]);
                }
            }
        }
        foreach ($d as $key => $value) {
            abort_if(! is_scalar($value) && $value !== null && ! ($action === 'master_propose' && $key === 'changes' && is_array($value)), 422, 'Dữ liệu không hợp lệ.');
        }
        if ($action === 'master_propose' && isset($d['changes_json'])) {
            try {
                $d['changes'] = json_decode($d['changes_json'], true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                abort(422, 'JSON thay đổi không hợp lệ.');
            }
        }
        foreach (['departure', 'arrival', 'due_at', 'starts_at', 'ends_at', 'event_time'] as $key) {
            if (! $r->expectsJson() && ! empty($d[$key]) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $d[$key])) {
                $d[$key] = Carbon::parse($d[$key], 'Asia/Ho_Chi_Minh')->utc()->toIso8601String();
            }
        }
        $result = match ($action) {
            'exchange_request' => app(ExchangeService::class)->request($u, $d),
            'exchange_decide' => app(ExchangeService::class)->decide($u, $d),
            'change_flight' => app(BookingChanges::class)->change($u, $d),
            'fare_class' => $this->fareClass($u, $d),
            'master_propose' => app(MasterDataService::class)->propose($u, $d),
            'master_approve' => app(MasterDataService::class)->approve($u, (int) ($d['change_id'] ?? 0)),
            'change_seat' => app(PassengerChanges::class)->seat($u, $d),
            'offload' => app(PassengerChanges::class)->offload($u, $d),
            'bag_tag' => app(BaggageService::class)->tag($u, $d),
            'bag_scan' => app(BaggageService::class)->scan($u, $d),
            'resource_propose' => app(FlightChangeService::class)->propose($u, $d),
            'resource_decide' => app(FlightChangeService::class)->decide($u, $d),
            'support_followup' => app(CustomerService::class)->followup($u, (int) ($d['case_id'] ?? 0), $d['message'] ?? ''),
            'support' => app(CustomerService::class)->create($u, $d),
            'support_reply' => app(CustomerService::class)->respond($u, (int) ($d['case_id'] ?? 0), $d),
            'device' => app(AnalyticsService::class)->device($u, $d),
            'telemetry' => app(AnalyticsService::class)->telemetry($u, $d),
            'forecast' => app(AnalyticsService::class)->forecast($u, (int) ($d['flight_id'] ?? 0)),
            'refund_request' => app(FinanceService::class)->requestRefund($u, $d['booking_id'] ?? '', $d['reason'] ?? ''),
            'refund_decide' => $this->refundDecide($u, $d),
            'reconcile' => app(FinanceService::class)->reconcile($u, $d['start'] ?? '', $d['end'] ?? ''),
            'resolve' => app(FinanceService::class)->resolve($u, (int) ($d['entry_id'] ?? 0), $d['note'] ?? ''),
            'close_period' => app(FinanceService::class)->close($u, (int) ($d['run_id'] ?? 0)),
            'read_notice' => $this->readNotice($u, $d),
            'fare' => $this->fare($u, $d),
            default => app(OperationsService::class)->execute($u, $action, $d),
        };
        if (in_array($action, ['publish', 'publish_batch', 'flight_state', 'allocate', 'approve_disruption', 'refund_request', 'refund_decide', 'exchange_decide', 'resource_decide'])) {
            app(OutboxRelay::class)->drain();
        }

        return $r->expectsJson() ? response()->json($result) : back()->with('message', 'Đã ghi nhận thao tác.')->with('result', $action === 'forecast' ? $result : null);
    }

    private function refundDecide(User $u, array $d): object
    {
        Validator::make($d, ['refund_id' => 'required|integer', 'approve' => 'required|boolean'])->validate();

        return app(FinanceService::class)->decide($u, (int) $d['refund_id'], (bool) $d['approve']);
    }

    private function readNotice(User $u, array $d): object
    {
        Validator::make($d, ['notification_id' => 'required|uuid'])->validate();
        abort_unless(DB::table('notifications')->where('id', $d['notification_id'])->where('user_id', $u->id)->exists(), 404);
        DB::table('notifications')->where('id', $d['notification_id'])->update(['read_at' => now()]);

        return (object) ['status' => 'READ'];
    }

    private function fare(User $u, array $d): object
    {
        Access::role($u, 'data_admin');
        $d = Validator::make($d, ['flight_id' => 'required|integer', 'price' => 'required|integer|between:1000,100000000', 'capacity' => 'required|integer|between:1,600', 'status' => 'required|in:OPEN,CLOSED', 'reason' => 'required|string|max:255'])->validate();
        $f = app(FlightCatalog::class)->get($d['flight_id']);
        $plane = DB::connection('ops')->table('aircraft')->find($f->aircraft_id);
        abort_unless($d['capacity'] <= $plane->capacity && in_array($f->status, ['SCHEDULED', 'BOARDING', 'DELAYED']), 409, 'Tồn vượt cấu hình tàu hoặc chuyến không bán được.');

        return DB::transaction(function () use ($u, $d) {
            $repo = app(CommerceRepository::class);
            $stock = $repo->lockInventory($d['flight_id']);
            $bookings = DB::table('bookings')->where('flight_id', $d['flight_id'])->whereIn('status', ['HELD', 'PAYMENT_PENDING', 'CONFIRMED', 'CHECKED_IN', 'BOARDED', 'REFUND_PENDING', 'EXCHANGE_PENDING'])->get();
            abort_if($bookings->count() > $d['capacity'], 409, 'Không được giảm tồn dưới số chỗ đã giữ/bán.');
            foreach ($bookings as $b) {
                if ($b->seat) {
                    abort_unless(SeatMap::contains($b->seat, (int) $d['capacity']), 409, 'Ghế đã gán vượt tồn mới.');
                }
            }
            DB::table('inventory')->where('flight_id', $d['flight_id'])->update(['price' => $d['price'], 'capacity' => $d['capacity'], 'status' => $d['status'], 'manual_closed' => $d['status'] === 'CLOSED']);
            Audit::record('fare.changed', (string) $d['flight_id'], (string) $stock->price, $d['reason'], $u->id);

            return (object) ['flight_id' => $d['flight_id']];
        });
    }

    private function fareClass(User $u, array $d): object
    {
        Access::role($u, 'data_admin');
        $d = Validator::make($d, ['flight_id' => 'required|integer', 'code' => 'required|regex:/^[A-Z0-9_]{1,20}$/', 'name' => 'required|string|max:100', 'quota' => 'required|integer|between:0,600', 'price' => 'required|integer|between:1000,100000000', 'active' => 'required|boolean'])->validate();

        return DB::transaction(function () use ($u, $d) {
            $stock = app(CommerceRepository::class)->lockInventory($d['flight_id']);
            abort_if($d['quota'] > $stock->capacity, 409, 'Hạn mức hạng vượt tổng tồn chuyến.');
            $used = DB::table('bookings')->where('flight_id', $d['flight_id'])->where('fare_class', $d['code'])->where(function ($q) {
                $q->whereIn('status', ['PAYMENT_PENDING', 'CONFIRMED', 'CHECKED_IN', 'BOARDED', 'REFUND_PENDING', 'EXCHANGE_PENDING'])->orWhere(fn ($q) => $q->where('status', 'HELD')->where('expires_at', '>', now()));
            })->count();
            abort_if($used > $d['quota'], 409, 'Hạng đã giữ/bán vượt hạn mức mới.');
            DB::table('fare_classes')->updateOrInsert(['flight_id' => $d['flight_id'], 'code' => $d['code']], $d);
            Audit::record('fare_class.updated', (string) $d['flight_id'], null, $d['code'], $u->id);

            return (object) $d;
        });
    }

    public function importMaster(Request $r)
    {
        Access::role($r->user(), 'data_admin');
        $r->validate(['file' => 'required|file|mimes:csv,txt|max:1024']);
        $count = app(MasterFileService::class)->importAirports($r->user(), $r->file('file')->getRealPath());

        return $r->expectsJson() ? response()->json(['staged' => $count]) : back()->with('message', 'Đã nhập '.$count.' sân bay vào hàng chờ duyệt. Dữ liệu chưa có hiệu lực.');
    }

    public function exportMaster(Request $r, string $entity)
    {
        return app(MasterFileService::class)->export($r->user(), $entity);
    }

    public function conversation(Request $r, int $id)
    {
        $case = app(CustomerService::class)->conversation($r->user(), $id);

        return $r->expectsJson() ? response()->json($case) : view('support-conversation', compact('case'));
    }

    public function recovery(Request $r, string $id)
    {
        $change = app(FlightChangeService::class)->detail($r->user(), $id);

        return $r->expectsJson() ? response()->json($change) : view('flight-change', compact('change'));
    }

    public function trace(Request $r, int $id)
    {
        $bag = app(BaggageService::class)->trace($r->user(), $id);

        return $r->expectsJson() ? response()->json($bag) : view('baggage-trace', compact('bag'));
    }

    public function export(Request $r, int $id)
    {
        Access::role($r->user(), 'finance');
        $db = DB::connection('finance');
        $run = $db->table('reconciliation_runs')->find($id) ?? abort(404);
        abort_unless($run->status === 'CLOSED', 409, 'Chỉ xuất kỳ đã khóa.');
        $rows = $db->table('reconciliation_entries')->where('run_id', $id)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['booking_id', 'result', 'detail', 'resolution'], ',', '"', '');
            foreach ($rows as $x) {
                fputcsv($out, [$x->booking_id, $x->result, $x->detail, preg_match('/^[=+@\-\t\r]/', $x->resolution ?? '') ? "'".$x->resolution : $x->resolution], ',', '"', '');
            }fclose($out);
        }, 'reconciliation-'.$id.'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }
}
