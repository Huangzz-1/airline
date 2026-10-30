<?php

namespace App\Modules\Baggage;

use App\Models\User;
use App\Modules\Booking\CommerceRepository;
use App\Modules\Flights\FlightCatalog;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BaggageService
{
    public function tag(User $u, array $data): object
    {
        Access::role($u, 'airport_agent');
        $d = Validator::make($data, ['booking_id' => 'required|uuid', 'weight' => 'required|numeric|decimal:0,2|between:0.1,32', 'request_id' => 'required|string|between:8,80'])->validate();
        $b = DB::table('bookings')->find($d['booking_id']) ?? abort(404);
        $f = app(FlightCatalog::class)->get($b->flight_id);
        Access::airport($u, $f->origin);
        abort_unless(in_array($f->status, ['SCHEDULED', 'DELAYED', 'BOARDING']), 409, 'Chuyến không tiếp nhận hành lý.');

        return DB::transaction(function () use ($u, $d, $b, $f) {
            $repo = app(CommerceRepository::class);
            $repo->lockInventory($b->flight_id);
            $b = $repo->locked($b->id);
            abort_unless(in_array($b->status, ['CHECKED_IN', 'BOARDED']), 409, 'Cần hành khách đã check-in.');
            $old = DB::table('baggage')->where('request_id', $d['request_id'])->first();
            if ($old) {
                abort_unless($old->booking_id === $b->id && (float) $old->weight === (float) $d['weight'], 409, 'Khóa hành lý đã dùng.');

                return $old;
            }
            $id = DB::table('baggage')->insertGetId(['tag' => 'BG'.strtoupper(Str::random(12)), 'booking_id' => $b->id, 'request_id' => $d['request_id'], 'weight' => $d['weight'], 'custody' => $f->origin.'-CHECKIN', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('baggage_events')->insert(['id' => (string) Str::uuid(), 'baggage_id' => $id, 'status' => 'TAGGED', 'location' => $f->origin, 'custody' => $f->origin.'-CHECKIN', 'actor_id' => $u->id, 'created_at' => now()]);
            Audit::record('baggage.tagged', (string) $id, null, 'TAGGED', $u->id);

            return DB::table('baggage')->find($id);
        }, 3);
    }

    public function scan(User $u, array $data): object
    {
        Access::role($u, 'airport_agent');
        $d = Validator::make($data, ['event_id' => 'required|uuid', 'tag' => 'required|string|max:30', 'flight_id' => 'required|integer', 'status' => 'required|in:ACCEPTED,SCREENED,LOADED,TRANSFERRED,DELIVERED,MISHANDLED,OFFLOADED', 'location' => 'required|string|max:80', 'custody' => 'required|string|max:100'])->validate();
        $f = app(FlightCatalog::class)->get($d['flight_id']);
        Access::airport($u, in_array($d['status'], ['DELIVERED', 'TRANSFERRED']) ? $f->destination : $f->origin);

        return DB::transaction(function () use ($u, $d, $f) {
            $baggage = DB::table('baggage')->where('tag', $d['tag'])->lockForUpdate()->first() ?? abort(404);
            $b = DB::table('bookings')->find($baggage->booking_id);
            abort_unless($b->flight_id === $f->id, 409, 'Hành lý sai chuyến.');
            $old = DB::table('baggage_events')->find($d['event_id']);
            if ($old) {
                abort_unless($old->baggage_id === $baggage->id && $old->status === $d['status'] && $old->location === $d['location'] && $old->custody === $d['custody'], 409, 'Sự kiện quét đã dùng cho dữ liệu khác.');

                return $baggage;
            }
            $states = ['TAGGED' => ['ACCEPTED', 'MISHANDLED', 'OFFLOADED'], 'ACCEPTED' => ['SCREENED', 'MISHANDLED', 'OFFLOADED'], 'SCREENED' => ['LOADED', 'MISHANDLED', 'OFFLOADED'], 'LOADED' => ['TRANSFERRED', 'DELIVERED', 'MISHANDLED', 'OFFLOADED'], 'TRANSFERRED' => ['DELIVERED', 'MISHANDLED'], 'MISHANDLED' => ['ACCEPTED', 'DELIVERED', 'OFFLOADED']];
            abort_unless(in_array($d['status'], $states[$baggage->status] ?? []), 409, 'Bỏ qua mốc bắt buộc hoặc trạng thái hành lý không hợp lệ.');
            if ($d['status'] === 'LOADED') {
                abort_unless(in_array($b->status, ['CHECKED_IN', 'BOARDED']) && in_array($f->status, ['BOARDING', 'GATE_CLOSED']), 409, 'Hành lý/chuyến chưa đủ điều kiện xếp tàu.');
            }
            if (in_array($d['status'], ['TRANSFERRED', 'DELIVERED'])) {
                abort_unless($f->status === 'ARRIVED', 409, 'Chuyến chưa đến.');
            }
            DB::table('baggage_events')->insert(['id' => $d['event_id'], 'baggage_id' => $baggage->id, 'status' => $d['status'], 'location' => $d['location'], 'custody' => $d['custody'], 'actor_id' => $u->id, 'created_at' => now()]);
            DB::table('baggage')->where('id', $baggage->id)->update(['status' => $d['status'], 'custody' => $d['custody'], 'updated_at' => now()]);
            Audit::record('baggage.scanned', (string) $baggage->id, $baggage->status, $d['status'], $u->id);

            return DB::table('baggage')->find($baggage->id);
        }, 3);
    }

    public function trace(User $u, int $id): object
    {
        $bag = DB::table('baggage')->find($id) ?? abort(404);
        $b = DB::table('bookings')->find($bag->booking_id);
        if ($u->role === 'passenger') {
            abort_unless($b->user_id === $u->id, 404);
        } else {
            Access::role($u, 'airport_agent');
            $f = app(FlightCatalog::class)->get($b->flight_id);
            abort_unless(in_array($u->airport_code, [$f->origin, $f->destination]), 403);
        }
        $bag->events = DB::table('baggage_events')->where('baggage_id', $id)->orderBy('created_at')->get(['status', 'location', 'custody', 'created_at']);
        Audit::record('baggage.viewed', (string) $id, null, 'READ', $u->id);

        return $bag;
    }
}
