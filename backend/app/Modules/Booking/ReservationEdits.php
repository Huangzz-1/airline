<?php

namespace App\Modules\Booking;

use App\Models\User;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use App\Modules\Shared\Events;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ReservationEdits
{
    public static function fingerprint(object $r, object $p): string
    {
        return hash('sha256', json_encode([$p->id, $p->name, $p->date_of_birth, $p->details, $r->contact_details], JSON_THROW_ON_ERROR));
    }

    private function input(array $data): array
    {
        $d = Validator::make($data, [
            'request_id' => 'required|uuid', 'passenger_id' => 'required|uuid', 'version' => 'required|string|size:64', 'reason' => 'required|string|between:5,255',
            'passenger' => 'required|array:surname,given_name,nationality,gender,date_of_birth,document_number,document_expiry',
            'passenger.surname' => 'required|string|between:1,60|regex:/^[\p{L}\p{M} \x{0027}-]+$/u', 'passenger.given_name' => 'required|string|between:1,100|regex:/^[\p{L}\p{M} \x{0027}-]+$/u',
            'passenger.nationality' => 'required|in:'.implode(',', array_keys(TripDetails::COUNTRIES)), 'passenger.gender' => 'required|in:M,F,X',
            'passenger.date_of_birth' => 'nullable|date_format:Y-m-d|before_or_equal:today', 'passenger.document_number' => 'nullable|string|regex:/^[A-Za-z0-9]{5,20}$/', 'passenger.document_expiry' => 'nullable|date_format:Y-m-d|after_or_equal:today',
            'contact' => 'required|array:name,email,phone', 'contact.name' => 'required|string|between:2,100', 'contact.email' => 'required|email|max:190', 'contact.phone' => 'required|string|regex:/^\+?[0-9 ]{9,16}$/',
        ])->validate();
        $p = $d['passenger'];
        foreach (['surname', 'given_name'] as $field) {
            $p[$field] = preg_replace('/\s+/u', ' ', trim($p[$field]));
        }
        abort_unless($p['surname'] !== '' && $p['given_name'] !== '', 422, 'Họ tên không được để trống.');
        $p += ['date_of_birth' => null, 'document_number' => null, 'document_expiry' => null];
        abort_if($p['document_number'] && ! $p['document_expiry'], 422, 'Nhập ngày hết hạn giấy tờ.');
        $d['passenger'] = $p;

        return $d;
    }

    private function locked(string $id, callable $work): object
    {
        return DB::transaction(function () use ($id, $work) {
            $q = DB::table('bookings')->where('reservation_id', $id)->whereNotIn('status', ['REFUNDED', 'CANCELLED', 'EXPIRED', 'NO_SHOW']);
            foreach ((clone $q)->distinct()->orderBy('flight_id')->pluck('flight_id') as $flight) {
                app(CommerceRepository::class)->lockInventory($flight);
            }
            $r = DB::table('reservations')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            $bookings = $q->orderBy('id')->lockForUpdate()->get();

            return $work($r, $bookings);
        }, 3);
    }

    private function state(object $r, $bookings, array $person): string
    {
        abort_if($bookings->isEmpty(), 409, 'Mã đặt chỗ không còn vé được phép sửa.');
        $flights = DB::connection('ops')->table('flights')->whereIn('id', $bookings->pluck('flight_id'))->get();
        foreach ($flights as $flight) {
            abort_unless(in_array($flight->status, ['SCHEDULED', 'DELAYED', 'BOARDING']) && now()->lt(Carbon::parse($flight->departure)->subMinutes(30)), 409, 'Chuyến không còn cho phép sửa thông tin.');
            if ($person['document_number']) {
                abort_unless(Carbon::parse($person['document_expiry'], 'Asia/Ho_Chi_Minh')->endOfDay()->gte(Carbon::parse($flight->arrival)), 422, 'Giấy tờ phải còn hạn đến hết chuyến đi.');
            }
        }
        if ($bookings->every(fn ($b) => $b->status === 'HELD')) {
            abort_unless(now()->lt($r->expires_at) && $bookings->every(fn ($b) => now()->lt($b->expires_at)), 409, 'Giữ chỗ đã hết hạn.');
            abort_if(DB::table('checkout_sessions')->whereIn('booking_id', $bookings->pluck('id'))->exists(), 409, 'Yêu cầu thanh toán đã được chốt.');

            return 'APPLIED';
        }
        abort_unless($bookings->every(fn ($b) => in_array($b->status, ['CONFIRMED', 'CHECKED_IN'])), 409, 'Vé đang thanh toán, đổi/hoàn hoặc đã lên tàu; chưa thể sửa.');

        return 'PENDING';
    }

    public function submit(User $u, string $id, array $data): object
    {
        Access::role($u, 'passenger');
        app(ReservationService::class)->owned($u, $id);
        $d = $this->input($data);

        return $this->locked($id, function ($r, $bookings) use ($u, $d) {
            abort_unless((int) $r->user_id === (int) $u->id, 404);
            $p = DB::table('reservation_passengers')->where('reservation_id', $r->id)->where('id', $d['passenger_id'])->lockForUpdate()->first() ?? abort(404);
            $after = ['passenger' => $d['passenger'], 'contact' => $d['contact']];
            $old = DB::table('passenger_revisions')->find($d['request_id']);
            if ($old) {
                abort_unless($old->reservation_id === $r->id && $old->passenger_id === $p->id && $old->requested_by === $u->id && TripDetails::decode($old->after_data) == $after && $old->reason === $d['reason'], 409, 'Mã yêu cầu đã dùng cho nội dung khác.');

                return $old;
            }
            abort_unless(hash_equals(self::fingerprint($r, $p), $d['version']), 409, 'Thông tin đã thay đổi. Tải lại trước khi sửa.');
            abort_if(DB::table('passenger_revisions')->where('reservation_id', $r->id)->where('status', 'PENDING')->exists(), 409, 'Mã đặt chỗ đang có yêu cầu sửa chờ duyệt.');
            $status = $this->state($r, $bookings, $d['passenger']);
            DB::table('passenger_revisions')->insert(['id' => $d['request_id'], 'reservation_id' => $r->id, 'passenger_id' => $p->id, 'requested_by' => $u->id, 'fingerprint' => $d['version'], 'before_data' => TripDetails::encode(['name' => $p->name, 'date_of_birth' => $p->date_of_birth, 'passenger' => TripDetails::decode($p->details), 'contact' => TripDetails::decode($r->contact_details)]), 'after_data' => TripDetails::encode($after), 'status' => $status, 'reason' => $d['reason'], 'created_at' => now(), 'updated_at' => now()]);
            if ($status === 'APPLIED') {
                $this->apply($r, $p, $after, $bookings);
            }
            Audit::record('passenger.revision_submitted', $d['request_id'], null, $status, $u->id);

            return DB::table('passenger_revisions')->find($d['request_id']);
        });
    }

    public function decide(User $u, string $id, bool $approve, string $note): object
    {
        Access::role($u, 'customer_service');
        Validator::make(['note' => $note], ['note' => 'required|string|between:5,1000'])->validate();
        $base = DB::table('passenger_revisions')->find($id) ?? abort(404);

        return $this->locked($base->reservation_id, function ($r, $bookings) use ($u, $id, $approve, $note) {
            $change = DB::table('passenger_revisions')->where('id', $id)->lockForUpdate()->first();
            abort_if((int) $change->requested_by === (int) $u->id, 409, 'Cần nhân viên duyệt độc lập.');
            $status = $approve ? 'APPROVED' : 'REJECTED';
            if ($change->status !== 'PENDING') {
                abort_unless($change->status === $status, 409, 'Yêu cầu đã được xử lý.');

                return $change;
            }
            if ($approve) {
                $p = DB::table('reservation_passengers')->where('id', $change->passenger_id)->lockForUpdate()->first();
                abort_unless(hash_equals($change->fingerprint, self::fingerprint($r, $p)), 409, 'Hồ sơ đã thay đổi; cần lập yêu cầu mới.');
                $after = TripDetails::decode($change->after_data);
                abort_unless($this->state($r, $bookings, $after['passenger']) === 'PENDING', 409, 'Trạng thái vé đã thay đổi.');
                $this->apply($r, $p, $after, $bookings);
            }
            DB::table('passenger_revisions')->where('id', $id)->update(['status' => $status, 'decided_by' => $u->id, 'decision_note' => $note, 'updated_at' => now()]);
            Events::notify($r->user_id, 'Kết quả sửa thông tin hành khách', $approve ? 'Thông tin đã được cập nhật. Thẻ lên tàu cũ của người được sửa không còn hiệu lực nếu thông tin định danh thay đổi; vui lòng làm thủ tục lại.' : 'Yêu cầu chưa được chấp thuận: '.$note);
            Audit::record('passenger.revision_decided', $id, 'PENDING', $status, $u->id);

            return DB::table('passenger_revisions')->find($id);
        });
    }

    private function apply(object $r, object $p, array $after, $bookings): void
    {
        $person = $after['passenger'];
        $name = trim($person['surname'].' '.$person['given_name']);
        $details = array_diff_key($person, ['date_of_birth' => true]);
        $changed = $p->name !== $name || $p->date_of_birth !== $person['date_of_birth'] || TripDetails::decode($p->details) != $details;
        DB::table('reservation_passengers')->where('id', $p->id)->update(['name' => $name, 'date_of_birth' => $person['date_of_birth'], 'details' => TripDetails::encode($details)]);
        DB::table('reservations')->where('id', $r->id)->update(['contact_details' => TripDetails::encode($after['contact']), 'updated_at' => now()]);
        if ($changed) {
            $ids = $bookings->where('passenger_id', $p->id)->pluck('id');
            DB::table('boarding_passes')->whereIn('booking_id', $ids)->update(['revoked_at' => now()]);
            DB::table('bookings')->whereIn('id', $ids)->where('status', 'CHECKED_IN')->update(['status' => 'CONFIRMED', 'seat' => null, 'updated_at' => now()]);
        }
    }
}
