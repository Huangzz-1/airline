<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Boarding\BoardingOperations;
use App\Modules\Booking\BaggagePurchase;
use App\Modules\Booking\ExchangeService;
use App\Modules\Booking\FareRules;
use App\Modules\Booking\ReservationEdits;
use App\Modules\Booking\ReservationService;
use App\Modules\Booking\TripDetails;
use App\Modules\Customer\SupportDesk;
use App\Modules\Finance\FinanceOperations;
use App\Modules\Finance\OutboxRelay;
use App\Modules\Finance\RevenueReport;
use App\Modules\Flights\FlightCatalog;
use App\Modules\Operations\PassengerRecovery;
use App\Modules\Shared\Access;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessController extends Controller
{
    public const AREAS = [
        'fare-rules' => ['Điều kiện vé & hành lý', ['data_admin']],
        'bank-statements' => ['Nhập sao kê & đối chiếu', ['finance']],
        'financial-adjustments' => ['Điều chỉnh tài chính', ['finance']],
        'revenue' => ['Báo cáo doanh thu', ['finance', 'analyst', 'admin']],
        'boarding-control' => ['Check-in & danh sách lên tàu', ['airport_agent', 'operations', 'ops_manager']],
        'support-desk' => ['Phân công & hạn hỗ trợ', ['customer_service']],
        'passenger-revisions' => ['Duyệt sửa thông tin khách', ['customer_service']],
        'passenger-recovery' => ['Hành khách bị gián đoạn', ['operations', 'ops_manager']],
    ];

    public function index(Request $r, string $section)
    {
        $meta = self::AREAS[$section] ?? abort(404);
        Access::role($r->user(), ...$meta[1]);
        $data = ['title' => $meta[0], 'section' => $section];
        if ($section === 'fare-rules') {
            $data['rows'] = DB::table('fare_classes')->orderBy('flight_id')->paginate(12)->withQueryString();
            $data['flights'] = DB::connection('ops')->table('flights')->whereIn('id', $data['rows']->pluck('flight_id'))->get()->keyBy('id');
        } elseif ($section === 'bank-statements') {
            if ($r->query('template') === '1') {
                return Csv::download('mau-giao-dich.csv', ['transaction_ref', 'booking_reference', 'amount', 'currency', 'booked_at'], []);
            }
            $r->validate(['batch' => 'nullable|integer|min:1']);
            $db = DB::connection('finance');
            $data['batches'] = $db->table('statement_batches')->orderByDesc('id')->limit(30)->get();
            $data['batch'] = $r->query('batch') ? $db->table('statement_batches')->find($r->integer('batch')) : $data['batches']->first();
            abort_if($r->query('batch') && ! $data['batch'], 404);
            $rows = $db->table('statement_rows')->where('batch_id', $data['batch']?->id ?? 0);
            $data['counts'] = (clone $rows)->select('result')->selectRaw('COUNT(*) as total')->groupBy('result')->pluck('total', 'result');
            if ($r->query('export') === 'csv') {
                return Csv::download('sao-ke-'.($data['batch']?->id ?? 0).'.csv', ['Nguồn', 'Mã giao dịch', 'Mã đặt chỗ', 'Số tiền', 'Tiền tệ', 'Ngày UTC', 'Kết quả'], $rows->orderBy('id')->get()->map(fn ($x) => [$x->source, $x->transaction_ref, $x->booking_reference, $x->amount, $x->currency, $x->booked_at, $x->result]));
            }
            $data['rows'] = $rows->orderByDesc('id')->paginate(20)->withQueryString();
            $data['intents'] = $db->table('checkout_intents')->whereIn('id', $data['rows']->pluck('intent_id')->filter())->get()->keyBy('id');
        } elseif ($section === 'financial-adjustments') {
            $db = DB::connection('finance');
            $data['rows'] = $db->table('financial_adjustments')->orderByDesc('created_at')->paginate(15)->withQueryString();
            $payments = $db->table('payments')->whereIn('status', ['CAPTURED', 'REFUNDED'])->orderByDesc('id')->limit(150)->get();
            $data['entries'] = $db->table('reconciliation_entries as e')->join('reconciliation_runs as r', 'r.id', '=', 'e.run_id')->where('r.status', 'OPEN')->where('e.result', 'MISMATCH')->select('e.*')->get();
            $ids = $payments->pluck('booking_id')->merge($data['rows']->pluck('booking_id'))->merge($data['entries']->pluck('booking_id'))->unique();
            $data['bookings'] = DB::table('bookings')->whereIn('id', $ids)->get()->keyBy('id');
        } elseif ($section === 'revenue') {
            $filters = $r->only('start', 'end', 'group', 'origin', 'destination') + ['start' => now('Asia/Ho_Chi_Minh')->subDays(29)->toDateString(), 'end' => now('Asia/Ho_Chi_Minh')->toDateString(), 'group' => 'day'];
            $data['report'] = app(RevenueReport::class)->build($r->user(), $filters);
            if ($r->query('export') === 'csv') {
                return Csv::download('doanh-thu-'.$filters['start'].'-'.$filters['end'].'.csv', ['Nhóm', 'Thu vé VND', 'Hoàn VND', 'Điều chỉnh VND', 'Thu ròng VND', 'Số bút toán'], collect($data['report']['rows'])->map(fn ($x) => array_values($x)));
            }
        } elseif ($section === 'boarding-control' || $section === 'passenger-recovery') {
            $r->validate(['flight' => 'nullable|integer|min:1']);
            $data['flights'] = DB::connection('ops')->table('flights')->where('origin', $r->user()->airport_code)->whereNotIn('status', ['DRAFT', 'PENDING'])->where('departure', '>', now()->subDays(2))->orderBy('departure')->limit(100)->get();
            $id = $r->integer('flight') ?: $data['flights']->first()?->id;
            $data['flight'] = $id ? app(FlightCatalog::class)->get($id) : null;
            if ($data['flight']) {
                Access::airport($r->user(), $data['flight']->origin);
                if ($section === 'passenger-recovery') {
                    $data += app(PassengerRecovery::class)->impact($r->user(), $id);
                } else {
                    $data['snapshot'] = app(BoardingOperations::class)->snapshot($id);
                    $data['manifests'] = DB::table('boarding_manifests')->where('flight_id', $id)->orderByDesc('version')->get(['id', 'flight_id', 'version', 'created_by', 'created_at']);
                    $data['window'] = BoardingOperations::window($data['flight']);
                }
            }
        } elseif ($section === 'support-desk') {
            $r->validate(['status' => 'nullable|in:OPEN,IN_PROGRESS,RESOLVED', 'assigned_to' => 'nullable|integer|min:1', 'overdue' => 'nullable|boolean', 'priority' => 'nullable|in:LOW,NORMAL,HIGH,URGENT']);
            $q = DB::table('support_cases');
            foreach (['status', 'assigned_to', 'priority'] as $field) {
                if ($r->filled($field)) {
                    $q->where($field, $r->query($field));
                }
            }
            if ($r->boolean('overdue')) {
                $q->where('status', '!=', 'RESOLVED')->where('due_at', '<', now());
            }
            $data['rows'] = $q->orderByRaw("CASE WHEN status != 'RESOLVED' AND due_at < ? THEN 0 ELSE 1 END", [now()])->orderBy('due_at')->paginate(15)->withQueryString();
            $data['agents'] = User::where('role', 'customer_service')->whereNull('disabled_at')->pluck('name', 'id');
            $data['stats'] = ['open' => DB::table('support_cases')->where('status', '!=', 'RESOLVED')->count(), 'overdue' => DB::table('support_cases')->where('status', '!=', 'RESOLVED')->where('due_at', '<', now())->count(), 'escalated' => DB::table('support_cases')->where('status', '!=', 'RESOLVED')->whereNotNull('escalated_at')->count()];
        } elseif ($section === 'passenger-revisions') {
            $r->validate(['status' => 'nullable|in:PENDING,APPROVED,REJECTED,APPLIED']);
            $q = DB::table('passenger_revisions')->select('id', 'reservation_id', 'passenger_id', 'requested_by', 'status', 'reason', 'decided_by', 'decision_note', 'created_at', 'updated_at');
            if ($r->filled('status')) {
                $q->where('status', $r->query('status'));
            } else {
                $q->where('status', 'PENDING');
            }
            $data['rows'] = $q->orderByDesc('created_at')->paginate(15)->withQueryString();
            $data['reservations'] = DB::table('reservations')->whereIn('id', $data['rows']->pluck('reservation_id'))->pluck('reference', 'id');
        }

        return $r->expectsJson() ? response()->json($data) : view('business.'.$section, $data);
    }

    public function edit(Request $r, string $id)
    {
        $reservation = app(ReservationService::class)->owned($r->user(), $id);
        $people = DB::table('reservation_passengers')->where('reservation_id', $id)->orderBy('position')->get();
        $person = $r->query('passenger') ? $people->firstWhere('id', $r->query('passenger')) : $people->first();
        abort_unless($person, 404);
        $details = TripDetails::decode($person->details);
        $words = preg_split('/\s+/', trim($person->name), 2);
        $details += ['surname' => $words[0] ?? '', 'given_name' => $words[1] ?? '', 'nationality' => 'VN', 'gender' => 'X', 'date_of_birth' => $person->date_of_birth, 'document_number' => '', 'document_expiry' => ''];
        $contact = TripDetails::decode($reservation->contact_details) + ['name' => $r->user()->name, 'email' => $r->user()->email, 'phone' => $r->user()->phone ?? ''];
        $history = DB::table('passenger_revisions')->where('reservation_id', $id)->orderByDesc('created_at')->limit(30)->get(['id', 'status', 'reason', 'decision_note', 'created_at']);
        $data = compact('reservation', 'people', 'person', 'details', 'contact', 'history') + ['countries' => TripDetails::COUNTRIES, 'version' => ReservationEdits::fingerprint($reservation, $person)];

        return $r->expectsJson() ? response()->json($data) : view('business.reservation-edit', $data);
    }

    public function saveEdit(Request $r, string $id)
    {
        $change = app(ReservationEdits::class)->submit($r->user(), $id, $r->all());

        return $r->expectsJson() ? response()->json(['id' => $change->id, 'status' => $change->status], 201) : redirect('/reservations/'.$id.'/details?passenger='.$change->passenger_id)->with('message', $change->status === 'APPLIED' ? 'Đã cập nhật thông tin; hạn giữ chỗ giữ nguyên.' : 'Đã gửi yêu cầu sửa thông tin để nhân viên duyệt.');
    }

    public function baggage(Request $r, string $id)
    {
        $d = $r->validate(['package' => 'required|regex:/^\d{1,2}:\d{1,12}$/']);
        [$kg,$amount] = array_map('intval', explode(':', $d['package']));
        $b = app(BaggagePurchase::class)->update($r->user(), ['booking_id' => $id, 'kg' => $kg, 'expected_amount' => $amount]);

        return $r->expectsJson() ? response()->json($b) : redirect($b->reservation_id ? '/reservations/'.$b->reservation_id : '/journeys/'.$id)->with('message', 'Đã cập nhật hành lý và tổng tiền trước thanh toán.');
    }

    public function revision(Request $r, string $id)
    {
        Access::role($r->user(), 'customer_service');
        $change = DB::table('passenger_revisions')->find($id) ?? abort(404);
        $before = TripDetails::decode($change->before_data);
        $after = TripDetails::decode($change->after_data);
        unset($change->before_data,$change->after_data);

        return $r->expectsJson() ? response()->json(compact('change', 'before', 'after')) : view('business.passenger-revision', compact('change', 'before', 'after'));
    }

    public function manifest(Request $r, string $id)
    {
        $manifest = app(BoardingOperations::class)->read($r->user(), $id);
        if ($r->query('export') === 'csv') {
            return Csv::download('danh-sach-len-tau-v'.$manifest->version.'.csv', ['Phiên bản', 'Còn khớp dữ liệu', 'Mã vé', 'Họ tên', 'Ghế', 'Hạng', 'Trạng thái'], collect($manifest->data['rows'])->map(fn ($x) => [$manifest->version, $manifest->current ? 'Có' : 'Không', $x['reference'], $x['name'], $x['seat'], $x['fare_class'], $x['status']]));
        }

        return $r->expectsJson() ? response()->json($manifest) : view('business.manifest', compact('manifest'));
    }

    public function recoveryOffer(Request $r, string $id)
    {
        Access::role($r->user(), 'passenger');
        $existing = DB::table('exchange_requests as e')->join('bookings as b', 'b.id', '=', 'e.source_booking_id')->where('e.id', $id)->where('b.user_id', $r->user()->id)->select('e.target_booking_id')->first();
        if ($existing) {
            return redirect('/journeys/'.$existing->target_booking_id);
        }
        $data = app(PassengerRecovery::class)->customer($r->user(), $id);

        return $r->expectsJson() ? response()->json($data) : view('business.recovery-offer', $data);
    }

    public function recoveryExchange(Request $r, string $id)
    {
        Access::role($r->user(), 'passenger');
        $existing = DB::table('exchange_requests as e')->join('bookings as b', 'b.id', '=', 'e.source_booking_id')->where('e.id', $id)->where('b.user_id', $r->user()->id)->select('e.*')->first();
        if ($existing) {
            return $r->expectsJson() ? response()->json($existing) : redirect('/journeys/'.$existing->target_booking_id);
        }
        $data = app(PassengerRecovery::class)->customer($r->user(), $id);
        abort_unless($data['fare']->code === $data['booking']->fare_class, 409, 'Chọn luồng đặt vé mới khi đổi sang hạng khác.');
        $exchange = app(ExchangeService::class)->request($r->user(), ['request_id' => $id, 'booking_id' => $data['booking']->id, 'flight_id' => $data['target']->id, 'reason' => 'Đổi theo phương án đã duyệt '.substr($id, 0, 8)]);

        return $r->expectsJson() ? response()->json($exchange, 201) : redirect('/journeys/'.$exchange->target_booking_id)->with('message', 'Đã gửi yêu cầu đổi theo phương án. Tài chính sẽ duyệt trước khi bạn thanh toán vé mới.');
    }

    public function action(Request $r, string $action)
    {
        $d = $r->all();
        if (in_array($action, ['revision_decide', 'adjustment_decide', 'recovery_decide'])) {
            $r->validate(['id' => 'required|uuid', 'approve' => 'required|boolean']);
        }
        if (in_array($action, ['statement_match', 'statement_confirm', 'support_assign', 'support_escalate', 'checkin_control', 'manifest_finalize'])) {
            $r->validate(['id' => 'required|integer|min:1']);
        }
        if ($action === 'statement_confirm') {
            $r->validate(['consent' => 'accepted']);
        }
        if ($action === 'statement_import') {
            Access::role($r->user(), 'finance');
            $r->validate(['file' => 'required|file|mimes:csv,txt|max:1024', 'source' => 'required|string']);
            $result = app(FinanceOperations::class)->import($r->user(), $r->input('source'), $r->file('file')->getRealPath(), $r->file('file')->getClientOriginalName());

            return $r->expectsJson() ? response()->json($result, 201) : redirect('/workspace/bank-statements?batch='.$result->id)->with('message', 'Đã nhập và phân loại giao dịch. Chỉ các khoản khớp mới được xác nhận thu.');
        }
        if ($action === 'recovery_propose' && $r->filled('choice')) {
            $r->validate(['choice' => 'required|regex:/^\d+:[A-Z0-9_]{1,20}$/']);
            [$d['target_flight_id'],$d['fare_class']] = explode(':', $r->input('choice'));
        }
        $result = match ($action) {
            'fare_rules' => app(FareRules::class)->update($r->user(), $d),
            'revision_decide' => app(ReservationEdits::class)->decide($r->user(), $d['id'], $r->boolean('approve'), $d['note'] ?? ''),
            'statement_match' => app(FinanceOperations::class)->rematch($r->user(), (int) $d['id']),
            'statement_confirm' => app(FinanceOperations::class)->confirm($r->user(), (int) $d['id']),
            'adjustment_request' => app(FinanceOperations::class)->adjustment($r->user(), $d),
            'adjustment_decide' => app(FinanceOperations::class)->decideAdjustment($r->user(), $d['id'], $r->boolean('approve'), $d['note'] ?? ''),
            'checkin_control' => app(BoardingOperations::class)->control($r->user(), (int) $d['id'], $d),
            'manifest_finalize' => app(BoardingOperations::class)->finalize($r->user(), (int) $d['id']),
            'support_assign' => app(SupportDesk::class)->assign($r->user(), (int) $d['id'], $d),
            'support_escalate' => $this->escalate($r, (int) $d['id']),
            'recovery_propose' => app(PassengerRecovery::class)->propose($r->user(), $d),
            'recovery_decide' => app(PassengerRecovery::class)->decide($r->user(), $d['id'], $r->boolean('approve')),
            default => abort(404),
        };
        if ($action === 'statement_confirm') {
            app(OutboxRelay::class)->drain();
            app(OutboxRelay::class)->drain();
        }
        if ($r->expectsJson()) {
            return response()->json($result);
        }
        if ($action === 'manifest_finalize') {
            return redirect('/manifests/'.$result->id)->with('message', 'Đã chốt danh sách lên tàu theo phiên bản.');
        }

        return back()->with('message', 'Đã ghi nhận thao tác.');
    }

    private function escalate(Request $r, int $id): int
    {
        Access::role($r->user(), 'customer_service');
        abort_unless(DB::table('support_cases')->where('id', $id)->exists(), 404);

        return app(SupportDesk::class)->escalateOverdue($id);
    }
}
